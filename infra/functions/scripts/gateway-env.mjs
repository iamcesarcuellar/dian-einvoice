// make cloud-up: prints infra/functions/.env.cloud, the settings of the compose service cloud-gateway, the proxy in
// front of the edge of the emulated cloud (infra/functions/gateway.conf.template): the host name by which the local
// emulator serves the CloudFront distribution of the local stage (its id, which the stack edge of the CDK app
// publishes), and the paths the edge sends to the API (backend/resources/http/paths.json, mounted at /workspace/http)
// as one regular expression of nginx, so the proxy rewrites the routes of the web app only.
import { readFileSync } from 'node:fs'
import { GetParameterCommand, SSMClient } from '@aws-sdk/client-ssm'

const STAGE = process.env.STAGE ?? 'local'
const parameter = `/dian-einvoice/${STAGE}/edge/distribution-id`
const { Parameter: found } = await new SSMClient({}).send(new GetParameterCommand({ Name: parameter }))
const id = found?.Value ?? ''

if (!/^[A-Z0-9]+$/.test(id)) {
  process.stderr.write(`${parameter} names no distribution: deploy the stack edge first.\n`)
  process.exit(1)
}

/** A path pattern of CloudFront as a regular expression: * any characters, ? one. */
function regex(pattern) {
  return pattern.replace(/[.+^${}()|[\]\\]/g, '\\$&').replaceAll('*', '.*').replaceAll('?', '.')
}

const paths = JSON.parse(readFileSync(process.env.HTTP_RESOURCES_PATHS ?? '/workspace/http/paths.json', 'utf8')).api

if (!Array.isArray(paths) || paths.length === 0) {
  process.stderr.write('paths.json lists no paths of the API.\n')
  process.exit(1)
}

process.stdout.write([
  '# The emulated cloud (make cloud-up): the host name of the distribution of the local stage in the local emulator,',
  '# and the paths of the API, for the proxy in front of it (compose service cloud-gateway). Written by make cloud-up;',
  '# never committed.',
  `EDGE_HOST=${id.toLowerCase()}.cloudfront.localhost.floci.io`,
  `EDGE_API_PATHS=^(${paths.map(regex).join('|')})$`,
  '',
].join('\n'))
