// The checks of the observability of the emulated cloud (Infrastructure task 5), which read the local AWS emulator
// and the local receiver of the chat:
//
//   node scripts/observability.mjs check <since-ms> <correlation-id>
//       every line the functions wrote since then is JSON with the fields of the schema of the log lines
//       (backend/resources/observability/log-schema.json), the runtime's own lines when the emulator removed an idle
//       container aside; the journey of an invoice issued through the edge is followed with the saved query, in the
//       functions api, chain and webhooks, with the id the client got in X-Correlation-Id; no value of a secret of the
//       stage and nothing that looks like an email, a key or a token is in any log; a metric filter produced its
//       metric; and a state of an alarm forced with SetAlarmState reaches the chat (the local receiver) as the
//       notifier formats it, and back to OK
//   node scripts/observability.mjs evaluation
//       the emulator evaluates alarms for real (minutes: make cloud-alarm-check): a message in the dead-letter queue of
//       the chain turns its alarm to ALARM and reaches the chat, and taking it out turns it back to OK; then the chat
//       refuses a notification, which the notifier keeps in its dead-letter queue, whose alarm reaches the chat
//   node scripts/observability.mjs follow <correlation-id> [hours]
//       make cloud-follow: the lines of one journey, with the saved query follow-a-journey
//   node scripts/observability.mjs alarms
//       make cloud-alarms: the state of every alarm, and the last messages the chat (the local receiver) took
//   node scripts/observability.mjs reconcile
//       make cloud-up: gives the emulator what its CloudFormation (Floci 2.5.0) drops from the deployed template of the
//       stack observability: the metric of every alarm on a metric with dimensions (SQS, Lambda, API Gateway) and the
//       metrics of every alarm of metric math, which it creates without them, so they could never evaluate; and the
//       saved queries of Logs Insights the template declares in its metadata, since it cannot create
//       AWS::Logs::QueryDefinition. A workaround for the emulator: it puts exactly what the template declares, by
//       name, changes nothing else, says what it gave back and when nothing had to be, and refuses to run anywhere
//       but against the emulator (docs/limitations.md)
import { readFileSync } from 'node:fs'
import { CloudWatchClient, DescribeAlarmsCommand, GetDashboardCommand, GetMetricStatisticsCommand, ListMetricsCommand, PutMetricAlarmCommand, SetAlarmStateCommand } from '@aws-sdk/client-cloudwatch'
import { CloudFormationClient, DescribeStackResourcesCommand, GetTemplateCommand } from '@aws-sdk/client-cloudformation'
import { CloudWatchLogsClient, DescribeLogGroupsCommand, DescribeMetricFiltersCommand, DescribeQueryDefinitionsCommand, FilterLogEventsCommand, GetQueryResultsCommand, PutQueryDefinitionCommand, StartQueryCommand } from '@aws-sdk/client-cloudwatch-logs'
import { GetSecretValueCommand, SecretsManagerClient } from '@aws-sdk/client-secrets-manager'
import { GetTopicAttributesCommand, ListSubscriptionsByTopicCommand, SNSClient } from '@aws-sdk/client-sns'
import { DeleteMessageCommand, GetQueueAttributesCommand, GetQueueUrlCommand, ReceiveMessageCommand, SendMessageCommand, SQSClient } from '@aws-sdk/client-sqs'
import { GetParameterCommand, SSMClient } from '@aws-sdk/client-ssm'

const STAGE = 'local'
const PREFIX = `dian-einvoice-${STAGE}`
const SCHEMA = JSON.parse(readFileSync(process.env.LOG_SCHEMA ?? '/workspace/observability/log-schema.json', 'utf8'))
const REQUIRED = SCHEMA.fields.filter((field) => field.optional !== true)
const SIMULATOR = process.env.SIMULATOR_URL ?? 'http://simulator:8602/cloud'
const logs = new CloudWatchLogsClient({})
const cloudwatch = new CloudWatchClient({})
let failures = 0

function verify(passed, message) {
  process.stdout.write(`   ${passed ? 'PASS' : 'FAIL'}  ${message}\n`)
  failures += passed ? 0 : 1
}

const sleep = (milliseconds) => new Promise((resolve) => { setTimeout(resolve, milliseconds) })

/** Every event of a log group since a moment, in order, with the stream it is in. */
async function eventsOf(group, since) {
  const events = []
  let token

  do {
    const page = await logs.send(new FilterLogEventsCommand({ logGroupName: group, startTime: since, nextToken: token }))
    events.push(...(page.events ?? []))
    token = page.nextToken
  } while (token !== undefined)

  return events.sort((a, b) => (a.timestamp ?? 0) - (b.timestamp ?? 0))
}

/**
 * What the runtime of a function prints when the emulator removes its idle container: the endpoint of the
 * invocations goes away first, so the runtime fails its long poll and prints the error in its own format, from that
 * line to the end of the stream. AWS freezes and removes a sandbox without it.
 */
const RUNTIME_STOPPED = [/LAMBDA_RUNTIME Failed to get next invocation/, /Uncaught Exception: Failed to fetch next Lambda invocation/]

/** The problems of a line of the schema: a field missing, one null that never is, a value out of its list. */
function problemsOf(line) {
  const problems = []
  const keys = Object.keys(line)

  if (keys.slice(0, REQUIRED.length).join(',') !== REQUIRED.map((field) => field.name).join(',')) {
    problems.push(`fields ${keys.join(',')}`)
  }

  for (const field of REQUIRED) {
    if (field.nullable !== true && (line[field.name] === null || line[field.name] === undefined)) {
      problems.push(`${field.name} is null`)
    }

    if (field.values !== undefined && line[field.name] !== null && !field.values.includes(line[field.name])) {
      problems.push(`${field.name} is ${line[field.name]}`)
    }
  }

  if (line.correlation_id !== null && !new RegExp(SCHEMA.correlation.pattern).test(line.correlation_id)) {
    problems.push('correlation_id is not one')
  }

  return problems
}

async function jsonLines(since) {
  const groups = ((await logs.send(new DescribeLogGroupsCommand({ logGroupNamePrefix: `/aws/lambda/${PREFIX}-` }))).logGroups ?? []).map((group) => group.logGroupName)
  const all = []
  const counts = []
  const wrong = []
  let stopped = 0
  let platform = 0

  for (const group of groups) {
    const events = await eventsOf(group, since)
    const byStream = new Map()

    for (const event of events) {
      byStream.set(event.logStreamName, [...(byStream.get(event.logStreamName) ?? []), event])
    }

    let written = 0

    for (const streamEvents of byStream.values()) {
      const cut = streamEvents.findIndex((event) => RUNTIME_STOPPED.some((pattern) => pattern.test(event.message ?? '')))
      const kept = cut === -1 ? streamEvents : streamEvents.slice(0, cut)
      stopped += streamEvents.length - kept.length

      for (const event of kept) {
        const text = (event.message ?? '').trim()
        all.push({ group, text })
        let line = null

        try {
          line = JSON.parse(text)
        } catch {
          wrong.push(`${group}: not JSON: ${text.slice(0, 160)}`)
          continue
        }

        // The system logs of Lambda in JSON (platform.start, platform.report...) are Lambda's, not of the schema.
        if (typeof line?.type === 'string' && line.type.startsWith('platform.')) {
          platform++
          continue
        }

        const problems = typeof line === 'object' && line !== null ? problemsOf(line) : ['not an object']

        if (problems.length > 0) {
          wrong.push(`${group}: ${problems.join('; ')}: ${text.slice(0, 160)}`)
        }

        written++
      }
    }

    counts.push(`${group.replace(`/aws/lambda/${PREFIX}-`, '')} ${written}`)
  }

  verify(all.length > 0 && wrong.length === 0, `Every line the functions wrote during the check is JSON with the ${REQUIRED.length} fields of the schema, in order: ${counts.join(', ')} lines${platform > 0 ? `, and ${platform} system lines of Lambda in JSON` : ''}${stopped > 0 ? `; ${stopped} lines of runtimes the emulator stopped when it removed their idle containers set aside (docs/limitations.md)` : ''}${wrong.length === 0 ? '' : `; wrong (${wrong.length}): ${wrong.slice(0, 5).join(' | ')}`}.`)

  return all
}

/** Runs a query of Logs Insights and returns its rows as objects. */
async function query(queryString, groups, since) {
  const { queryId } = await logs.send(new StartQueryCommand({ logGroupNames: groups, queryString, startTime: Math.floor(since / 1000) - 60, endTime: Math.ceil(Date.now() / 1000) + 60 }))

  for (let attempt = 0; attempt < 60; attempt++) {
    const answer = await logs.send(new GetQueryResultsCommand({ queryId }))

    if (['Complete', 'Failed', 'Cancelled', 'Timeout'].includes(answer.status ?? '')) {
      if (answer.status !== 'Complete') {
        throw new Error(`The query ended ${answer.status}.`)
      }

      return (answer.results ?? []).map((row) => Object.fromEntries(row.map((cell) => [cell.field, cell.value])))
    }

    await sleep(500)
  }

  throw new Error('The query did not complete.')
}

async function follow(since, correlation) {
  const definitions = (await logs.send(new DescribeQueryDefinitionsCommand({ queryDefinitionNamePrefix: `dian-einvoice/${STAGE}/` }))).queryDefinitions ?? []
  const saved = definitions.find((definition) => definition.name === `dian-einvoice/${STAGE}/follow-a-journey`)

  if (saved === undefined) {
    verify(false, `The saved query dian-einvoice/${STAGE}/follow-a-journey exists (found ${definitions.map((definition) => definition.name).join(', ') || 'none'}).`)

    return
  }

  // The saved query as an operator runs it: its placeholder replaced by the id the client got.
  const rows = await query(String(saved.queryString).replace('REPLACE-WITH-THE-CORRELATION-ID', correlation), saved.logGroupNames ?? [], since)
  const functions = [...new Set(rows.map((row) => row.function))].sort()
  const events = [...new Set(rows.map((row) => row.event).filter((event) => event !== undefined && event !== 'null'))]
  const issued = rows.find((row) => row.function === 'api' && row.event === 'http.request')
  const missing = ['api', 'chain', 'webhooks'].filter((fn) => !functions.includes(fn))
  verify(rows.length > 0 && missing.length === 0 && issued !== undefined,
    `The saved query follow-a-journey follows the invoice issued through the edge by the id the client got in X-Correlation-Id (${correlation}): ${rows.length} lines in ${functions.join(', ')}${missing.length === 0 ? '' : `, none in ${missing.join(', ')}`}, from its request to the API to its webhooks (${events.slice(0, 12).join(', ')}).`)
}

async function secretValues() {
  const secrets = new SecretsManagerClient({})
  const values = []
  const read = async (id, stage = 'AWSCURRENT') => {
    try {
      return (await secrets.send(new GetSecretValueCommand({ SecretId: id, VersionStage: stage }))).SecretString ?? ''
    } catch {
      return ''
    }
  }

  for (const id of [`dian-einvoice/${STAGE}/app`, `dian-einvoice/${STAGE}/edge/origin-verify`, `dian-einvoice/${STAGE}/database/application`, `dian-einvoice/${STAGE}/database/migrations`, `dian-einvoice/${STAGE}/alerts/chat-webhook`]) {
    for (const stage of ['AWSCURRENT', 'AWSPREVIOUS']) {
      const text = await read(id, stage)

      if (text === '') {
        continue
      }

      try {
        const parsed = JSON.parse(text)

        for (const [key, value] of Object.entries(parsed)) {
          if (typeof value === 'string' && value.length >= 8 && ['APP_KEY', 'password', 'url'].includes(key)) {
            values.push([`${id} ${key}`, value])
          }
        }
      } catch {
        values.push([id, text])
      }
    }
  }

  return values
}

/** What must never be in a log: an email, a key or a certificate, a token, a credential of this platform or of AWS. */
const PATTERNS = [
  ['an email', /[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/],
  ['a key or a certificate', /-----BEGIN [A-Z ]+-----/],
  ['an API key', /\bdei_[A-Za-z0-9]{8,}/],
  ['a webhook secret', /\bwhsec_[A-Za-z0-9+/=]{8,}/],
  ['a bearer token', /\bBearer\s+[A-Za-z0-9._~+/-]{8,}/],
  ['a JSON web token', /\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\./],
  ['an AWS access key', /\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/],
  ['an APP_KEY', /\bbase64:[A-Za-z0-9+/]{20,}/],
]

async function sweep(lines, since) {
  const access = await eventsOf(`/aws/http-api/${PREFIX}-functions`, since).catch(() => [])
  const texts = [...lines.map((entry) => entry.text), ...access.map((event) => event.message ?? '')]
  const values = await secretValues()
  const found = []

  for (const [name, value] of values) {
    if (texts.some((text) => text.includes(value))) {
      found.push(`the value of ${name}`)
    }
  }

  for (const [name, pattern] of PATTERNS) {
    const match = texts.find((text) => pattern.test(text))

    if (match !== undefined) {
      found.push(`${name} (${match.slice(0, 120)})`)
    }
  }

  verify(values.length >= 4 && found.length === 0, `No log holds a secret or personal data: ${texts.length} lines swept for the ${values.length} values of the secrets of the stage and for emails, keys, certificates and tokens${found.length === 0 ? '' : `; found: ${found.join('; ')}`}.`)
}

async function metric(since) {
  const namespace = `dian-einvoice/${STAGE}`
  const listed = (await cloudwatch.send(new ListMetricsCommand({ Namespace: namespace }))).Metrics ?? []
  let sum = 0

  for (let attempt = 0; attempt < 30 && sum === 0; attempt++) {
    const statistics = await cloudwatch.send(new GetMetricStatisticsCommand({ Namespace: namespace, MetricName: 'DocumentsIssued', StartTime: new Date(since - 60_000), EndTime: new Date(Date.now() + 60_000), Period: 60, Statistics: ['Sum'] }))
    sum = (statistics.Datapoints ?? []).reduce((total, point) => total + (point.Sum ?? 0), 0)

    if (sum === 0) {
      await sleep(2000)
    }
  }

  const names = [...new Set(listed.map((candidate) => candidate.MetricName))].sort()
  verify(sum > 0, `The metric filters of the application produce their metrics: DocumentsIssued counted ${sum} documents issued during the check (metrics of ${namespace}: ${names.join(', ') || 'none'}).`)
}

/** The messages the local receiver of the chat took since a moment, newest first. */
async function chat(since) {
  const secret = await new SecretsManagerClient({}).send(new GetSecretValueCommand({ SecretId: `dian-einvoice/${STAGE}/alerts/chat-webhook` }))
  const url = new URL(JSON.parse(secret.SecretString ?? '{}').url)
  const receiver = url.pathname.split('/').pop()
  const response = await fetch(`${SIMULATOR}/admin/webhook-receiver/${receiver}?limit=50`, { headers: { 'X-Simulator-Token': process.env.SIMULATOR_TOKEN ?? '' } })
  const body = await response.json()

  return (body.received ?? []).filter((entry) => Date.parse(entry.received_at) >= since).map((entry) => entry.body_preview)
}

/** Waits until the chat takes a message that contains every piece. */
async function message(since, pieces, seconds) {
  for (let waited = 0; waited < seconds; waited += 2) {
    const texts = (await chat(since)).map((body) => { try { return JSON.parse(body).text ?? '' } catch { return '' } })
    const found = texts.find((text) => pieces.every((piece) => text.includes(piece)))

    if (found !== undefined) {
      return found
    }

    await sleep(2000)
  }

  return null
}

async function notification() {
  const alarm = `${PREFIX}-chain-dead-letters`
  const reason = `make cloud-check forced this state at ${new Date().toISOString()} to follow the path of an alarm to the chat.`
  const since = Date.now() - 2000
  await cloudwatch.send(new SetAlarmStateCommand({ AlarmName: alarm, StateValue: 'ALARM', StateReason: reason }))
  const text = await message(since, [`*ALARM* ${alarm}`, `Reason: ${reason}`, 'Runbook: docs/runbooks/dead-letters.md', 'A message reached the dead-letter queue'], 60)
  verify(text !== null, `A state forced on the alarm ${alarm} goes through the topic of the alarms and the notifier to the chat (the local receiver) as a short message: ${text === null ? 'none arrived within 60 s' : JSON.stringify(text.split('\n').slice(0, 1)[0])}, with its reason, what it means and its runbook.`)

  const back = Date.now() - 2000
  await cloudwatch.send(new SetAlarmStateCommand({ AlarmName: alarm, StateValue: 'OK', StateReason: 'make cloud-check put it back.' }))
  const ok = await message(back, [`*OK* ${alarm}`], 60)
  verify(ok !== null, `Its way back to OK reaches the chat too${ok === null ? ': none arrived within 60 s' : ''}.`)
}

/** What the stacks alerts and observability declare exists for real in the emulator, as their templates say. */
async function deployed() {
  const { TemplateBody: body } = await new CloudFormationClient({}).send(new GetTemplateCommand({ StackName: `${PREFIX}-observability` }))
  const declared = Object.values(JSON.parse(body ?? '{}').Resources ?? {})
  const count = (type) => declared.filter((resource) => resource.Type === type).length
  const groups = [...new Set(declared.filter((resource) => resource.Type === 'AWS::Logs::MetricFilter').map((resource) => resource.Properties.LogGroupName))]
  let filters = 0

  for (const group of groups) {
    filters += ((await logs.send(new DescribeMetricFiltersCommand({ logGroupName: group }))).metricFilters ?? []).length
  }

  const alarms = (await cloudwatch.send(new DescribeAlarmsCommand({ AlarmNamePrefix: `${PREFIX}-`, MaxRecords: 100 }))).MetricAlarms ?? []
  const withActions = alarms.filter((alarm) => (alarm.AlarmActions ?? []).length > 0 && (alarm.OKActions ?? []).length > 0 && /Runbook: docs\/runbooks\//.test(alarm.AlarmDescription ?? '') && (alarm.MetricName !== undefined || (alarm.Metrics ?? []).length > 0))
  const dashboard = await cloudwatch.send(new GetDashboardCommand({ DashboardName: `${PREFIX}-operations` })).then((answer) => (answer.DashboardBody ?? '').length).catch(() => 0)
  const queries = (await logs.send(new DescribeQueryDefinitionsCommand({ queryDefinitionNamePrefix: `dian-einvoice/${STAGE}/` }))).queryDefinitions ?? []
  verify(filters === count('AWS::Logs::MetricFilter') && alarms.length === count('AWS::CloudWatch::Alarm') && withActions.length === alarms.length && dashboard > 0 && queries.length === 4,
    `The observability exists for real in the emulator: ${filters} metric filters on ${groups.length} log groups, ${alarms.length} alarms each with its metric, its actions and its runbook (${withActions.length}), the dashboard ${PREFIX}-operations (${dashboard} bytes) and ${queries.length} saved queries, as the template declares (${count('AWS::Logs::MetricFilter')} filters, ${count('AWS::CloudWatch::Alarm')} alarms).`)

  const ssm = new SSMClient({})
  const topic = (await ssm.send(new GetParameterCommand({ Name: `/dian-einvoice/${STAGE}/alerts/alarms-topic-arn` }))).Parameter?.Value ?? ''
  const sns = new SNSClient({})
  const subscriptions = (await sns.send(new ListSubscriptionsByTopicCommand({ TopicArn: topic }))).Subscriptions ?? []
  const attributes = (await sns.send(new GetTopicAttributesCommand({ TopicArn: topic }))).Attributes ?? {}
  const notifier = subscriptions.find((subscription) => subscription.Protocol === 'lambda' && String(subscription.Endpoint).endsWith(`:function:${PREFIX}-notifier`))
  // The key of the topic is accepted and not applied by the emulator, which does not report it (docs/limitations.md).
  verify(notifier !== undefined,
    `The notifier is subscribed to the topic of the alarms, ${topic.split(':').pop()} (${subscriptions.length} subscriptions${attributes.KmsMasterKeyId === undefined ? '; its KMS key, which the emulator accepts and does not apply, checked on the template' : `, encrypted with ${attributes.KmsMasterKeyId}`}).`)
}

async function check() {
  const since = Number(process.argv[3])
  const correlation = process.argv[4] ?? ''

  if (!Number.isFinite(since) || correlation === '') {
    process.stderr.write('usage: node scripts/observability.mjs check <since-ms> <correlation-id>\n')
    process.exit(2)
  }

  await deployed()
  // The path of an alarm first, so the lines of the notifier are among those checked.
  await notification()
  await sleep(2000)
  const lines = await jsonLines(since)
  await follow(since, correlation)
  await sweep(lines, since)
  await metric(since)
}

async function state(alarm) {
  return (await cloudwatch.send(new DescribeAlarmsCommand({ AlarmNames: [alarm] }))).MetricAlarms?.[0]?.StateValue
}

async function evaluation() {
  const alarm = `${PREFIX}-chain-dead-letters`
  const sqs = new SQSClient({})
  const { QueueUrl: queueUrl } = await sqs.send(new GetQueueUrlCommand({ QueueName: `${PREFIX}-chain-dlq` }))
  const started = Date.now()
  const marker = `cloud-alarm-check-${started}`

  if (await state(alarm) === 'ALARM') {
    await cloudwatch.send(new SetAlarmStateCommand({ AlarmName: alarm, StateValue: 'OK', StateReason: 'make cloud-alarm-check starts from OK.' }))
  }

  await sqs.send(new SendMessageCommand({ QueueUrl: queueUrl, MessageBody: JSON.stringify({ marker, note: 'A message make cloud-alarm-check puts in the dead-letter queue of the chain, and takes out.' }) }))
  process.stdout.write(`   A message in the dead-letter queue of the chain; waiting for the emulator to publish the metrics of SQS and evaluate ${alarm}...\n`)

  let reached = null

  for (let waited = 0; waited < 300 && reached === null; waited += 5) {
    reached = await state(alarm) === 'ALARM' ? Date.now() : null
    await sleep(reached === null ? 5000 : 0)
  }

  const text = reached === null ? null : await message(started, [`*ALARM* ${alarm}`, 'Runbook: docs/runbooks/dead-letters.md'], 60)
  verify(reached !== null && text !== null, `The emulator evaluated ${alarm} for real: a message in the dead-letter queue turned it to ALARM after ${reached === null ? 'more than 300' : Math.round((reached - started) / 1000)} s, and the notification reached the chat: ${text === null ? 'none' : JSON.stringify(text.split('\n')[0])}${text === null ? '' : `, ${JSON.stringify(text.split('\n').find((line) => line.startsWith('Reason:')) ?? '')}`}.`)

  // The message goes, and the alarm goes back to OK by itself.
  for (let attempt = 0; attempt < 10; attempt++) {
    const received = await sqs.send(new ReceiveMessageCommand({ QueueUrl: queueUrl, MaxNumberOfMessages: 10, WaitTimeSeconds: 1 }))

    for (const found of received.Messages ?? []) {
      if ((found.Body ?? '').includes(marker)) {
        await sqs.send(new DeleteMessageCommand({ QueueUrl: queueUrl, ReceiptHandle: found.ReceiptHandle }))
      }
    }
  }

  const cleared = Date.now()
  let ok = null

  for (let waited = 0; waited < 300 && ok === null; waited += 5) {
    ok = await state(alarm) === 'OK' ? Date.now() : null
    await sleep(ok === null ? 5000 : 0)
  }

  const back = ok === null ? null : await message(cleared, [`*OK* ${alarm}`], 60)
  verify(ok !== null && back !== null, `Taken out of the queue, the alarm went back to OK by itself after ${ok === null ? 'more than 300' : Math.round((ok - cleared) / 1000)} s, and said so in the chat${back === null ? ': no message' : ''}.`)

  await notifierFailure(sqs)
}

/** How many messages a queue holds, visible or not. */
async function depth(sqs, url) {
  const attributes = (await sqs.send(new GetQueueAttributesCommand({ QueueUrl: url, AttributeNames: ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible'] }))).Attributes ?? {}

  return Number(attributes.ApproximateNumberOfMessages ?? 0) + Number(attributes.ApproximateNumberOfMessagesNotVisible ?? 0)
}

/** Waits until a condition holds, for at most some seconds; the moment it held, or null. */
async function until(condition, seconds) {
  for (let waited = 0; waited < seconds; waited += 5) {
    if (await condition()) {
      return Date.now()
    }

    await sleep(5000)
  }

  return null
}

/**
 * The chat refuses the next notifications (the error mode of the local receiver): the notifier tries each three times
 * and keeps it in its dead-letter queue, whose alarm notifier-failures turns to ALARM and reaches the chat once it
 * takes messages again (and the fallback topic, whose email the emulator does not send).
 */
async function notifierFailure(sqs) {
  const forced = `${PREFIX}-webhooks-dead-letters`
  const failures = `${PREFIX}-notifier-failures`
  const { QueueUrl: deadLetters } = await sqs.send(new GetQueueUrlCommand({ QueueName: `${PREFIX}-notifier-dlq` }))
  const secret = await new SecretsManagerClient({}).send(new GetSecretValueCommand({ SecretId: `dian-einvoice/${STAGE}/alerts/chat-webhook` }))
  const receiver = new URL(JSON.parse(secret.SecretString ?? '{}').url).pathname.split('/').pop()
  const started = Date.now()

  // The three attempts of the next notification answer HTTP 500.
  await fetch(`${SIMULATOR}/admin/webhook-receiver/${receiver}/mode`, { method: 'PUT', headers: { 'X-Simulator-Token': process.env.SIMULATOR_TOKEN ?? '', 'content-type': 'application/json' }, body: JSON.stringify({ mode: 'error_500', count: 3 }) })
  await cloudwatch.send(new SetAlarmStateCommand({ AlarmName: forced, StateValue: 'ALARM', StateReason: 'make cloud-alarm-check forced this state while the chat refuses its notifications.' }))
  const kept = await until(async () => await depth(sqs, deadLetters) > 0, 60)
  const alarmed = kept === null ? null : await until(async () => await state(failures) === 'ALARM', 300)
  const text = alarmed === null ? null : await message(started, [`*ALARM* ${failures}`, 'Runbook: docs/runbooks/notifier.md'], 60)
  verify(kept !== null && alarmed !== null && text !== null,
    `When the chat refuses a notification, the notifier keeps it in its dead-letter queue after its three attempts (${kept === null ? 'none within 60 s' : `after ${Math.round((kept - started) / 1000)} s`}), the emulator turns ${failures} to ALARM (${alarmed === null ? 'not within 300 s' : `after ${Math.round((alarmed - started) / 1000)} s`}), and that alarm reaches the chat, and the fallback topic of the email: ${text === null ? 'no message' : JSON.stringify(text.split('\n')[0])}.`)

  // Put back as it was: the dead-letter queue of the notifier empty, both alarms OK.
  for (let attempt = 0; attempt < 10 && await depth(sqs, deadLetters) > 0; attempt++) {
    const received = await sqs.send(new ReceiveMessageCommand({ QueueUrl: deadLetters, MaxNumberOfMessages: 10, WaitTimeSeconds: 1, VisibilityTimeout: 5 }))

    for (const found of received.Messages ?? []) {
      await sqs.send(new DeleteMessageCommand({ QueueUrl: deadLetters, ReceiptHandle: found.ReceiptHandle }))
    }
  }

  await cloudwatch.send(new SetAlarmStateCommand({ AlarmName: forced, StateValue: 'OK', StateReason: 'make cloud-alarm-check put it back.' }))
  const cleared = await until(async () => await state(failures) === 'OK', 300)
  verify(cleared !== null, `With its dead-letter queue empty again, ${failures} went back to OK by itself${cleared === null ? ': not within 300 s' : ''}.`)
}

async function followJourney() {
  const correlation = process.argv[3] ?? ''
  const hours = Number(process.argv[4] ?? 24)

  if (!new RegExp(SCHEMA.correlation.pattern).test(correlation)) {
    process.stderr.write('usage: make cloud-follow ID=<correlation-id>, the X-Correlation-Id of an answer of the API: a ULID in lower case\n')
    process.exit(2)
  }

  const definitions = (await logs.send(new DescribeQueryDefinitionsCommand({ queryDefinitionNamePrefix: `dian-einvoice/${STAGE}/` }))).queryDefinitions ?? []
  const saved = definitions.find((definition) => definition.name === `dian-einvoice/${STAGE}/follow-a-journey`)

  if (saved === undefined) {
    process.stderr.write('The saved query follow-a-journey is not in the emulator: run make cloud-up.\n')
    process.exit(1)
  }

  const rows = await query(String(saved.queryString).replace('REPLACE-WITH-THE-CORRELATION-ID', correlation), saved.logGroupNames ?? [], Date.now() - hours * 3_600_000)
  process.stdout.write(`${rows.length} lines of the journey ${correlation} in the last ${hours} hours (saved query ${saved.name}):\n`)

  for (const row of rows) {
    process.stdout.write(`${row['@timestamp'] ?? ''}  ${String(row.function ?? '').padEnd(10)} ${String(row.event ?? '').padEnd(18)} ${String(row.outcome ?? '').padEnd(9)} ${row.message ?? ''}\n`)
  }
}

/** The key of the metadata of the template that holds the saved queries (infra/cdk/lib/observability-stack.ts). */
const SAVED_QUERIES_METADATA = 'dian-einvoice:saved-queries'

/** A value of the template with its references to parameters of SSM resolved: the name of a queue, the id of the HTTP API. */
async function resolved(value, parameters, ssm) {
  if (Array.isArray(value)) {
    return Promise.all(value.map((item) => resolved(item, parameters, ssm)))
  }

  if (value !== null && typeof value === 'object') {
    if (typeof value.Ref === 'string') {
      const name = parameters[value.Ref]?.Default

      if (typeof name !== 'string') {
        throw new Error(`The template refers to ${value.Ref}, which is not a parameter of SSM.`)
      }

      return (await ssm.send(new GetParameterCommand({ Name: name }))).Parameter?.Value
    }

    return Object.fromEntries(await Promise.all(Object.entries(value).map(async ([key, item]) => [key, await resolved(item, parameters, ssm)])))
  }

  return value
}

/** The release of the emulator the two workarounds of reconcile were written against (docs/limitations.md). */
const WORKAROUND_RELEASE = '2.5.0'

/** The version of the emulator, from its health endpoint; '' when it does not say. */
async function emulatorVersion() {
  try {
    const answer = await (await fetch(new URL('/_floci/health', process.env.AWS_ENDPOINT_URL))).json()

    return typeof answer.version === 'string' ? answer.version : ''
  } catch {
    return ''
  }
}

/** Whether a version x.y.z is later than another. */
function later(version, than) {
  const parts = (text) => text.split('.').map((part) => Number.parseInt(part, 10) || 0)
  const [a, b] = [parts(version), parts(than)]

  for (let index = 0; index < 3; index++) {
    if ((a[index] ?? 0) !== (b[index] ?? 0)) {
      return (a[index] ?? 0) > (b[index] ?? 0)
    }
  }

  return false
}

/**
 * The alarms: an alarm whose metric the CloudFormation of the emulator dropped (an alarm of metric math, written as
 * queries of metric data) gets back the metric its template declares; one that holds it is left as it is, and said to
 * be kept when this step gave it before (its configuration changed after CloudFormation last created or updated it)
 * or to be as CloudFormation deployed it. Every one as CloudFormation deployed it: the emulator no longer drops them.
 */
async function reconcileAlarms(template, deployedAt, ssm) {
  const declared = Object.entries(template.Resources ?? {}).filter(([, resource]) => resource.Type === 'AWS::CloudWatch::Alarm')
  const deployed = new Map(((await cloudwatch.send(new DescribeAlarmsCommand({ AlarmNamePrefix: `${PREFIX}-`, MaxRecords: 100 }))).MetricAlarms ?? []).map((alarm) => [alarm.AlarmName, alarm]))
  const math = declared.filter(([, resource]) => Array.isArray(resource.Properties.Metrics)).length
  const given = []
  const kept = []

  for (const [logicalId, resource] of declared) {
    const alarm = resource.Properties
    const current = deployed.get(alarm.AlarmName)
    const holds = current !== undefined && (Array.isArray(alarm.Metrics)
      ? (current.Metrics ?? []).length === alarm.Metrics.length
      : current.MetricName === alarm.MetricName && (current.Dimensions ?? []).length === (alarm.Dimensions ?? []).length)

    if (holds) {
      if ((current.AlarmConfigurationUpdatedTimestamp?.getTime() ?? 0) > (deployedAt.get(logicalId) ?? 0) + 1000) {
        kept.push(alarm.AlarmName)
      }

      continue
    }

    const properties = await resolved(alarm, template.Parameters ?? {}, ssm)
    await cloudwatch.send(new PutMetricAlarmCommand({
      AlarmName: properties.AlarmName,
      AlarmDescription: properties.AlarmDescription,
      ActionsEnabled: properties.ActionsEnabled,
      AlarmActions: properties.AlarmActions,
      OKActions: properties.OKActions,
      ComparisonOperator: properties.ComparisonOperator,
      EvaluationPeriods: properties.EvaluationPeriods,
      DatapointsToAlarm: properties.DatapointsToAlarm,
      Threshold: properties.Threshold,
      TreatMissingData: properties.TreatMissingData,
      ...(Array.isArray(properties.Metrics)
        ? { Metrics: properties.Metrics }
        : { Namespace: properties.Namespace, MetricName: properties.MetricName, Dimensions: properties.Dimensions, Period: properties.Period, Unit: properties.Unit, ...(properties.ExtendedStatistic === undefined ? { Statistic: properties.Statistic } : { ExtendedStatistic: properties.ExtendedStatistic }) }),
    }))
    given.push(`${properties.AlarmName} (${Array.isArray(properties.Metrics) ? `the ${properties.Metrics.length} metrics of its expression` : `${properties.Namespace} ${properties.MetricName}`})`)
  }

  if (given.length > 0) {
    process.stdout.write(`Gave ${given.length} of the ${declared.length} alarms of the stack observability the metrics the CloudFormation of the emulator dropped from their template (docs/limitations.md): ${given.join(', ')}.\n`)
  }

  if (kept.length > 0) {
    process.stdout.write(`${given.length > 0 ? 'And kept' : 'Nothing new to give back: kept'} ${kept.length} alarms as this step gave them before, since CloudFormation last deployed them: ${kept.join(', ')}.\n`)
  }

  if (given.length === 0 && kept.length === 0) {
    process.stdout.write(math === 0
      ? `None of the ${declared.length} alarms of the stack observability is of metric math: this part of the step has nothing to do.\n`
      : `Every one of the ${declared.length} alarms of the stack observability holds its metrics as CloudFormation deployed them, the ${math} of metric math included: the emulator no longer drops them, and this part of the step (observability.mjs reconcile in make cloud-up) can be retired, with its note in docs/limitations.md.\n`)
  }
}

/** Whether two lists hold the same values, in any order. */
function same(a = [], b = []) {
  return a.length === b.length && [...a].sort().join('\n') === [...b].sort().join('\n')
}

/**
 * The saved queries the template declares in its metadata, where the stage cannot have them as resources: each one
 * missing is saved, one that differs from the template saved again under its id, one that matches left alone.
 */
async function reconcileQueries(template) {
  const declared = template.Metadata?.[SAVED_QUERIES_METADATA] ?? []
  const resources = Object.values(template.Resources ?? {}).filter((resource) => resource.Type === 'AWS::Logs::QueryDefinition').length

  if (declared.length === 0) {
    process.stdout.write(resources > 0
      ? `The template of the stack observability declares its ${resources} saved queries as resources, which CloudFormation creates: nothing to save, and this part of the step can be retired, with its note in docs/limitations.md.\n`
      : 'The template of the stack observability declares no saved query: nothing to save.\n')

    return
  }

  const existing = (await logs.send(new DescribeQueryDefinitionsCommand({ queryDefinitionNamePrefix: `dian-einvoice/${STAGE}/` }))).queryDefinitions ?? []
  const saved = []
  const updated = []
  let kept = 0

  for (const query of declared) {
    const current = existing.find((candidate) => candidate.name === query.name)

    if (current !== undefined && current.queryString === query.queryString && same(current.logGroupNames, query.logGroupNames)) {
      kept++
      continue
    }

    await logs.send(new PutQueryDefinitionCommand({ name: query.name, queryString: query.queryString, logGroupNames: query.logGroupNames, ...(current === undefined ? {} : { queryDefinitionId: current.queryDefinitionId }) }))
    ;(current === undefined ? saved : updated).push(query.name.split('/').pop())
  }

  process.stdout.write(saved.length + updated.length === 0
    ? `Nothing new to save: the ${declared.length} queries of Logs Insights the template declares in its metadata are saved as it declares them.\n`
    : `Saved the queries of Logs Insights the template declares in its metadata, which the CloudFormation of the emulator cannot create (docs/limitations.md): ${[...saved.map((name) => `${name} (new)`), ...updated.map((name) => `${name} (changed)`)].join(', ')}${kept > 0 ? `; ${kept} kept as saved before` : ''}.\n`)
}

async function reconcile() {
  const endpoint = process.env.AWS_ENDPOINT_URL ?? ''

  if (!/^http:\/\/floci:4566\/?$/.test(endpoint)) {
    process.stderr.write('observability.mjs reconcile works around the CloudFormation of the local emulator only; AWS_ENDPOINT_URL is not the emulator.\n')
    process.exit(2)
  }

  const cloudformation = new CloudFormationClient({})
  const stackName = `${PREFIX}-observability`
  const template = JSON.parse((await cloudformation.send(new GetTemplateCommand({ StackName: stackName }))).TemplateBody ?? '{}')
  // When CloudFormation last created or updated each resource of the stack, by its logical id.
  const resources = (await cloudformation.send(new DescribeStackResourcesCommand({ StackName: stackName }))).StackResources ?? []
  const deployedAt = new Map(resources.map((resource) => [resource.LogicalResourceId, resource.Timestamp?.getTime() ?? 0]))

  await reconcileAlarms(template, deployedAt, new SSMClient({}))
  await reconcileQueries(template)

  // The emulator cannot say whether its CloudFormation would now create the saved queries without a deployment: a
  // later release is the sign to try one.
  const version = await emulatorVersion()

  if (later(version, WORKAROUND_RELEASE) && (template.Metadata?.[SAVED_QUERIES_METADATA] ?? []).length > 0) {
    process.stdout.write(`The emulator is Floci ${version}, later than the ${WORKAROUND_RELEASE} whose CloudFormation could not create AWS::Logs::QueryDefinition: deploy the stage local with savedQueries 'resources' (infra/cdk/lib/stage.ts) and, if the stack observability deploys, retire the part of the saved queries.\n`)
  }
}

async function alarmsAndMessages() {
  const alarms = (await cloudwatch.send(new DescribeAlarmsCommand({ AlarmNamePrefix: `${PREFIX}-`, MaxRecords: 100 }))).MetricAlarms ?? []
  process.stdout.write(`The ${alarms.length} alarms of the stage ${STAGE}:\n`)

  for (const alarm of alarms.sort((a, b) => String(a.AlarmName).localeCompare(String(b.AlarmName)))) {
    process.stdout.write(`  ${String(alarm.StateValue).padEnd(17)} ${String(alarm.AlarmName).padEnd(46)} since ${alarm.StateUpdatedTimestamp?.toISOString() ?? '?'}  ${/Runbook: (\S+)/.exec(alarm.AlarmDescription ?? '')?.[1] ?? ''}\n`)
  }

  const messages = (await chat(0)).slice(0, 10)
  process.stdout.write(`\nThe last ${messages.length} messages the chat took (the local receiver), newest first:\n`)

  for (const body of messages) {
    let text = ''

    try {
      text = JSON.parse(body).text ?? ''
    } catch {
      text = body
    }

    process.stdout.write(`${text.split('\n').map((line, index) => `${index === 0 ? '  - ' : '    '}${line}`).join('\n')}\n`)
  }
}

const actions = { check, evaluation, reconcile, follow: followJourney, alarms: alarmsAndMessages }
const action = actions[process.argv[2] ?? '']

if (action === undefined) {
  process.stderr.write('usage: node scripts/observability.mjs check <since-ms> <correlation-id> | evaluation | reconcile | follow <correlation-id> [hours] | alarms\n')
  process.exit(2)
}

await action()
process.exit(failures === 0 ? 0 : 1)
