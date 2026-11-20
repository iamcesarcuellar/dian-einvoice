// make cloud-up: prints infra/functions/.env.cloud, the settings of the compose service cloud-gateway (the
// minimal proxy in front of API Gateway): the host name by which the local emulator serves the HTTP API of
// the stage local, from the outputs of its stack.
import { CloudFormationClient, DescribeStacksCommand } from '@aws-sdk/client-cloudformation'

const stack = 'dian-einvoice-local-functions'
const cloudformation = new CloudFormationClient({})
const { Stacks: stacks = [] } = await cloudformation.send(new DescribeStacksCommand({ StackName: stack }))
const id = stacks[0]?.Outputs?.find((output) => output.OutputKey === 'HttpApiId')?.OutputValue

if (id === undefined || !/^[a-z0-9]+$/.test(id)) {
  process.stderr.write(`The stack ${stack} has no output HttpApiId: deploy the functions first.\n`)
  process.exit(1)
}

process.stdout.write([
  '# The emulated cloud (make cloud-up): the host name of the HTTP API of the stage local in the local emulator,',
  '# for the proxy in front of it (compose service cloud-gateway). Written by make cloud-up; never committed.',
  `API_GATEWAY_HOST=${id}.execute-api.localhost.floci.io:4566`,
  '',
].join('\n'))
