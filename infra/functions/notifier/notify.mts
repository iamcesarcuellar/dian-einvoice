// What the notifier does, apart from Lambda, Secrets Manager and SQS, so it is tested with real notifications of
// alarms and fakes of the rest: it reads the notification CloudWatch publishes to the topic of the alarms when an
// alarm changes state (a metric alarm, one with metric math, or a composite alarm), turns it into a short message a
// chat webhook compatible with Slack takes ({"text": ...}): which alarm, its new state, why, what it means and the
// runbook its description names; and posts it, a few times when the chat does not take it. A notification it cannot
// deliver goes to its dead-letter queue, whose alarm emails the operators; one it is not given an address for is only
// logged, and so is an alarm that settles to OK from INSUFFICIENT_DATA (a new alarm, or a metric without data), which
// no operator acts on. It never logs the address of the webhook, which is a credential.
import type { LogFields, Level } from '../shared/log.mts'

/** The notification of a change of state of an alarm, as much of it as the notifier reads. */
export interface AlarmNotification {
  readonly AlarmName: string
  readonly AlarmDescription?: string | null
  readonly NewStateValue: string
  readonly OldStateValue?: string
  readonly NewStateReason?: string
  readonly StateChangeTime?: string
  readonly Region?: string
}

/** A record of the event SNS invokes the notifier with. */
export interface SnsRecord {
  readonly Sns: { readonly MessageId?: string, readonly Message: string, readonly Subject?: string | null }
}

export interface SnsEvent {
  readonly Records?: readonly SnsRecord[]
}

/** The message of a chat webhook compatible with Slack. */
export interface ChatMessage {
  readonly text: string
}

/** How a post to the webhook went: the status of the answer, or the error that kept it from one. */
export type PostResult = { readonly status: number } | { readonly error: unknown }

export interface NotifierPorts {
  /** The address of the webhook, '' when none is configured. */
  readonly webhookUrl: () => Promise<string>
  readonly post: (url: string, message: ChatMessage) => Promise<PostResult>
  /** Keeps a notification that could not be delivered; throws when it cannot. */
  readonly deadLetter: (record: SnsRecord, reason: string) => Promise<void>
  readonly log: (level: Level, message: string, fields?: LogFields) => void
  readonly correlationId: () => string
  readonly sleep?: (milliseconds: number) => Promise<void>
  readonly now?: () => number
}

/** Attempts of one post, and the waits between them, within the timeout of the function. */
export const ATTEMPTS = 3
export const WAITS_MILLISECONDS = [250, 1000] as const

/** The marker of each state, as the chats show it. */
const MARKERS: Readonly<Record<string, string>> = { ALARM: ':red_circle:', OK: ':large_green_circle:', INSUFFICIENT_DATA: ':white_circle:' }

const REASON_LIMIT = 400

/** The notification of an alarm a message of the topic carries, or null when it is not one. */
export function parseNotification(message: string): AlarmNotification | null {
  let parsed: unknown

  try {
    parsed = JSON.parse(message)
  } catch {
    return null
  }

  if (typeof parsed !== 'object' || parsed === null) {
    return null
  }

  const candidate = parsed as Partial<AlarmNotification>

  return typeof candidate.AlarmName === 'string' && candidate.AlarmName !== '' && typeof candidate.NewStateValue === 'string' && candidate.NewStateValue !== ''
    ? candidate as AlarmNotification
    : null
}

/** The runbook a description names (Runbook: docs/runbooks/<name>.md), or null. */
export function runbookOf(description: string | null | undefined): string | null {
  return /Runbook:\s*(\S+)/.exec(description ?? '')?.[1]?.replace(/[.,;]$/, '') ?? null
}

/** What the alarm means: its description without the runbook. */
export function meaningOf(description: string | null | undefined): string {
  return (description ?? '').replace(/\s*Runbook:\s*\S+\s*$/, '').trim()
}

/** The short, readable message of a notification: which alarm, its state, why, what it means and its runbook. */
export function formatMessage(notification: AlarmNotification): ChatMessage {
  const state = notification.NewStateValue
  const reason = (notification.NewStateReason ?? '').replace(/\s+/g, ' ').trim()
  const meaning = meaningOf(notification.AlarmDescription)
  const runbook = runbookOf(notification.AlarmDescription)
  const lines = [
    `${MARKERS[state] ?? ':grey_question:'} *${state}* ${notification.AlarmName}${notification.OldStateValue === undefined ? '' : ` (was ${notification.OldStateValue})`}`,
    ...(meaning === '' ? [] : [meaning]),
    ...(reason === '' ? [] : [`Reason: ${reason.length > REASON_LIMIT ? `${reason.slice(0, REASON_LIMIT - 1)}…` : reason}`]),
    `Runbook: ${runbook ?? 'none named in the description of the alarm'}`,
    ...(notification.StateChangeTime === undefined ? [] : [`At ${notification.StateChangeTime}${notification.Region === undefined ? '' : ` in ${notification.Region}`}`]),
  ]

  return { text: lines.join('\n') }
}

/** Turns the alarms of one invocation into messages, posts them, and keeps the ones it could not deliver. */
export async function notify(event: SnsEvent, requestId: string | null, ports: NotifierPorts): Promise<void> {
  const sleep = ports.sleep ?? ((milliseconds: number) => new Promise<void>((resolve) => { setTimeout(resolve, milliseconds) }))
  const now = ports.now ?? Date.now

  for (const record of event.Records ?? []) {
    // Each notification is a journey of its own: the run of the notifier for one change of state.
    const correlationId = ports.correlationId()
    const started = now()
    const notification = parseNotification(record.Sns.Message)

    if (notification === null) {
      ports.log('warning', 'A message of the topic of the alarms is not the notification of an alarm: skipped.', { event: 'alarm.notification', outcome: 'skipped', correlationId, requestId, detail: { reason: 'not an alarm' } })
      continue
    }

    const detail = { alarm: notification.AlarmName, state: notification.NewStateValue, previous_state: notification.OldStateValue, runbook: runbookOf(notification.AlarmDescription) }

    // A new alarm, or one whose metric had no data, settling to OK says nothing an operator acts on.
    if (notification.NewStateValue === 'OK' && notification.OldStateValue === 'INSUFFICIENT_DATA') {
      ports.log('info', `${notification.AlarmName} settled to OK from INSUFFICIENT_DATA: nothing to tell the chat.`, { event: 'alarm.notification', outcome: 'skipped', correlationId, requestId, detail: { ...detail, reason: 'settled to OK without an alarm' } })
      continue
    }

    let url: string

    try {
      url = await ports.webhookUrl()
    } catch (error) {
      await ports.deadLetter(record, 'the address of the chat webhook could not be read')
      ports.log('error', `${notification.AlarmName} is ${notification.NewStateValue}, but the address of the chat webhook could not be read: kept in the dead-letter queue of the notifier.`, { event: 'alarm.notification', outcome: 'failure', correlationId, requestId, detail: { ...detail, reason: 'the secret of the chat webhook could not be read' }, error })
      continue
    }

    if (url === '') {
      ports.log('warning', `${notification.AlarmName} is ${notification.NewStateValue}; no chat webhook is configured, so it is only logged.`, { event: 'alarm.notification', outcome: 'skipped', correlationId, requestId, detail: { ...detail, reason: 'no chat webhook configured' } })
      continue
    }

    const message = formatMessage(notification)
    let failure = ''

    for (let attempt = 1; attempt <= ATTEMPTS; attempt++) {
      const result = await ports.post(url, message)

      if ('status' in result && result.status >= 200 && result.status < 300) {
        failure = ''
        break
      }

      failure = 'status' in result ? `the chat answered HTTP ${result.status}` : `the chat could not be reached (${result.error instanceof Error ? result.error.name : 'error'})`

      if (attempt < ATTEMPTS) {
        await sleep(WAITS_MILLISECONDS[attempt - 1] ?? 1000)
      }
    }

    if (failure === '') {
      ports.log('info', `${notification.AlarmName} is ${notification.NewStateValue}: sent to the chat.`, { event: 'alarm.notification', outcome: 'success', durationMs: now() - started, correlationId, requestId, detail })
      continue
    }

    // Kept for the operators, whose alarm on this queue emails them; when even that fails, the invocation fails and
    // Lambda retries it, then sends it to the same queue (the destination of its failures).
    await ports.deadLetter(record, failure)
    ports.log('error', `${notification.AlarmName} is ${notification.NewStateValue}, but ${failure} after ${ATTEMPTS} attempts: kept in the dead-letter queue of the notifier.`, { event: 'alarm.notification', outcome: 'failure', durationMs: now() - started, correlationId, requestId, detail: { ...detail, reason: failure } })
  }
}
