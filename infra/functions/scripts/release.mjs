// The steps of scripts/deploy.sh that read or check a stage through the AWS API, the same for the emulator (the local stage,
// make cloud-up) and for a real account (the prod stage, .github/workflows/deploy.yml, never run):
//
//   node scripts/release.mjs registry-password       the password docker login takes for the ECR registry of the account
//                                                    (prod), piped, never printed to a terminal
//   node scripts/release.mjs image-digest <tag>      the digest of the image of the functions under a tag, or nothing:
//                                                    the tags of prod never move, so a tag pushed before is reused
//   node scripts/release.mjs current-image           the image the function api runs now, by digest, or nothing before
//                                                    the first deployment
//   node scripts/release.mjs smoke                   the health of the API through the edge, and API Gateway refusing a
//                                                    call that skips the edge, without and with a made-up secret
//   node scripts/release.mjs versions                what runs: the image of each function, the stacks and their state,
//                                                    the build of the web app, and the recent images a rollback can take
import { request as httpsRequest } from 'node:https'
import { request as httpRequest } from 'node:http'
import { CloudFormationClient, DescribeStacksCommand } from '@aws-sdk/client-cloudformation'
import { DescribeImagesCommand, ECRClient, GetAuthorizationTokenCommand } from '@aws-sdk/client-ecr'
import { GetFunctionCommand, LambdaClient } from '@aws-sdk/client-lambda'
import { HeadObjectCommand, S3Client } from '@aws-sdk/client-s3'
import { GetParametersCommand, SSMClient } from '@aws-sdk/client-ssm'

const STAGE = process.env.STAGE ?? 'local'
const PREFIX = `dian-einvoice-${STAGE}`
const EMULATED = process.env.AWS_ENDPOINT_URL !== undefined
// The functions of the serverless service, and the stacks the pipeline deploys.
const FUNCTIONS = ['api', 'chain', 'webhooks', 'relay', 'scheduler', 'console', 'origin-verifier', 'notifier']
const STACKS = ['messaging', 'storage', 'data', 'security', 'mail', 'alerts', ...(STAGE === 'prod' ? ['network', 'database'] : []), 'compute', 'functions', 'edge', 'observability']
let failures = 0

function verify(passed, message) {
  process.stdout.write(`   ${passed ? 'PASS' : 'FAIL'}  ${message}\n`)
  failures += passed ? 0 : 1
}

async function parameters(names) {
  const { Parameters: found = [] } = await new SSMClient({}).send(new GetParametersCommand({ Names: names.map((name) => `/dian-einvoice/${STAGE}/${name}`) }))

  return Object.fromEntries(names.map((name) => [name, found.find((parameter) => parameter.Name === `/dian-einvoice/${STAGE}/${name}`)?.Value]))
}

async function registryPassword() {
  const { authorizationData: [data] = [] } = await new ECRClient({}).send(new GetAuthorizationTokenCommand({}))
  const token = Buffer.from(data?.authorizationToken ?? '', 'base64').toString('utf8')

  if (!token.startsWith('AWS:')) {
    throw new Error('ECR gave no authorization token.')
  }

  process.stdout.write(token.slice('AWS:'.length))
}

async function imageDigest(tag) {
  if (tag === undefined || tag === '') {
    throw new Error('usage: node scripts/release.mjs image-digest <tag>')
  }

  try {
    const { imageDetails: [image] = [] } = await new ECRClient({}).send(new DescribeImagesCommand({ repositoryName: `${PREFIX}-functions`, imageIds: [{ imageTag: tag }] }))
    process.stdout.write(image?.imageDigest ?? '')
  } catch (error) {
    if (error.name !== 'ImageNotFoundException') {
      throw error
    }
  }
}

async function currentImage() {
  try {
    const found = await new LambdaClient({}).send(new GetFunctionCommand({ FunctionName: `${PREFIX}-api` }))
    const uri = found.Code?.ImageUri ?? ''
    process.stdout.write(uri.includes('@sha256:') ? uri : '')
  } catch (error) {
    if (error.name !== 'ResourceNotFoundException') {
      throw error
    }
  }
}

/** One request, with a host of its own where the emulator serves API Gateway by host name. */
function call(url, { host, headers = {} } = {}) {
  return new Promise((resolve, reject) => {
    const target = new URL(url)
    const send = target.protocol === 'https:' ? httpsRequest : httpRequest
    const sent = send({ hostname: target.hostname, port: target.port, path: `${target.pathname}${target.search}`, method: 'GET', headers: { accept: 'application/json', ...headers, ...(host === undefined ? {} : { host }) }, timeout: 30_000 }, (response) => {
      const chunks = []
      response.on('data', (chunk) => chunks.push(chunk))
      response.on('end', () => resolve({ status: response.statusCode, headers: response.headers, body: Buffer.concat(chunks).toString('utf8') }))
    })
    sent.on('timeout', () => sent.destroy(new Error(`${url} did not respond within 30 s`)))
    sent.on('error', reject)
    sent.end()
  })
}

async function smoke() {
  const found = await parameters(['functions/http-api-id', 'edge/distribution-domain-name'])
  const apiId = found['functions/http-api-id']

  if (apiId === undefined) {
    throw new Error(`The stage ${STAGE} publishes no HTTP API: deploy the functions first.`)
  }

  // The edge: in the emulator the proxy in front of the distribution (cloud-gateway), in AWS the domain of the platform
  // or, without one, the name CloudFront gives the distribution.
  const edge = EMULATED ? (process.env.EDGE_CHECK_URL ?? 'http://api:8000') : `https://${process.env.EDGE_DOMAIN_NAME || found['edge/distribution-domain-name']}`
  const health = await call(`${edge}/api/v1/health`)
  const through = health.headers['apigw-requestid'] !== undefined || health.headers['x-amzn-requestid'] !== undefined
  verify(health.status === 200 && through && health.body.includes('"status"'), `The API responds to its health check through the edge, ${edge}: HTTP ${health.status}${through ? ', through API Gateway' : ''}.`)

  // API Gateway, called directly as a client that skips the edge would: refused before the application.
  const direct = (headers) => EMULATED
    ? call('http://floci:4566/api/v1/health', { host: `${apiId}.execute-api.localhost.floci.io:4566`, headers })
    : call(`https://${apiId}.execute-api.${process.env.AWS_REGION ?? 'us-east-1'}.amazonaws.com/api/v1/health`, { headers })
  const missing = await direct({})
  const wrong = await direct({ 'x-origin-verify': 'a-value-a-client-made-up-0000000000000000000000000000000000000000' })
  verify(missing.status === 401 && wrong.status === 403 && missing.headers['x-request-id'] === undefined && wrong.headers['x-request-id'] === undefined,
    `API Gateway refuses a call that skips the edge, before the application: ${missing.status} without X-Origin-Verify, ${wrong.status} with a made-up value.`)
}

async function versions() {
  const lambda = new LambdaClient({})
  process.stdout.write(`What runs in the ${STAGE} stage, at ${new Date().toISOString()}:\n\nFunctions:\n`)

  for (const fn of FUNCTIONS) {
    try {
      const found = await lambda.send(new GetFunctionCommand({ FunctionName: `${PREFIX}-${fn}` }))
      const code = found.Code?.ImageUri === undefined ? `${found.Configuration?.Runtime} zip ${found.Configuration?.CodeSha256?.slice(0, 12)}` : `image ${found.Code.ImageUri.split('/').pop()}`
      process.stdout.write(`  ${fn.padEnd(16)} ${code}, reserved concurrency ${found.Concurrency?.ReservedConcurrentExecutions ?? 'none'}, ${found.Configuration?.LastModified ?? ''}\n`)
    } catch (error) {
      process.stdout.write(`  ${fn.padEnd(16)} ${error.name === 'ResourceNotFoundException' ? 'not deployed' : error.message}\n`)
    }
  }

  process.stdout.write('\nStacks:\n')
  const cloudformation = new CloudFormationClient({})

  for (const stack of STACKS) {
    try {
      const { Stacks: [found] = [] } = await cloudformation.send(new DescribeStacksCommand({ StackName: `${PREFIX}-${stack}` }))
      process.stdout.write(`  ${`${PREFIX}-${stack}`.padEnd(36)} ${found?.StackStatus}, ${(found?.LastUpdatedTime ?? found?.CreationTime)?.toISOString() ?? ''}\n`)
    } catch {
      process.stdout.write(`  ${`${PREFIX}-${stack}`.padEnd(36)} not deployed\n`)
    }
  }

  const { 'edge/panel-bucket-name': bucket } = await parameters(['edge/panel-bucket-name'])

  if (bucket !== undefined) {
    try {
      const index = await new S3Client({ forcePathStyle: EMULATED }).send(new HeadObjectCommand({ Bucket: bucket, Key: 'index.html' }))
      process.stdout.write(`\nWeb app: index.html version ${index.VersionId ?? 'unversioned'}, ${index.LastModified?.toISOString() ?? ''} (panel.mjs rollback goes back to the version before it).\n`)
    } catch {
      process.stdout.write('\nWeb app: not uploaded.\n')
    }
  }

  try {
    const { imageDetails: images = [] } = await new ECRClient({}).send(new DescribeImagesCommand({ repositoryName: `${PREFIX}-functions` }))
    const recent = images.sort((a, b) => (b.imagePushedAt?.getTime() ?? 0) - (a.imagePushedAt?.getTime() ?? 0)).slice(0, 5)
    process.stdout.write(`\nThe ${recent.length} most recent images of ${PREFIX}-functions (scripts/deploy.sh ${STAGE} rollback-image <repository>@<digest> goes back to one):\n`)

    for (const image of recent) {
      process.stdout.write(`  ${image.imageDigest}  ${(image.imageTags ?? []).join(', ') || 'untagged'}  ${image.imagePushedAt?.toISOString() ?? ''}\n`)
    }
  } catch (error) {
    process.stdout.write(`\nThe images of ${PREFIX}-functions: ${error.message}\n`)
  }
}

const [action, argument] = process.argv.slice(2)
const actions = { 'registry-password': registryPassword, 'image-digest': () => imageDigest(argument), 'current-image': currentImage, smoke, versions }

if (actions[action ?? ''] === undefined) {
  process.stderr.write('usage: node scripts/release.mjs registry-password|image-digest <tag>|current-image|smoke|versions\n')
  process.exit(2)
}

await actions[action]()
process.exit(failures === 0 ? 0 : 1)
