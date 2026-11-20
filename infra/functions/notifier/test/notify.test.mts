// The notifier (notify.mts) with notifications of alarms as CloudWatch publishes them to the topic (test/alarms: a
// metric alarm, its way back to OK, insufficient data, metric math, a composite alarm and a state forced by
// SetAlarmState), inside the event SNS invokes Lambda with; the chat, Secrets Manager and SQS are fakes.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, it } from 'node:test'
import type { Level, LogFields } from '../../shared/log.mts'
import { urlOfSecret } from '../handler.mts'
import { ATTEMPTS, formatMessage, notify, parseNotification, runbookOf, WAITS_MILLISECONDS, type ChatMessage, type PostResult, type SnsEvent, type SnsRecord } from '../notify.mts'

const WEBHOOK = 'https://hooks.chat.example/services/T0000000/B0000000/aSecretPartOfTheAddress000000'

function sample(name: string): string {
  return readFileSync(join(import.meta.dirname, 'alarms', `${name}.json`), 'utf8')
}

/** The event SNS invokes the notifier with, for notifications of the topic of the alarms. */
function event(...messages: string[]): SnsEvent {
  return {
    Records: messages.map((message, index) => ({
      EventSource: 'aws:sns',
      EventVersion: '1.0',
      EventSubscriptionArn: 'arn:aws:sns:us-east-1:111111111111:dian-einvoice-prod-alarms:5f1b2c3d-0000-4000-8000-000000000000',
      Sns: {
        Type: 'Notification',
        MessageId: `message-${index}`,
        TopicArn: 'arn:aws:sns:us-east-1:111111111111:dian-einvoice-prod-alarms',
        Subject: 'ALARM: "dian-einvoice-prod-chain-dead-letters" in US East (N. Virginia)',
        Message: message,
        Timestamp: '2026-11-20T21:00:13.000Z',
        SignatureVersion: '1',
        MessageAttributes: {},
      },
    })),
  } as SnsEvent
}

interface Fake {
  readonly posts: { url: string, message: ChatMessage }[]
  readonly deadLetters: { record: SnsRecord, reason: string }[]
  readonly logs: { level: Level, message: string, fields: LogFields }[]
  readonly sleeps: number[]
}

/** The ports of the notifier, answering what the test says: the chat answers in order, the last answer repeats. */
function fake(options: { url?: string, answers?: PostResult[], failDeadLetter?: boolean, failSecret?: boolean } = {}): Fake & { ports: Parameters<typeof notify>[2] } {
  const posts: Fake['posts'] = []
  const deadLetters: Fake['deadLetters'] = []
  const logs: Fake['logs'] = []
  const sleeps: number[] = []
  const answers = options.answers ?? [{ status: 200 }]

  return {
    posts,
    deadLetters,
    logs,
    sleeps,
    ports: {
      webhookUrl: async () => {
        if (options.failSecret === true) {
          throw new Error('Secrets Manager does not answer.')
        }

        return options.url ?? WEBHOOK
      },
      post: async (url, message) => {
        posts.push({ url, message })

        return answers[Math.min(posts.length, answers.length) - 1] ?? { status: 200 }
      },
      deadLetter: async (record, reason) => {
        if (options.failDeadLetter === true) {
          throw new Error('SQS does not answer.')
        }

        deadLetters.push({ record, reason })
      },
      log: (level, message, fields = {}) => { logs.push({ level, message, fields }) },
      correlationId: () => '01jc0000000000000000000000',
      sleep: async (milliseconds) => { sleeps.push(milliseconds) },
      now: () => 1_000,
    },
  }
}

describe('the message of an alarm', () => {
  it('says which alarm, its state and the one before, why, what it means and its runbook', () => {
    const text = formatMessage(parseNotification(sample('metric-alarm')) ?? assert.fail('a notification')).text

    assert.equal(text.split('\n')[0], ':red_circle: *ALARM* dian-einvoice-prod-chain-dead-letters (was OK)')
    assert.match(text, /^A message reached the dead-letter queue of the chain queue: a job failed every attempt/m)
    assert.match(text, /^Reason: Threshold Crossed: 1 out of the last 1 datapoints \[3\.0/m)
    assert.match(text, /^Runbook: docs\/runbooks\/dead-letters\.md$/m)
    assert.match(text, /^At 2026-11-20T21:00:12\.345\+0000 in US East \(N\. Virginia\)$/m)
    assert.doesNotMatch(text, /Runbook: docs\/runbooks\/dead-letters\.md\S/, 'The runbook is named once, on its own line.')
    assert.ok(text.length < 1000, 'A short message.')
  })

  it('reads the way back to OK, insufficient data, metric math, composite alarms and a state forced by hand', () => {
    const cases = [
      ['metric-alarm-ok', ':large_green_circle: *OK* dian-einvoice-prod-relay-not-running (was ALARM)', 'docs/runbooks/outbox-relay.md'],
      ['insufficient-data', ':white_circle: *INSUFFICIENT_DATA* dian-einvoice-prod-api-latency (was OK)', 'docs/runbooks/api-latency.md'],
      ['metric-math-alarm', ':red_circle: *ALARM* dian-einvoice-prod-dian-error-rate (was OK)', 'docs/runbooks/dian-errors.md'],
      ['composite-alarm', ':red_circle: *ALARM* dian-einvoice-prod-service-degraded (was OK)', 'docs/runbooks/service-degraded.md'],
      ['forced-state', ':red_circle: *ALARM* dian-einvoice-local-chain-dead-letters (was OK)', 'docs/runbooks/dead-letters.md'],
    ] as const

    for (const [name, first, runbook] of cases) {
      const notification = parseNotification(sample(name)) ?? assert.fail(name)
      const text = formatMessage(notification).text
      assert.equal(text.split('\n')[0], first, name)
      assert.ok(text.includes(`Runbook: ${runbook}`), name)
      assert.equal(runbookOf(notification.AlarmDescription), runbook, name)
    }

    assert.match(formatMessage(parseNotification(sample('forced-state')) ?? assert.fail('forced')).text, /^Reason: make cloud-check forced this state/m)
  })

  it('cuts a long reason, and names no runbook when the description has none', () => {
    const notification = { ...(parseNotification(sample('metric-alarm')) ?? assert.fail('a notification')), NewStateReason: 'x'.repeat(2000), AlarmDescription: 'Something without a runbook.' }
    const text = formatMessage(notification).text

    assert.ok((/^Reason: (x+…)$/m.exec(text)?.[1]?.length ?? 0) <= 400)
    assert.match(text, /^Runbook: none named in the description of the alarm$/m)
  })

  it('is no notification of an alarm when the message is not one', () => {
    for (const message of ['not json', '[]', '{}', '{"Type":"SubscriptionConfirmation","Message":"You have chosen to subscribe"}', '{"AlarmName":"x"}']) {
      assert.equal(parseNotification(message), null, message)
    }
  })
})

describe('the notifier', () => {
  it('posts the message of each alarm to the chat once, and logs it as sent', async () => {
    const chat = fake()
    await notify(event(sample('metric-alarm'), sample('metric-alarm-ok')), 'request-1', chat.ports)

    assert.equal(chat.posts.length, 2)
    assert.equal(chat.posts[0]?.url, WEBHOOK)
    assert.deepEqual(Object.keys(chat.posts[0]?.message ?? {}), ['text'], 'A message of a chat webhook compatible with Slack.')
    assert.deepEqual(chat.deadLetters, [])
    assert.deepEqual(chat.logs.map((entry) => [entry.level, entry.fields.event, entry.fields.outcome, entry.fields.detail?.alarm, entry.fields.detail?.state, entry.fields.detail?.runbook]), [
      ['info', 'alarm.notification', 'success', 'dian-einvoice-prod-chain-dead-letters', 'ALARM', 'docs/runbooks/dead-letters.md'],
      ['info', 'alarm.notification', 'success', 'dian-einvoice-prod-relay-not-running', 'OK', 'docs/runbooks/outbox-relay.md'],
    ])
    assert.ok(chat.logs.every((entry) => entry.fields.requestId === 'request-1' && entry.fields.correlationId === '01jc0000000000000000000000'))
  })

  it('tells the chat nothing about an alarm that settles to OK from insufficient data, as every new alarm does', async () => {
    const chat = fake()
    const settled = JSON.stringify({ ...JSON.parse(sample('metric-alarm-ok')) as object, OldStateValue: 'INSUFFICIENT_DATA' })
    await notify(event(settled), null, chat.ports)

    assert.deepEqual(chat.posts, [])
    assert.deepEqual([chat.logs[0]?.fields.outcome, chat.logs[0]?.fields.detail?.reason], ['skipped', 'settled to OK without an alarm'])
  })

  it('only logs an alarm when no chat webhook is configured', async () => {
    const chat = fake({ url: '' })
    await notify(event(sample('metric-alarm')), null, chat.ports)

    assert.deepEqual(chat.posts, [])
    assert.deepEqual(chat.deadLetters, [])
    assert.equal(chat.logs[0]?.fields.outcome, 'skipped')
    assert.match(chat.logs[0]?.message ?? '', /no chat webhook is configured/)
  })

  it('tries a few times, then keeps the notification in its dead-letter queue and logs the failure', async () => {
    const chat = fake({ answers: [{ status: 500 }] })
    await notify(event(sample('metric-alarm')), null, chat.ports)

    assert.equal(chat.posts.length, ATTEMPTS)
    assert.deepEqual(chat.sleeps, [...WAITS_MILLISECONDS])
    assert.equal(chat.deadLetters.length, 1)
    assert.equal(chat.deadLetters[0]?.reason, 'the chat answered HTTP 500')
    assert.equal(chat.deadLetters[0]?.record.Sns.Message, sample('metric-alarm'), 'The notification is kept as CloudWatch published it.')
    assert.deepEqual([chat.logs[0]?.level, chat.logs[0]?.fields.outcome], ['error', 'failure'])
  })

  it('goes on after a chat that could not be reached once', async () => {
    const chat = fake({ answers: [{ error: new TypeError('fetch failed') }, { status: 204 }] })
    await notify(event(sample('composite-alarm')), null, chat.ports)

    assert.equal(chat.posts.length, 2)
    assert.deepEqual(chat.deadLetters, [])
    assert.equal(chat.logs[0]?.fields.outcome, 'success')
  })

  it('keeps the notification when the address of the webhook cannot be read', async () => {
    const chat = fake({ failSecret: true })
    await notify(event(sample('metric-alarm')), null, chat.ports)

    assert.deepEqual(chat.posts, [])
    assert.equal(chat.deadLetters[0]?.reason, 'the address of the chat webhook could not be read')
    assert.equal(chat.logs[0]?.fields.outcome, 'failure')
  })

  it('fails the invocation when even the dead-letter queue does not take the notification, so Lambda retries it', async () => {
    const chat = fake({ answers: [{ status: 503 }], failDeadLetter: true })

    await assert.rejects(notify(event(sample('metric-alarm')), null, chat.ports), /SQS does not answer/)
  })

  it('skips a message that is not an alarm, and never logs the address of the webhook', async () => {
    const chat = fake({ answers: [{ status: 500 }] })
    await notify(event('{"Type":"SubscriptionConfirmation"}', sample('metric-alarm')), null, chat.ports)

    assert.equal(chat.logs[0]?.fields.outcome, 'skipped')
    assert.doesNotMatch(JSON.stringify(chat.logs), /hooks\.chat\.example|aSecretPartOfTheAddress/)
  })

  it('reads the address of the webhook from its secret, as JSON or alone', () => {
    assert.equal(urlOfSecret('{"url": " https://hooks.chat.example/x "}'), 'https://hooks.chat.example/x')
    assert.equal(urlOfSecret('{"url": ""}'), '')
    assert.equal(urlOfSecret('https://hooks.chat.example/y'), 'https://hooks.chat.example/y')
    assert.equal(urlOfSecret(undefined), '')
  })
})
