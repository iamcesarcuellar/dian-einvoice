// The operation commands of the runbooks (docs/runbooks/) that act on AWS rather than on the application, against the
// stage of STAGE: the emulator for make (the local stage); a real account takes the AWS CLI commands each runbook gives next
// to them. Each one says what it did, never the body of a message or the value of a secret.
//
//   node scripts/operations.mjs dead-letters <chain|webhooks|notifier>
//       the messages of a dead-letter queue, without consuming them: when each came, how many times it was received,
//       its type, company, document and journey, and why its job gave up; each is visible again at once
//   node scripts/operations.mjs redrive <chain|webhooks>
//       moves every message of the dead-letter queue back to its queue, with the native redrive of SQS
//       (StartMessageMoveTask), and waits for the task to end
//   node scripts/operations.mjs purge-notifier
//       empties the dead-letter queue of the notifier, once its notifications were read: they have no queue to go back
//       to, and the state of the alarms is what counts (make cloud-alarms)
//   node scripts/operations.mjs errors [function] [minutes]
//       the lines of error each function wrote in the last minutes, counted, with the last of each: the saved query
//       errors-by-function, which needs stats, written with what the emulator evaluates
//   node scripts/operations.mjs functions
//       each function with its image, reserved concurrency and state, the mappings of the queues, the schedules, and
//       the concurrency of the account
//   node scripts/operations.mjs rotate-edge
//       a new value of the secret of the edge, out of schedule; the one it replaces stays valid as the previous version
//   node scripts/operations.mjs chat-webhook <url>
//       a new address of the chat webhook of the notifier
//   node scripts/operations.mjs rotate-migrations
//       a new password in the secret of the migrations identity of the local stage, which make then sets on the user, as
//       the single-user rotation of AWS does
import { randomInt } from 'node:crypto'
import { CloudWatchLogsClient, DescribeLogGroupsCommand, FilterLogEventsCommand } from '@aws-sdk/client-cloudwatch-logs'
import { GetAccountSettingsCommand, GetFunctionCommand, LambdaClient, ListEventSourceMappingsCommand } from '@aws-sdk/client-lambda'
import { GetScheduleCommand, SchedulerClient } from '@aws-sdk/client-scheduler'
import { GetSecretValueCommand, PutSecretValueCommand, SecretsManagerClient } from '@aws-sdk/client-secrets-manager'
import { ChangeMessageVisibilityBatchCommand, GetQueueAttributesCommand, GetQueueUrlCommand, ListMessageMoveTasksCommand, PurgeQueueCommand, ReceiveMessageCommand, SQSClient, StartMessageMoveTaskCommand } from '@aws-sdk/client-sqs'

const STAGE = process.env.STAGE ?? 'local'
const PREFIX = `dian-einvoice-${STAGE}`
const FUNCTIONS = ['api', 'chain', 'webhooks', 'relay', 'scheduler', 'console', 'origin-verifier', 'notifier']
const sqs = new SQSClient({})
const sleep = (milliseconds) => new Promise((resolve) => { setTimeout(resolve, milliseconds) })

/** The URL and the ARN of a queue of the stage. */
async function queue(name) {
  const { QueueUrl: url } = await sqs.send(new GetQueueUrlCommand({ QueueName: `${PREFIX}-${name}` }))
  const attributes = (await sqs.send(new GetQueueAttributesCommand({ QueueUrl: url, AttributeNames: ['QueueArn', 'ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible'] }))).Attributes ?? {}

  return { url, arn: attributes.QueueArn, visible: Number(attributes.ApproximateNumberOfMessages ?? 0), inFlight: Number(attributes.ApproximateNumberOfMessagesNotVisible ?? 0) }
}

function channelOf(name) {
  if (!['chain', 'webhooks', 'notifier'].includes(name ?? '')) {
    process.stderr.write('The dead-letter queue is chain, webhooks or notifier.\n')
    process.exit(2)
  }

  return name
}

/** What a message of a dead-letter queue is about, from its attributes and the fields of its body, never the body. */
function describe(message, channel) {
  const attribute = (name) => message.MessageAttributes?.[name]?.StringValue
  let body = null

  try {
    body = JSON.parse(message.Body ?? '')
  } catch {
    body = null
  }

  if (channel === 'notifier') {
    // A notification of SNS, as CloudWatch published it.
    let alarm = null

    try {
      alarm = JSON.parse(body?.Records?.[0]?.Sns?.Message ?? body?.Message ?? '')
    } catch {
      alarm = null
    }

    return `alarm ${alarm?.AlarmName ?? '?'} ${alarm?.OldStateValue ?? '?'} -> ${alarm?.NewStateValue ?? '?'} at ${alarm?.StateChangeTime ?? '?'}`
  }

  const type = attribute('type') ?? (typeof body?.displayName === 'string' ? `job ${body.displayName.split('\\').pop()}` : 'a message without a type')
  const documentId = body?.payload?.document_id ?? body?.aggregate_id ?? null

  return [
    type,
    attribute('company_id') === undefined ? null : `company ${attribute('company_id')}`,
    documentId === null ? null : `document ${documentId}`,
    attribute('correlation_id') === undefined ? null : `journey ${attribute('correlation_id')}`,
    typeof body?.payload?.attempt === 'number' ? `attempt ${body.payload.attempt}` : null,
  ].filter((part) => part !== null).join(', ') + (attribute('dead_letter_reason') === undefined ? '' : `\n      why: ${attribute('dead_letter_reason')}`)
}

async function deadLetters(name) {
  const channel = channelOf(name)
  const dlq = await queue(`${channel}-dlq`)
  process.stdout.write(`${dlq.visible + dlq.inFlight} messages in ${PREFIX}-${channel}-dlq (${dlq.visible} visible, ${dlq.inFlight} in flight):\n`)
  const seen = new Map()

  // Received for a few seconds so each is listed once, then visible again at once: nothing is consumed.
  for (let round = 0; round < 10 && seen.size < dlq.visible; round++) {
    const { Messages: messages = [] } = await sqs.send(new ReceiveMessageCommand({ QueueUrl: dlq.url, MaxNumberOfMessages: 10, VisibilityTimeout: 20, WaitTimeSeconds: 1, MessageSystemAttributeNames: ['All'], MessageAttributeNames: ['All'] }))

    if (messages.length === 0) {
      break
    }

    for (const message of messages) {
      seen.set(message.MessageId, message)
    }

    await sqs.send(new ChangeMessageVisibilityBatchCommand({ QueueUrl: dlq.url, Entries: messages.map((message, index) => ({ Id: `m${index}`, ReceiptHandle: message.ReceiptHandle, VisibilityTimeout: 0 })) }))
  }

  for (const message of [...seen.values()].sort((a, b) => Number(a.Attributes?.SentTimestamp ?? 0) - Number(b.Attributes?.SentTimestamp ?? 0))) {
    process.stdout.write(`  ${new Date(Number(message.Attributes?.SentTimestamp ?? 0)).toISOString()}  received ${message.Attributes?.ApproximateReceiveCount ?? '?'} times  ${describe(message, channel)}\n`)
  }

  if (seen.size > 0 && channel !== 'notifier') {
    process.stdout.write(`Once the cause is fixed: make cloud-redrive QUEUE=${channel} (docs/runbooks/dead-letters.md).\n`)
  }
}

async function redrive(name) {
  const channel = channelOf(name)

  if (channel === 'notifier') {
    process.stderr.write('The notifications of the notifier have no queue to go back to: read them, then make cloud-dead-letters-purge (docs/runbooks/notifier.md).\n')
    process.exit(2)
  }

  const source = await queue(`${channel}-dlq`)
  const destination = await queue(channel)
  const waiting = source.visible + source.inFlight

  if (waiting === 0) {
    process.stdout.write(`${PREFIX}-${channel}-dlq is empty: nothing to move back.\n`)

    return
  }

  // The native redrive of SQS, to the queue of the channel by its ARN: every message of the dead-letter queue, whether
  // its redrive policy moved it or its job copied it there when it gave up.
  const { TaskHandle: task } = await sqs.send(new StartMessageMoveTaskCommand({ SourceArn: source.arn, DestinationArn: destination.arn }))
  let status = null

  for (let waited = 0; waited < 120; waited++) {
    const { Results: results = [] } = await sqs.send(new ListMessageMoveTasksCommand({ SourceArn: source.arn, MaxResults: 10 }))
    status = results.find((result) => result.TaskHandle === task) ?? results[0] ?? null

    if (status !== null && status.Status !== 'RUNNING') {
      break
    }

    await sleep(1000)
  }

  process.stdout.write(`Moved ${status?.ApproximateNumberOfMessagesMoved ?? '?'} of ${status?.ApproximateNumberOfMessagesToMove ?? waiting} messages of ${PREFIX}-${channel}-dlq back to ${PREFIX}-${channel} (task ${status?.Status ?? 'unknown'}${status?.FailureReason ? `: ${status.FailureReason}` : ''}): their steps start again.\n`)

  if (status?.Status !== 'COMPLETED') {
    process.exit(1)
  }
}

async function purgeNotifier() {
  const dlq = await queue('notifier-dlq')
  await sqs.send(new PurgeQueueCommand({ QueueUrl: dlq.url }))
  process.stdout.write(`Emptied ${PREFIX}-notifier-dlq (${dlq.visible + dlq.inFlight} notifications); the stage's alarms say their current state (make cloud-alarms).\n`)
}

async function errors(only, minutes) {
  const logs = new CloudWatchLogsClient({})
  const since = Date.now() - minutes * 60_000
  const functions = only === undefined || only === 'all' ? FUNCTIONS : [only]
  const groups = new Set(((await logs.send(new DescribeLogGroupsCommand({ logGroupNamePrefix: `/aws/lambda/${PREFIX}-` }))).logGroups ?? []).map((group) => group.logGroupName))
  process.stdout.write(`The error lines of the ${STAGE} stage's functions in the last ${minutes} minutes (level error or worse), worst first:\n`)
  const rows = []

  for (const fn of functions) {
    const group = `/aws/lambda/${PREFIX}-${fn}`

    if (!groups.has(group)) {
      continue
    }

    const found = []
    let token

    do {
      const page = await logs.send(new FilterLogEventsCommand({ logGroupName: group, startTime: since, nextToken: token }))

      for (const event of page.events ?? []) {
        try {
          const line = JSON.parse(event.message ?? '')

          if (['error', 'critical', 'alert', 'emergency'].includes(line.level)) {
            found.push(line)
          }
        } catch {
          // A line outside the schema (a runtime the emulator stopped) is not an error of the function.
        }
      }

      token = page.nextToken
    } while (token !== undefined)

    rows.push([fn, found])
  }

  for (const [fn, found] of rows.sort((a, b) => b[1].length - a[1].length)) {
    process.stdout.write(`  ${fn.padEnd(16)} ${found.length}\n`)

    for (const line of found.slice(-3)) {
      process.stdout.write(`      ${line.time}  ${line.event ?? 'no event'}  ${line.message}${line.correlation_id === null ? '' : `  (journey ${line.correlation_id})`}\n`)
    }
  }
}

async function functionsState() {
  const lambda = new LambdaClient({})
  process.stdout.write(`The functions of ${STAGE}:\n`)

  for (const fn of FUNCTIONS) {
    const found = await lambda.send(new GetFunctionCommand({ FunctionName: `${PREFIX}-${fn}` }))
    process.stdout.write(`  ${fn.padEnd(16)} ${found.Configuration?.State ?? '?'}, ${found.Code?.ImageUri?.split('/').pop() ?? `${found.Configuration?.Runtime} zip`}, reserved concurrency ${found.Concurrency?.ReservedConcurrentExecutions ?? 'none'}, ${found.Configuration?.MemorySize} MB, ${found.Configuration?.Timeout} s\n`)
  }

  const { EventSourceMappings: mappings = [] } = await lambda.send(new ListEventSourceMappingsCommand({}))

  for (const mapping of mappings.filter((candidate) => String(candidate.FunctionArn).includes(`:function:${PREFIX}-`))) {
    process.stdout.write(`  mapping ${String(mapping.EventSourceArn).split(':').pop()} -> ${String(mapping.FunctionArn).split(':').pop()}: ${mapping.State}, at most ${mapping.ScalingConfig?.MaximumConcurrency ?? '?'} at once\n`)
  }

  const scheduler = new SchedulerClient({})

  for (const fn of ['relay', 'scheduler']) {
    const schedule = await scheduler.send(new GetScheduleCommand({ Name: `${PREFIX}-${fn}` }))
    process.stdout.write(`  schedule ${PREFIX}-${fn}: ${schedule.State}, ${schedule.ScheduleExpression}\n`)
  }

  try {
    const account = await lambda.send(new GetAccountSettingsCommand({}))
    process.stdout.write(`  the account: ${account.AccountLimit?.ConcurrentExecutions ?? '?'} concurrent executions, ${account.AccountLimit?.UnreservedConcurrentExecutions ?? '?'} of them unreserved\n`)
  } catch (error) {
    process.stdout.write(`  the account: ${error.name}\n`)
  }
}

/** 64 letters and digits, as Secrets Manager generates the secret of the edge. */
function randomValue() {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'

  return Array.from({ length: 64 }, () => alphabet[randomInt(alphabet.length)]).join('')
}

async function rotateEdge() {
  const { VersionId: version } = await new SecretsManagerClient({}).send(new PutSecretValueCommand({ SecretId: `dian-einvoice/${STAGE}/edge/origin-verify`, SecretString: randomValue() }))
  process.stdout.write(`A new value of dian-einvoice/${STAGE}/edge/origin-verify, version ${version}; the one it replaced stays valid as the previous version until the next rotation.\n`)
}

async function chatWebhook(url) {
  if (url === undefined || !/^https?:\/\/\S+$/.test(url)) {
    process.stderr.write('usage: node scripts/operations.mjs chat-webhook <url>\n')
    process.exit(2)
  }

  await new SecretsManagerClient({}).send(new PutSecretValueCommand({ SecretId: `dian-einvoice/${STAGE}/alerts/chat-webhook`, SecretString: JSON.stringify({ url }) }))
  process.stdout.write(`A new chat webhook address in dian-einvoice/${STAGE}/alerts/chat-webhook (to ${new URL(url).host}); the notifier reads it within 5 minutes.\n`)
}

async function rotateMigrations() {
  if (STAGE !== 'local') {
    process.stderr.write("In AWS the secret's rotation function does this: aws secretsmanager rotate-secret (docs/runbooks/rotate-secrets.md).\n")
    process.exit(2)
  }

  const secrets = new SecretsManagerClient({})
  const id = `dian-einvoice/${STAGE}/database/migrations`
  const current = JSON.parse((await secrets.send(new GetSecretValueCommand({ SecretId: id }))).SecretString ?? '{}')
  const { VersionId: version } = await secrets.send(new PutSecretValueCommand({ SecretId: id, SecretString: JSON.stringify({ ...current, password: randomValue() }) }))
  process.stdout.write(`A new password in ${id}, version ${version}.\n`)
}

const [action, argument, extra] = process.argv.slice(2)
const actions = {
  'dead-letters': () => deadLetters(argument),
  redrive: () => redrive(argument),
  'purge-notifier': purgeNotifier,
  errors: () => errors(argument, Math.max(1, Number(extra ?? 60) || 60)),
  functions: functionsState,
  'rotate-edge': rotateEdge,
  'chat-webhook': () => chatWebhook(argument),
  'rotate-migrations': rotateMigrations,
}

if (actions[action ?? ''] === undefined) {
  process.stderr.write('usage: node scripts/operations.mjs dead-letters <queue>|redrive <queue>|purge-notifier|errors [function] [minutes]|functions|rotate-edge|chat-webhook <url>|rotate-migrations\n')
  process.exit(2)
}

await actions[action]()
