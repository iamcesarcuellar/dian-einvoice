// The steps of make cloud-drill (scripts/cloud-drill.sh) that watch the local AWS emulator and the local receiver of the
// chat: whether the platform is healthy, when the incident was detected and notified, when it was over, and the timeline.
// The drill itself acts through the console function (php artisan cloud:drill) and the commands of the runbook.
//
//   node scripts/drill.mjs healthy
//       the dead-letter queues of the chain and of the webhooks are empty and the alarm chain-dead-letters is not in ALARM
//       (waiting up to 3 minutes for an alarm an earlier run left behind)
//   node scripts/drill.mjs detect <since-ms> <messages>
//       waits until the dead-letter queue of the chain holds the messages, the emulator turns chain-dead-letters to
//       ALARM, and the chat takes its notification naming its runbook; prints when each happened
//   node scripts/drill.mjs recovered <since-ms>
//       waits until both dead-letter queues are empty, the alarm is back to OK, and the chat takes that too
//   node scripts/drill.mjs story <correlation-id> <since-ms>
//       the journey of one invoice of the incident with the saved query follow-a-journey, as make cloud-follow runs it:
//       one story under its correlation id, in order, from the calls to the DIAN that failed and its dead letter to the
//       step that came back from it, its acceptance, its email and its webhooks
//   node scripts/drill.mjs timeline <name=moment>...
//       the timeline of the drill, each moment an ISO time or milliseconds, with the time to detect, to notify and to
//       recover from the start of the incident
import { CloudWatchClient, DescribeAlarmsCommand } from '@aws-sdk/client-cloudwatch'
import { CloudWatchLogsClient, DescribeQueryDefinitionsCommand, GetQueryResultsCommand, StartQueryCommand } from '@aws-sdk/client-cloudwatch-logs'
import { GetSecretValueCommand, SecretsManagerClient } from '@aws-sdk/client-secrets-manager'
import { GetQueueAttributesCommand, GetQueueUrlCommand, SQSClient } from '@aws-sdk/client-sqs'

const STAGE = 'local'
const PREFIX = `dian-einvoice-${STAGE}`
const ALARM = `${PREFIX}-chain-dead-letters`
const RUNBOOK = 'docs/runbooks/dead-letters.md'
const SIMULATOR = process.env.SIMULATOR_URL ?? 'http://simulator:8602/cloud'
const sqs = new SQSClient({})
const cloudwatch = new CloudWatchClient({})
const logs = new CloudWatchLogsClient({})
const sleep = (milliseconds) => new Promise((resolve) => { setTimeout(resolve, milliseconds) })
let failures = 0

function verify(passed, message) {
  process.stdout.write(`   ${passed ? 'PASS' : 'FAIL'}  ${message}\n`)
  failures += passed ? 0 : 1
}

async function depth(name) {
  const { QueueUrl: url } = await sqs.send(new GetQueueUrlCommand({ QueueName: `${PREFIX}-${name}` }))
  const attributes = (await sqs.send(new GetQueueAttributesCommand({ QueueUrl: url, AttributeNames: ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible'] }))).Attributes ?? {}

  return Number(attributes.ApproximateNumberOfMessages ?? 0) + Number(attributes.ApproximateNumberOfMessagesNotVisible ?? 0)
}

async function alarm() {
  return (await cloudwatch.send(new DescribeAlarmsCommand({ AlarmNames: [ALARM] }))).MetricAlarms?.[0]
}

async function state() {
  return (await alarm())?.StateValue ?? 'MISSING'
}

/** When the alarm changed to a state, by its own clock, once it is in it; null otherwise. */
async function reached(wanted) {
  const found = await alarm()

  return found?.StateValue === wanted ? (found.StateUpdatedTimestamp?.getTime() ?? Date.now()) : null
}

/** The messages the local receiver of the chat took since a moment, as [received at, text]. */
async function chat(since) {
  const secret = await new SecretsManagerClient({}).send(new GetSecretValueCommand({ SecretId: `dian-einvoice/${STAGE}/alerts/chat-webhook` }))
  const receiver = new URL(JSON.parse(secret.SecretString ?? '{}').url).pathname.split('/').pop()
  const response = await fetch(`${SIMULATOR}/admin/webhook-receiver/${receiver}?limit=50`, { headers: { 'X-Simulator-Token': process.env.SIMULATOR_TOKEN ?? '' } })
  const body = await response.json()

  return (body.received ?? [])
    .map((entry) => {
      let text = ''

      try {
        text = JSON.parse(entry.body_preview).text ?? ''
      } catch {
        text = ''
      }

      return [Date.parse(entry.received_at), text]
    })
    .filter(([at]) => at >= since)
}

/** Polls until a condition holds, at most some seconds; the moment it held, in milliseconds, or null. */
async function until(condition, seconds) {
  for (const deadline = Date.now() + seconds * 1000; Date.now() < deadline; await sleep(3000)) {
    const moment = await condition()

    if (moment !== null && moment !== false) {
      return moment === true ? Date.now() : moment
    }
  }

  return null
}

async function healthy() {
  const leftOver = await state() === 'ALARM'
  const okAt = await until(async () => await state() !== 'ALARM', 180)
  const dead = { chain: await depth('chain-dlq'), webhooks: await depth('webhooks-dlq') }
  verify(okAt !== null && dead.chain === 0 && dead.webhooks === 0, `Healthy before the incident: the chain's and the webhooks' dead-letter queues hold ${dead.chain} and ${dead.webhooks} messages, and ${ALARM} is ${await state()}${leftOver ? ' (an earlier run had left it in ALARM)' : ''}.`)
}

async function detect(since, expected) {
  let deadAt = null
  let alarmAt = null
  let notifiedAt = null
  let text = ''
  const deadline = Date.now() + 480_000

  // The three are watched together, each noted when it first holds.
  while ((deadAt === null || alarmAt === null || notifiedAt === null) && Date.now() < deadline) {
    deadAt ??= await depth('chain-dlq') >= expected ? Date.now() : null
    alarmAt ??= await reached('ALARM')

    if (notifiedAt === null) {
      const found = (await chat(since)).find(([, message]) => message.includes(`*ALARM* ${ALARM}`) && message.includes(`Runbook: ${RUNBOOK}`))
      notifiedAt = found?.[0] ?? null
      text = found?.[1] ?? ''
    }

    await sleep(deadAt !== null && alarmAt !== null && notifiedAt !== null ? 0 : 3000)
  }

  verify(deadAt !== null, `The ${expected} messages of the transmissions that gave up are in the chain's dead-letter queue${deadAt === null ? ': not within 8 minutes' : ''}.`)
  verify(alarmAt !== null, `The emulator turned ${ALARM} to ALARM${alarmAt === null ? ': not within 8 minutes' : ''}.`)
  verify(notifiedAt !== null, `Its notification reached the chat (the local receiver) naming its runbook${notifiedAt === null ? ': none within 8 minutes' : `: ${JSON.stringify(text.split('\n')[0])}, ${JSON.stringify(text.split('\n').find((line) => line.startsWith('Runbook:')) ?? '')}`}.`)
  process.stdout.write(`dead_letters_at=${deadAt ?? ''}\nalarm_at=${alarmAt ?? ''}\nnotified_at=${notifiedAt ?? ''}\n`)
}

async function recovered(since) {
  const emptyAt = await until(async () => await depth('chain-dlq') === 0 && await depth('webhooks-dlq') === 0, 120)
  const okAt = await until(async () => await reached('OK'), 300)
  const toldAt = okAt === null ? null : await until(async () => (await chat(since)).find(([, message]) => message.includes(`*OK* ${ALARM}`))?.[0] ?? null, 60)
  verify(emptyAt !== null, `Both dead-letter queues are empty${emptyAt === null ? ': not within 2 minutes' : ''}.`)
  verify(okAt !== null && toldAt !== null, `${ALARM} went back to OK by itself, and the chat heard it${okAt === null ? ': not within 5 minutes' : toldAt === null ? ': no message' : ''}.`)
  process.stdout.write(`ok_at=${okAt ?? ''}\nok_notified_at=${toldAt ?? ''}\n`)
}

/** The lines of one journey with the saved query follow-a-journey, its placeholder replaced as make cloud-follow does. */
async function journey(correlation, since) {
  const definitions = (await logs.send(new DescribeQueryDefinitionsCommand({ queryDefinitionNamePrefix: `dian-einvoice/${STAGE}/` }))).queryDefinitions ?? []
  const saved = definitions.find((definition) => definition.name === `dian-einvoice/${STAGE}/follow-a-journey`)

  if (saved === undefined) {
    throw new Error('The saved query follow-a-journey is not in the emulator: run make cloud-up.')
  }

  const queryString = String(saved.queryString).replace('REPLACE-WITH-THE-CORRELATION-ID', correlation)
  const { queryId } = await logs.send(new StartQueryCommand({ logGroupNames: saved.logGroupNames ?? [], queryString, startTime: Math.floor(since / 1000) - 60, endTime: Math.ceil(Date.now() / 1000) + 60 }))

  for (let attempt = 0; attempt < 60; attempt++) {
    const answer = await logs.send(new GetQueryResultsCommand({ queryId }))

    if (answer.status === 'Complete') {
      return (answer.results ?? []).map((row) => Object.fromEntries(row.map((cell) => [cell.field, cell.value])))
    }

    if (['Failed', 'Cancelled', 'Timeout'].includes(answer.status ?? '')) {
      throw new Error(`The query ended ${answer.status}.`)
    }

    await sleep(500)
  }

  throw new Error('The query did not complete.')
}

/** The chapters of the story of an invoice of the incident, in order: the line that tells each. */
const CHAPTERS = [
  ['dian.call', 'failure', 'the calls to the DIAN that failed'],
  ['queue.dead_letter', 'failure', 'its dead letter'],
  ['chain.resumed', 'retried', 'the step that came back from it'],
  ['dian.call', 'success', 'the call that got through'],
  ['dian.response', 'accepted', 'its acceptance'],
  ['delivery.email', 'success', 'its email'],
  ['webhook.delivery', 'success', 'its webhooks'],
]

async function story(correlation, since) {
  let rows = []
  let missing = CHAPTERS[0]

  // The lines of the recovery reach the logs of the emulator a moment after it.
  const told = await until(async () => {
    rows = await journey(correlation, since)
    missing = null
    let from = 0

    for (const chapter of CHAPTERS) {
      const index = rows.findIndex((row, position) => position >= from && row.event === chapter[0] && row.outcome === chapter[1])

      if (index === -1) {
        missing = chapter

        return false
      }

      from = index + 1
    }

    return true
  }, 120)

  const functions = [...new Set(rows.map((row) => row.function).filter((name) => name !== undefined && name !== 'null'))].sort()
  const failed = rows.filter((row) => row.event === 'dian.call' && row.outcome === 'failure').length
  const requested = rows.some((row) => row.function === 'api' && row.event === 'http.request')
  verify(told !== null && requested, `make cloud-follow ID=${correlation} tells an incident invoice as one story under its correlation id: its request to the API${requested ? '' : ' (missing)'}, then in order ${failed} calls to the DIAN that failed, ${CHAPTERS.slice(1).map((chapter) => chapter[2]).join(', ')} (${rows.length} lines in ${functions.join(', ')})${told === null ? `; missing, after what came before it: ${missing?.[2]} (${missing?.[0]} ${missing?.[1]})` : ''}.`)
}

function timeline(pairs) {
  const moments = Object.fromEntries(pairs.map((pair) => pair.split('=')).map(([name, value]) => [name, value === undefined || value === '' ? null : (/^\d+$/.test(value) ? Number(value) : Date.parse(value))]))
  const start = moments.incident
  const since = (name) => (moments[name] === null || moments[name] === undefined || start === null ? 'not observed' : `+${((moments[name] - start) / 1000).toFixed(0)} s`)
  const at = (name) => (moments[name] === null || moments[name] === undefined ? '-' : new Date(moments[name]).toISOString().slice(11, 19))
  const rows = [
    ['incident', "The DIAN goes down: the simulator responds with HTTP 500 to every call; the incident's invoices are issued through the edge"],
    ['first_failure', 'The first call to the DIAN without a response; the transmissions retry (after 2 and 5 s in the local stage)'],
    ['dead_letters', "Every transmission of the incident gave up: its message is in the chain's dead-letter queue"],
    ['alarm', 'The emulator turns chain-dead-letters to ALARM: detected'],
    ['notified', 'Its notification reaches the chat with its runbook: notified'],
    ['restored', 'The DIAN responds again (the drill restores the simulator; dian:probe confirms it)'],
    ['redriven', 'The dead letters are moved back to the chain queue with the native redrive of SQS'],
    ['recovered', "The incident's last invoice accepted and delivered: recovered"],
    ['ok', 'chain-dead-letters back to OK'],
  ]

  process.stdout.write("The drill's timeline (UTC; the emulator's times, Floci 2.5.0 on this machine, not AWS's):\n")

  for (const [name, what] of rows) {
    process.stdout.write(`  ${at(name)}  ${since(name).padEnd(13)} ${what}\n`)
  }

  process.stdout.write(`Time to detect ${since('alarm')}, to notify ${since('notified')}, to recover ${since('recovered')} (to the alarm back to OK ${since('ok')}), from the incident's start.\n`)
}

const [action, ...rest] = process.argv.slice(2)

switch (action) {
  case 'healthy':
    await healthy()
    break
  case 'detect':
    await detect(Number(rest[0]), Number(rest[1] ?? 1))
    break
  case 'recovered':
    await recovered(Number(rest[0]))
    break
  case 'story':
    await story(String(rest[0] ?? ''), Number(rest[1]))
    break
  case 'timeline':
    timeline(rest)
    break
  default:
    process.stderr.write('usage: node scripts/drill.mjs healthy | detect <since-ms> <messages> | recovered <since-ms> | story <correlation-id> <since-ms> | timeline <name=moment>...\n')
    process.exit(2)
}

process.exit(failures === 0 ? 0 : 1)
