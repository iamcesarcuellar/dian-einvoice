// The serverless service of the functions (infra/functions), as osls packaged it for both stages without a
// network or credentials (make test-infra and make cdk-check package it first): the same rules of cdk-nag
// as the CDK app, over the template included in a stack of its own; every function bounded and with a role
// of its own from the stack compute; the mappings of the queues with a partial batch response and a
// visibility timeout of their queue that fits the function; the parameters it reads, all published by the
// CDK app or listed for Infrastructure task 3; and no IAM of its own.
import assert from 'node:assert/strict'
import { mkdtempSync, readFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, it } from 'node:test'
import { App, Stack, Validations } from 'aws-cdk-lib'
import { Template } from 'aws-cdk-lib/assertions'
import { CfnInclude } from 'aws-cdk-lib/cloudformation-include'
import { AwsSolutionsChecks } from 'cdk-nag'
import { cdkContext } from '../lib/application.ts'
import { SCHEDULED_FUNCTIONS } from '../lib/compute-stack.ts'
import { FUNCTIONS, FUTURE_PARAMETERS, STAGES, type StageName } from '../lib/stage.ts'
import { stageOf, template } from './support.ts'

interface Resource {
  readonly Type: string
  readonly Properties: Record<string, unknown>
}

interface FunctionsTemplate {
  readonly Parameters: Record<string, { readonly Type: string, readonly Default: string }>
  readonly Resources: Record<string, Resource>
}

/** At most this many connections to the database at once: one per execution environment (docs/infrastructure.md). */
const DATABASE_CONNECTIONS = 60

/** The 30 s API Gateway waits for an integration. */
const API_GATEWAY_TIMEOUT = 30

/** The exceptions of cdk-nag on the template of the functions, the rule and the resource they are acknowledged on. */
const EXCEPTIONS: Readonly<Record<string, string>> = {
  'AwsSolutions-APIG4 HttpApiRouteDefault': 'The application authorizes every request itself: the session of the web app with its CSRF token, or an API key, and the health check and the API reference are public by design.',
}

const directory = process.env.FUNCTIONS_TEMPLATES ?? join(import.meta.dirname, '../../functions')

function packaged(stage: StageName): FunctionsTemplate {
  return JSON.parse(readFileSync(join(directory, '.package', stage, 'cloudformation-template-update-stack.json'), 'utf8')) as FunctionsTemplate
}

function resources(body: FunctionsTemplate, type: string): [string, Resource][] {
  return Object.entries(body.Resources).filter(([, resource]) => resource.Type === type)
}

/** The parameter a property refers to, through Ref or Fn::Sub of ${Name}. */
function parameterOf(value: unknown): string | null {
  if (typeof value === 'object' && value !== null) {
    const reference = (value as Record<string, unknown>).Ref ?? (value as Record<string, unknown>)['Fn::Sub']

    if (typeof reference === 'string') {
      return reference.replace(/^\$\{([A-Za-z0-9]+)\}$/, '$1')
    }
  }

  return null
}

/** The names of every SSM parameter the CDK app publishes for a stage. */
function published(stage: StageName): Set<string> {
  const stacks = stageOf(stage)
  const names = new Set<string>()

  for (const stack of [stacks.messaging, stacks.storage, stacks.data, stacks.security, stacks.mail, stacks.compute]) {
    for (const parameter of Object.values(template(stack).findResources('AWS::SSM::Parameter')) as { Properties: { Name: string } }[]) {
      names.add(parameter.Properties.Name)
    }
  }

  return names
}

for (const stage of ['local', 'prod'] as const) {
  const config = STAGES[stage]
  const body = packaged(stage)
  const functions = resources(body, 'AWS::Lambda::Function')

  describe(`functions of the stage ${stage}`, () => {
    it('are the functions the stack compute gives a role to, each bounded in time, memory and concurrency', () => {
      assert.deepEqual(functions.map(([, fn]) => fn.Properties.FunctionName).sort(), FUNCTIONS.map((fn) => `dian-einvoice-${stage}-${fn}`).sort())
      const roles = new Set<string>()

      for (const [id, fn] of functions) {
        const name = String(fn.Properties.FunctionName).replace(`dian-einvoice-${stage}-`, '')
        assert.equal(fn.Properties.PackageType, 'Image', id)
        assert.match(String((fn.Properties.Code as { ImageUri: string }).ImageUri), new RegExp(`^${config.account}\\.dkr\\.ecr\\.${config.region}\\.amazonaws\\.com/dian-einvoice-${stage}-functions@sha256:[0-9a-f]{64}$`), id)
        assert.ok(Number(fn.Properties.Timeout) >= 1 && Number(fn.Properties.Timeout) <= 900, `${id} has a timeout`)
        assert.ok(Number(fn.Properties.MemorySize) >= 256, `${id} has its memory`)
        assert.ok(Number.isInteger(fn.Properties.ReservedConcurrentExecutions) && Number(fn.Properties.ReservedConcurrentExecutions) >= 1, `${id} has a concurrency limit`)

        const role = parameterOf(fn.Properties.Role)
        assert.ok(role !== null, `${id} takes its role from a parameter`)
        assert.equal(body.Parameters[role]?.Default, `/dian-einvoice/${stage}/compute/roles/${name}`, `${id} has the role of the stack compute for ${name}`)
        roles.add(role)
      }

      assert.equal(roles.size, functions.length, 'no two functions share a role')
    })

    it('hold at most the connections of the budget of the database, and the API answers within API Gateway', () => {
      const total = functions.reduce((sum, [, fn]) => sum + Number(fn.Properties.ReservedConcurrentExecutions), 0)
      assert.ok(total <= DATABASE_CONNECTIONS, `${total} execution environments at once, at most ${DATABASE_CONNECTIONS} connections`)

      const api = functions.find(([, fn]) => fn.Properties.FunctionName === `dian-einvoice-${stage}-api`)?.[1]
      assert.ok(api !== undefined && Number(api.Properties.Timeout) < API_GATEWAY_TIMEOUT)
    })

    it('consume each queue in batches with a partial batch response, within the visibility timeout of the queue', () => {
      const mappings = resources(body, 'AWS::Lambda::EventSourceMapping')
      const queues = template(stageOf(stage).messaging).findResources('AWS::SQS::Queue') as Record<string, { Properties: { QueueName: string, VisibilityTimeout?: number } }>
      assert.equal(mappings.length, 2)

      for (const channel of ['chain', 'webhooks'] as const) {
        const mapping = mappings.find(([, candidate]) => parameterOf(candidate.Properties.EventSourceArn) === `${channel === 'chain' ? 'Chain' : 'Webhooks'}QueueArn`)?.[1]
        assert.ok(mapping !== undefined, `a mapping of the ${channel} queue`)
        assert.equal(body.Parameters[`${channel === 'chain' ? 'Chain' : 'Webhooks'}QueueArn`]?.Default, `/dian-einvoice/${stage}/messaging/${channel}-queue-arn`)
        assert.deepEqual(mapping.Properties.FunctionResponseTypes, ['ReportBatchItemFailures'])
        assert.equal(mapping.Properties.BatchSize, 10)

        const target = parameterOf(mapping.Properties.FunctionName)
        const fn = target === null ? undefined : body.Resources[target]
        assert.equal(fn?.Properties.FunctionName, `dian-einvoice-${stage}-${channel}`)
        const timeout = Number(fn?.Properties.Timeout)
        const window = Number(mapping.Properties.MaximumBatchingWindowInSeconds ?? 0)
        assert.ok(Number((mapping.Properties.ScalingConfig as { MaximumConcurrency: number }).MaximumConcurrency) <= Number(fn?.Properties.ReservedConcurrentExecutions), 'the mapping invokes no more at once than the function may run')

        // Lambda refuses a mapping whose queue hides a message for less than the function may run; AWS
        // recommends six times that (and the batching window), so a batch retried after a throttle is not
        // received twice. The stage local keeps the minimum, so a message a killed function held comes back
        // soon (docs/limitations.md).
        const queue = Object.values(queues).find((candidate) => candidate.Properties.QueueName === `dian-einvoice-${stage}-${channel}`)
        const visibility = Number(queue?.Properties.VisibilityTimeout)
        assert.ok(visibility >= timeout + window, `${channel}: visibility ${visibility} s for a function of ${timeout} s`)
        assert.equal(visibility, config.queues.visibilityTimeout.toSeconds())

        if (stage === 'prod') {
          assert.ok(visibility >= 6 * timeout + window, `${channel}: visibility ${visibility} s, six times the ${timeout} s of the function`)
        }
      }
    })

    it('run the scheduled functions once a minute without retries, the next run being the retry', () => {
      const schedules = resources(body, 'AWS::Scheduler::Schedule')
      assert.equal(schedules.length, SCHEDULED_FUNCTIONS.length)

      for (const scheduled of SCHEDULED_FUNCTIONS) {
        const schedule = schedules.find(([, candidate]) => candidate.Properties.Name === `dian-einvoice-${stage}-${scheduled}`)?.[1]
        const target = schedule?.Properties.Target as { RoleArn: unknown, RetryPolicy: { MaximumRetryAttempts: number }, Input: string } | undefined
        assert.equal(schedule?.Properties.ScheduleExpression, 'rate(1 minute)')
        assert.equal(target?.RetryPolicy.MaximumRetryAttempts, 0)
        assert.equal(body.Parameters[parameterOf(target?.RoleArn) ?? '']?.Default, `/dian-einvoice/${stage}/compute/roles/schedules`)

        const invokeConfig = resources(body, 'AWS::Lambda::EventInvokeConfig').find(([, candidate]) => parameterOf(candidate.Properties.FunctionName) === `${scheduled.charAt(0).toUpperCase()}${scheduled.slice(1)}LambdaFunction`)?.[1]
        assert.equal(invokeConfig?.Properties.MaximumRetryAttempts, 0, `${scheduled}: no retry of the asynchronous invocation`)
      }
    })

    it('read only parameters the CDK app publishes, and in prod those Infrastructure task 3 will', () => {
      const names = published(stage)
      const future = Object.values(FUTURE_PARAMETERS).map((name) => `/dian-einvoice/${stage}/${name}`)

      for (const [id, parameter] of Object.entries(body.Parameters)) {
        assert.equal(parameter.Type, 'AWS::SSM::Parameter::Value<String>', id)
        assert.ok(names.has(parameter.Default) || (stage === 'prod' && future.includes(parameter.Default)), `${id}: ${parameter.Default} is published`)
      }

      if (stage === 'prod') {
        assert.deepEqual(future.filter((name) => !Object.values(body.Parameters).some((parameter) => parameter.Default === name)), [], 'prod reads every parameter of task 3')
      }

      for (const [id, fn] of functions) {
        assert.equal(fn.Properties.VpcConfig !== undefined, config.network === 'private', `${id} runs in the private subnets in prod only`)
      }
    })

    it('define no IAM of their own: every role and policy is in the CDK app', () => {
      assert.deepEqual(Object.values(body.Resources).filter((resource) => resource.Type.startsWith('AWS::IAM::')), [])
    })

    it('pass the rules of cdk-nag of the CDK app, with every exception acknowledged with its reason', () => {
      const app = new App({ context: cdkContext(), outdir: mkdtempSync(join(tmpdir(), 'cdk-functions-')) })
      Validations.of(app).addPlugins(new AwsSolutionsChecks(app, { verbose: true }))
      const stack = new Stack(app, `functions-${stage}`, { env: { account: config.account, region: config.region } })
      const include = new CfnInclude(stack, 'Functions', { templateFile: join(directory, '.package', stage, 'cloudformation-template-update-stack.json') })

      for (const [exception, reason] of Object.entries(EXCEPTIONS)) {
        const [rule, logicalId] = exception.split(' ') as [string, string]
        Validations.of(include.getResource(logicalId)).acknowledge({ id: `AwsSolutions::${rule}`, reason })
      }

      const assembly = app.synth()
      const report = JSON.parse(readFileSync(join(assembly.directory, 'validation-report.json'), 'utf8')) as { pluginReports: { pluginName: string, conclusion: string, violations: { ruleName: string, violatingConstructs: { constructPath: string }[] }[], suppressedViolations: { ruleName: string, reason: string, violatingConstructs: { constructPath: string }[] }[] }[] }
      const nag = report.pluginReports.find((plugin) => plugin.pluginName === 'AwsSolutions')
      assert.ok(nag !== undefined)
      assert.deepEqual(nag.violations.flatMap((violation) => violation.violatingConstructs.map((construct) => `${violation.ruleName} ${construct.constructPath}`)), [])
      assert.equal(nag.conclusion, 'success')

      // The acknowledged findings name the resource of the template they are on.
      const suppressed = nag.suppressedViolations.flatMap((violation) => violation.violatingConstructs.map((construct) => {
        assert.ok(violation.reason.length >= 60, `${violation.ruleName} needs a written reason`)

        return `${violation.ruleName} ${construct.constructPath.split('/').pop() ?? ''}`
      }))
      assert.deepEqual([...new Set(suppressed)].sort(), Object.keys(EXCEPTIONS).sort())

      // cdk-nag saw every resource of the template.
      assert.equal(Object.keys(Template.fromStack(stack).toJSON().Resources as object).length, Object.keys(body.Resources).length)
    })
  })
}
