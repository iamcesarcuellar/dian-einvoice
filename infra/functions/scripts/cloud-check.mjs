// The checks of make cloud-check (scripts/cloud-check.sh) that read the local AWS emulator itself:
//
//   node scripts/cloud-check.mjs resources   every function, mapping, route and schedule of the stage local exists
//                                            for real, as its template declares it (the emulator could have
//                                            accepted a resource it does not create), and each function connects
//                                            to the database as its identity, over TLS
//   node scripts/cloud-check.mjs edge        the edge as a browser and an API client reach it, through the proxy in
//                                            front of the distribution: API Gateway refuses a request that did not
//                                            come through it; the web app comes from the bucket with every header
//                                            of backend/resources/http/security-headers.json; its routes are
//                                            answered with index.html, the cases of the CloudFront Function; and
//                                            the errors of the API stay problems of the API
//   node scripts/cloud-check.mjs cold        the time of one request to the API that starts a new execution
//                                            environment (after the script removed the warm ones)
//   node scripts/cloud-check.mjs warm        the times of requests answered by a warm environment
//
// The times are those of the emulator on this machine, through the edge, not of AWS.
import { readFileSync } from 'node:fs'
import { request as httpRequest } from 'node:http'
import { ApiGatewayV2Client, GetAuthorizersCommand, GetIntegrationsCommand, GetRoutesCommand } from '@aws-sdk/client-apigatewayv2'
import { CloudFormationClient, DescribeStacksCommand, GetTemplateCommand } from '@aws-sdk/client-cloudformation'
import { GetFunctionCommand, LambdaClient, ListEventSourceMappingsCommand } from '@aws-sdk/client-lambda'
import { GetScheduleCommand, SchedulerClient } from '@aws-sdk/client-scheduler'
import { DescribeSecretCommand, SecretsManagerClient } from '@aws-sdk/client-secrets-manager'
import { GetParameterCommand, SSMClient } from '@aws-sdk/client-ssm'

const STAGE = 'local'
const STACK = `dian-einvoice-${STAGE}-functions`
// The proxy in front of the edge, as 127.0.0.1:8600 and 127.0.0.1:8601 are on the host.
const EDGE = process.env.EDGE_CHECK_URL ?? 'http://api:8000'
const API = `${EDGE}/api/v1/health`
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

    if (name.endsWith('-origin-verifier')) {
      verify(
        configuration.PackageType === 'Zip' && configuration.Runtime === declaredFunction.Properties.Runtime && configuration.MemorySize === declaredFunction.Properties.MemorySize
          && found.Concurrency?.ReservedConcurrentExecutions === declaredFunction.Properties.ReservedConcurrentExecutions && typeof configuration.Role === 'string' && configuration.Role.endsWith(`:role/${name}-function`)
          && (configuration.VpcConfig?.SubnetIds ?? []).length === 0,
        `The function ${name} exists: a zip of ${configuration.Runtime}, ${configuration.MemorySize} MB, ${configuration.Timeout} s, reserved concurrency ${found.Concurrency?.ReservedConcurrentExecutions}, role ${configuration.Role?.split('/').pop()}, outside any VPC.`,
      )
      continue
    }

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

  for (const declaredFunction of functions.filter((candidate) => !candidate.Properties.FunctionName.endsWith('-origin-verifier'))) {
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

  // The API is API Gateway, whose only route sends every request to the function api, behind the origin verifier.
  const apiId = outputs.find((output) => output.OutputKey === 'HttpApiId')?.OutputValue
  const gateway = new ApiGatewayV2Client({})
  const routes = apiId === undefined ? [] : (await gateway.send(new GetRoutesCommand({ ApiId: apiId }))).Items ?? []
  const integrations = apiId === undefined ? [] : (await gateway.send(new GetIntegrationsCommand({ ApiId: apiId }))).Items ?? []
  const authorizers = apiId === undefined ? [] : (await gateway.send(new GetAuthorizersCommand({ ApiId: apiId }))).Items ?? []
  const integration = integrations.find((candidate) => routes.some((route) => route.Target === `integrations/${candidate.IntegrationId}`))
  const authorizer = authorizers.find((candidate) => candidate.AuthorizerId === routes[0]?.AuthorizerId)
  verify(
    routes.length === 1 && routes[0].RouteKey === '$default' && integration?.IntegrationType === 'AWS_PROXY' && integration.PayloadFormatVersion === '2.0'
      && String(integration.IntegrationUri).includes(`function:dian-einvoice-${STAGE}-api`)
      && routes[0].AuthorizationType === 'CUSTOM' && authorizer?.AuthorizerType === 'REQUEST' && authorizer.EnableSimpleResponses === true && authorizer.AuthorizerResultTtlInSeconds === 300
      && String(authorizer.AuthorizerUri).includes(`function:dian-einvoice-${STAGE}-origin-verifier`) && (authorizer.IdentitySource ?? []).includes('$request.header.X-Origin-Verify'),
    `API Gateway ${apiId}: route ${routes.map((route) => route.RouteKey).join(', ')} to the function api (${integration?.IntegrationType}, payload ${integration?.PayloadFormatVersion}), behind the ${authorizer?.AuthorizerType} authorizer ${authorizer?.Name} of the function origin-verifier on ${authorizer?.IdentitySource?.join(', ')}, its answer kept ${authorizer?.AuthorizerResultTtlInSeconds} s.`,
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

/** One request with a host of its own (the emulator answers API Gateway and CloudFront by host name), with its headers and body. */
function call(url, { host, headers = {}, method = 'GET', body } = {}) {
  return new Promise((resolve, reject) => {
    const target = new URL(url)
    const sent = httpRequest({ hostname: target.hostname, port: target.port, path: `${target.pathname}${target.search}`, method, headers: { ...headers, ...(host === undefined ? {} : { host }) } }, (response) => {
      const chunks = []
      response.on('data', (chunk) => chunks.push(chunk))
      response.on('end', () => resolve({ status: response.statusCode, headers: response.headers, body: Buffer.concat(chunks).toString('utf8') }))
    })
    sent.on('error', reject)
    sent.end(body)
  })
}

/** The headers of a document of security-headers.json, as the edge or the application must answer them. */
function expected(headers, document, extra) {
  const policy = Object.entries(headers.documents[document].contentSecurityPolicy).map(([directive, sources]) => `${directive} ${sources}`).join('; ')

  return { ...extra, 'content-security-policy': policy, ...Object.fromEntries(Object.entries(headers.documents[document].headers).map(([name, value]) => [name.toLowerCase(), value])) }
}

function differs(answer, wanted) {
  return Object.entries(wanted).filter(([name, value]) => answer.headers[name] !== value).map(([name]) => name)
}

async function edge() {
  const headers = JSON.parse(readFileSync('/workspace/http/security-headers.json', 'utf8'))
  const { cases } = JSON.parse(readFileSync('/workspace/edge/panel-routes.cases.json', 'utf8'))
  const ssm = new SSMClient({})
  const value = async (name) => (await ssm.send(new GetParameterCommand({ Name: `/dian-einvoice/${STAGE}/${name}` }))).Parameter?.Value
  const apiId = await value('functions/http-api-id')
  const common = Object.fromEntries(Object.entries(headers.common).map(([name, header]) => [name.toLowerCase(), header]))

  // API Gateway, called directly as a client that skips the edge would: refused before the application, which sets
  // an X-Request-Id on every answer of its own.
  const direct = (extra) => call('http://floci:4566/api/v1/health', { host: `${apiId}.execute-api.localhost.floci.io:4566`, headers: { accept: 'application/json', ...extra } })
  const missing = await direct({})
  const wrong = await direct({ 'x-origin-verify': 'a-value-a-client-made-up-0000000000000000000000000000000000000000' })
  verify(missing.status === 401 && wrong.status === 403 && missing.headers['x-request-id'] === undefined && wrong.headers['x-request-id'] === undefined,
    `API Gateway refuses whoever skips the edge, before the application: without X-Origin-Verify ${missing.status} ${missing.body.trim()}, with a value of its own ${wrong.status} ${wrong.body.trim()}.`)

  // Through the edge the API answers with the headers of the application only.
  const health = await call(API, { headers: { accept: 'application/json' } })
  const apiHeaders = expected(headers, 'api', { 'x-content-type-options': common['x-content-type-options'], 'x-frame-options': common['x-frame-options'] })
  verify(health.status === 200 && health.headers['x-request-id'] !== undefined && differs(health, apiHeaders).length === 0 && health.headers['permissions-policy'] === undefined,
    `Through the edge the API answers (HTTP ${health.status}, X-Request-Id ${health.headers['x-request-id']}) with the headers of the application, none added by the edge${differs(health, apiHeaders).length === 0 ? '' : `; different: ${differs(health, apiHeaders).join(', ')}`}.`)

  // The web app from the bucket, with every header of the panel; index.html revalidated, its files cached a year.
  const index = await call(`${EDGE}/`)
  const panelHeaders = expected(headers, 'panel', common)
  const script = /<script type="module" crossorigin src="(\/assets\/[^"]+\.js)"/.exec(index.body)?.[1]
  const asset = script === undefined ? undefined : await call(`${EDGE}${script}`)
  verify(index.status === 200 && index.body.includes('<div id="app">') && differs(index, panelHeaders).length === 0 && index.headers['cache-control'] === 'no-cache' && index.headers['x-amz-request-id'] !== undefined,
    `The web app comes from the bucket of the edge (HTTP ${index.status}, Cache-Control ${index.headers['cache-control']}) with the ${Object.keys(panelHeaders).length} headers of security-headers.json${differs(index, panelHeaders).length === 0 ? '' : `; different: ${differs(index, panelHeaders).join(', ')}`}.`)
  verify(asset?.status === 200 && asset.headers['cache-control'] === 'public, max-age=31536000, immutable' && differs(asset, panelHeaders).length === 0,
    `Its fingerprinted files are cached a year with the same headers: ${script} (HTTP ${asset?.status}, Cache-Control ${asset?.headers['cache-control']}).`)

  // The routes of the web app answer index.html, files keep their path: the cases of the CloudFront Function.
  const wrongCases = []

  for (const { uri, rewritten } of cases) {
    const answer = await call(`${EDGE}${uri}`)
    const isIndex = answer.status === 200 && answer.body === index.body

    if (isIndex !== (rewritten === '/index.html')) {
      wrongCases.push(`${uri} (HTTP ${answer.status})`)
    }
  }

  verify(wrongCases.length === 0, `A deep link of the web app answers index.html and a file keeps its path: the ${cases.length} cases of the CloudFront Function${wrongCases.length === 0 ? '' : `; wrong: ${wrongCases.join(', ')}`}.`)

  // The errors of the API stay problems of the API, never the web app.
  const notFound = await call(`${EDGE}/api/v1/nothing-lives-here`, { headers: { accept: 'application/json' } })
  const unauthenticated = await call(`${EDGE}/api/v1/invoices`, { headers: { accept: 'application/json' } })
  verify([notFound, unauthenticated].every((answer) => String(answer.headers['content-type']).startsWith('application/problem+json') && !answer.body.includes('<div id="app">')) && notFound.status === 404 && unauthenticated.status === 401,
    `Through the edge the errors of the API stay problems of the API: ${notFound.status} and ${unauthenticated.status} in ${notFound.headers['content-type']}.`)
}

async function time() {
  const started = performance.now()
  const response = await fetch(API, { headers: { Accept: 'application/json' } })
  await response.arrayBuffer()

  return { ms: performance.now() - started, status: response.status, gateway: response.headers.has('x-amzn-requestid') || response.headers.has('apigw-requestid') }
}

async function cold() {
  const answer = await time()
  verify(answer.status === 200 && answer.gateway, `Cold start of the API through the edge and API Gateway: ${(answer.ms / 1000).toFixed(2)} s for GET /api/v1/health on a new execution environment (HTTP ${answer.status}; a figure of the emulator, not of AWS).`)
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
  verify(true, `Warm answers of the API through the edge and API Gateway: median ${times[4].toFixed(0)} ms, slowest ${times[9].toFixed(0)} ms over 10 requests of GET /api/v1/health (figures of the emulator, not of AWS).`)
}

const actions = { resources, edge, cold, warm }
const action = actions[process.argv[2] ?? '']

if (action === undefined) {
  process.stderr.write('usage: node scripts/cloud-check.mjs resources|edge|cold|warm\n')
  process.exit(2)
}

await action()
process.exit(failures === 0 ? 0 : 1)
