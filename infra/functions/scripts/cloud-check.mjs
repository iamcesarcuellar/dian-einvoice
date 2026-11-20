// The checks of make cloud-check (scripts/cloud-check.sh) that read the local AWS emulator itself:
//
//   node scripts/cloud-check.mjs resources   every function, mapping, route and schedule of the stage local exists
//                                            for real, as its template declares it (the emulator could have
//                                            accepted a resource it does not create), and each function connects
//                                            to the database as its identity, over TLS
//   node scripts/cloud-check.mjs cold        the time of one request to the API that starts a new execution
//                                            environment (after the script removed the warm ones)
//   node scripts/cloud-check.mjs warm        the times of requests answered by a warm environment
//
// The times are those of the emulator on this machine, through the proxy in front of API Gateway, not of AWS.
import { ApiGatewayV2Client, GetIntegrationsCommand, GetRoutesCommand } from '@aws-sdk/client-apigatewayv2'
import { CloudFormationClient, DescribeStacksCommand, GetTemplateCommand } from '@aws-sdk/client-cloudformation'
import { GetFunctionCommand, LambdaClient, ListEventSourceMappingsCommand } from '@aws-sdk/client-lambda'
import { GetScheduleCommand, SchedulerClient } from '@aws-sdk/client-scheduler'
import { DescribeSecretCommand, SecretsManagerClient } from '@aws-sdk/client-secrets-manager'

const STAGE = 'local'
const STACK = `dian-einvoice-${STAGE}-functions`
const API = process.env.API_CHECK_HEALTH ?? 'http://api:8000/api/v1/health'
let failures = 0

function verify(passed, message) {
  process.stdout.write(`   ${passed ? 'PASS' : 'FAIL'}  ${message}\n`)
  failures += passed ? 0 : 1
}

/** The template of the stack as CloudFormation deployed it. */
async function template(cloudformation) {
  const { TemplateBody: body } = await cloudformation.send(new GetTemplateCommand({ StackName: STACK, TemplateStage: 'Processed' }))

  return JSON.parse(body)
}

async function resources() {
  const cloudformation = new CloudFormationClient({})
  const declared = (await template(cloudformation)).Resources
  const lambda = new LambdaClient({})
  const outputs = (await cloudformation.send(new DescribeStacksCommand({ StackName: STACK }))).Stacks?.[0]?.Outputs ?? []
  const functions = Object.values(declared).filter((resource) => resource.Type === 'AWS::Lambda::Function')
  const roles = new Set()

  for (const declaredFunction of functions) {
    const name = declaredFunction.Properties.FunctionName
    const found = await lambda.send(new GetFunctionCommand({ FunctionName: name }))
    const configuration = found.Configuration ?? {}
    roles.add(configuration.Role)
    verify(
      configuration.PackageType === 'Image'
        && configuration.MemorySize === declaredFunction.Properties.MemorySize
        && configuration.Timeout === declaredFunction.Properties.Timeout
        && found.Concurrency?.ReservedConcurrentExecutions === declaredFunction.Properties.ReservedConcurrentExecutions
        && typeof configuration.Role === 'string' && configuration.Role.endsWith(`:role/${name}-function`)
        && found.Code?.ImageUri?.includes('@sha256:') === true,
      `The function ${name} exists: image ${found.Code?.ImageUri?.split('/').pop()}, ${configuration.MemorySize} MB, ${configuration.Timeout} s, reserved concurrency ${found.Concurrency?.ReservedConcurrentExecutions}, role ${configuration.Role?.split('/').pop()}.`,
    )
  }

  verify(roles.size === functions.length, `Each of the ${functions.length} functions has a role of its own (${roles.size} roles).`)

  // The identity of the database of each function: the migrations identity for the console only, which also
  // knows the application identity it provisions; the application identity for every other one; every
  // connection encrypted with the RDS bundle of the image.
  const secrets = new SecretsManagerClient({})
  const arnOf = async (identity) => (await secrets.send(new DescribeSecretCommand({ SecretId: `dian-einvoice/${STAGE}/database/${identity}` }))).ARN
  const migrationsSecret = await arnOf('migrations')
  const applicationSecret = await arnOf('application')
  const identities = []

  for (const declaredFunction of functions) {
    const name = declaredFunction.Properties.FunctionName
    const environment = (await lambda.send(new GetFunctionCommand({ FunctionName: name }))).Configuration?.Environment?.Variables ?? {}
    const migrations = name.endsWith('-console')
    const right = environment.DB_SECRETS_ID === (migrations ? migrationsSecret : applicationSecret)
      && (environment.DB_APPLICATION_SECRETS_ID ?? null) === (migrations ? applicationSecret : null)
      && environment.DB_SSL_CA === '/opt/rds/global-bundle.pem'
    identities.push(`${name.replace(`dian-einvoice-${STAGE}-`, '')} ${right ? (migrations ? 'migrations' : 'application') : 'WRONG'}`)
    failures += right ? 0 : 1
  }

  process.stdout.write(`   ${identities.some((line) => line.endsWith('WRONG')) ? 'FAIL' : 'PASS'}  Each function connects to the database as its identity, over TLS with the RDS bundle of the image: ${identities.join(', ')}.\n`)

  // The consumers are mappings of their queues, not processes that poll.
  const { EventSourceMappings: mappings = [] } = await lambda.send(new ListEventSourceMappingsCommand({}))

  for (const [channel, fn] of [['chain', 'chain'], ['webhooks', 'webhooks']]) {
    const mapping = mappings.find((candidate) => candidate.EventSourceArn?.endsWith(`:dian-einvoice-${STAGE}-${channel}`))
    verify(
      mapping !== undefined && mapping.FunctionArn?.endsWith(`:function:dian-einvoice-${STAGE}-${fn}`) === true && mapping.State === 'Enabled'
        && mapping.FunctionResponseTypes?.includes('ReportBatchItemFailures') === true && mapping.BatchSize === 10,
      `The ${channel} queue is a mapping of the function dian-einvoice-${STAGE}-${fn}: ${mapping?.State ?? 'missing'}, batches of ${mapping?.BatchSize}, ${mapping?.FunctionResponseTypes?.join(', ') ?? 'no'} partial batch response.`,
    )
  }

  // The API is API Gateway, whose only route sends every request to the function api.
  const apiId = outputs.find((output) => output.OutputKey === 'HttpApiId')?.OutputValue
  const gateway = new ApiGatewayV2Client({})
  const routes = apiId === undefined ? [] : (await gateway.send(new GetRoutesCommand({ ApiId: apiId }))).Items ?? []
  const integrations = apiId === undefined ? [] : (await gateway.send(new GetIntegrationsCommand({ ApiId: apiId }))).Items ?? []
  const integration = integrations.find((candidate) => routes.some((route) => route.Target === `integrations/${candidate.IntegrationId}`))
  verify(
    routes.length === 1 && routes[0].RouteKey === '$default' && integration?.IntegrationType === 'AWS_PROXY' && integration.PayloadFormatVersion === '2.0'
      && String(integration.IntegrationUri).includes(`function:dian-einvoice-${STAGE}-api`),
    `API Gateway ${apiId}: route ${routes.map((route) => route.RouteKey).join(', ')} to the function api (${integration?.IntegrationType}, payload ${integration?.PayloadFormatVersion}).`,
  )

  const scheduler = new SchedulerClient({})

  for (const fn of ['relay', 'scheduler']) {
    const schedule = await scheduler.send(new GetScheduleCommand({ Name: `dian-einvoice-${STAGE}-${fn}` }))
    verify(
      schedule.State === 'ENABLED' && schedule.ScheduleExpression === 'rate(1 minute)' && schedule.Target?.Arn?.endsWith(`:function:dian-einvoice-${STAGE}-${fn}`) === true
        && schedule.Target?.RetryPolicy?.MaximumRetryAttempts === 0,
      `The schedule dian-einvoice-${STAGE}-${fn} invokes its function ${schedule.ScheduleExpression}, ${schedule.State}, ${schedule.Target?.RetryPolicy?.MaximumRetryAttempts} retries.`,
    )
  }
}

async function time() {
  const started = performance.now()
  const response = await fetch(API, { headers: { Accept: 'application/json' } })
  await response.arrayBuffer()

  return { ms: performance.now() - started, status: response.status, gateway: response.headers.has('x-amzn-requestid') || response.headers.has('apigw-requestid') }
}

async function cold() {
  const answer = await time()
  verify(answer.status === 200 && answer.gateway, `Cold start of the API through API Gateway: ${(answer.ms / 1000).toFixed(2)} s for GET /api/v1/health on a new execution environment (HTTP ${answer.status}; a figure of the emulator, not of AWS).`)
}

async function warm() {
  const times = []

  for (let request = 0; request < 10; request++) {
    const answer = await time()

    if (answer.status !== 200 || !answer.gateway) {
      verify(false, `A warm request answered HTTP ${answer.status}.`)

      return
    }

    times.push(answer.ms)
  }

  times.sort((a, b) => a - b)
  verify(true, `Warm answers of the API through API Gateway: median ${times[4].toFixed(0)} ms, slowest ${times[9].toFixed(0)} ms over 10 requests of GET /api/v1/health (figures of the emulator, not of AWS).`)
}

const actions = { resources, cold, warm }
const action = actions[process.argv[2] ?? '']

if (action === undefined) {
  process.stderr.write('usage: node scripts/cloud-check.mjs resources|cold|warm\n')
  process.exit(2)
}

await action()
process.exit(failures === 0 ? 0 : 1)
