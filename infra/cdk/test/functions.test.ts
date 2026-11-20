// The serverless service of the functions (infra/functions), as osls packaged it for both stages without a
// network or credentials (make test-infra and make cdk-check package it first): the same rules of cdk-nag
// as the CDK app, over the template included in a stack of its own; every function bounded and with a role
// of its own from the stack compute; the only route behind the origin verifier of the edge, a zip of its own
// outside the VPC; the mappings of the queues with a partial batch response and a visibility timeout of their
// queue that fits the function; the parameters it reads, all published by the CDK app (in prod, those of the
// network and the database too); the identity of the database of each function and its encrypted connection;
// the notifier of the alarms on the topic of the stack alerts, with its dead-letter queue; a log group with its
// retention for every function, the system logs of Lambda and the access logs of API Gateway in JSON, and the
// application logging its own lines as JSON; and no IAM of its own.
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
import { DATABASE_PARAMETERS, FUNCTIONS, HTTP_API_PARAMETER, MIGRATIONS_FUNCTION, NETWORK_PARAMETERS, NOTIFIER, ORIGIN_VERIFIER, STAGES, type StageName } from '../lib/stage.ts'
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
const NODE_24 = 'Node.js 24, the newest runtime the local emulator runs (Floci 2.5.0 has no image of nodejs26.x), and one definition serves every stage; Node.js 24 is a long-term support line until April 2028. A change of one line of serverless.yml moves it once the emulator runs 26.'

const EXCEPTIONS: Readonly<Record<string, string>> = {
  'AwsSolutions-L1 OriginVerifierLambdaFunction': NODE_24,
  'AwsSolutions-L1 NotifierLambdaFunction': NODE_24,
}

/** The days a log group of the functions keeps its lines in each stage (serverless.yml). */
const LOG_RETENTION: Readonly<Record<StageName, number>> = { local: 7, prod: 365 }

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

  for (const stack of [stacks.messaging, stacks.storage, stacks.data, stacks.security, stacks.mail, stacks.alerts, stacks.compute, ...(stacks.network === null ? [] : [stacks.network]), ...(stacks.database === null ? [] : [stacks.database])]) {
    for (const parameter of Object.values(template(stack).findResources('AWS::SSM::Parameter')) as { Properties: { Name: string } }[]) {
      names.add(parameter.Properties.Name)
    }
  }

  return names
}

for (const stage of ['local', 'prod'] as const) {
  const config = STAGES[stage]
  const body = packaged(stage)
  const everyFunction = resources(body, 'AWS::Lambda::Function')
  // The functions of the application, on the image of PHP; the origin verifier of the edge and the notifier apart.
  const functions = everyFunction.filter(([, fn]) => fn.Properties.PackageType === 'Image')
  const verifier = everyFunction.find(([, fn]) => fn.Properties.FunctionName === `dian-einvoice-${stage}-${ORIGIN_VERIFIER}`)
  const notifier = everyFunction.find(([, fn]) => fn.Properties.FunctionName === `dian-einvoice-${stage}-${NOTIFIER}`)

  describe(`functions of the stage ${stage}`, () => {
    it('are the functions the stack compute gives a role to, each bounded in time, memory and concurrency', () => {
      assert.deepEqual(everyFunction.map(([, fn]) => fn.Properties.FunctionName).sort(), [...FUNCTIONS, ORIGIN_VERIFIER, NOTIFIER].map((fn) => `dian-einvoice-${stage}-${fn}`).sort())
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

    it('put every route behind the origin verifier, a small function of Node.js apart from the image, outside any VPC', () => {
      const routes = resources(body, 'AWS::ApiGatewayV2::Route')
      const authorizers = resources(body, 'AWS::ApiGatewayV2::Authorizer')
      assert.equal(routes.length, 1)
      assert.equal(authorizers.length, 1)
      const [authorizerId, authorizer] = authorizers[0] as [string, Resource]
      assert.deepEqual(routes.map(([, route]) => [route.Properties.RouteKey, route.Properties.AuthorizationType, parameterOf(route.Properties.AuthorizerId)]), [['$default', 'CUSTOM', authorizerId]])

      // A request authorizer with simple answers, on the header the edge sends, its answer kept 5 minutes.
      assert.equal(authorizer.Properties.AuthorizerType, 'REQUEST')
      assert.equal(authorizer.Properties.AuthorizerPayloadFormatVersion, '2.0')
      assert.equal(authorizer.Properties.EnableSimpleResponses, true)
      assert.deepEqual(authorizer.Properties.IdentitySource, ['$request.header.X-Origin-Verify'])
      assert.equal(authorizer.Properties.AuthorizerResultTtlInSeconds, 300)
      assert.match(JSON.stringify(authorizer.Properties.AuthorizerUri), /OriginVerifierLambdaFunction/)

      // TypeScript built to JavaScript and packaged as a zip of its own; the role of the stack compute, the secret of
      // the edge and nothing of the application; outside the VPC, small and bounded.
      assert.ok(verifier !== undefined)
      const fn = verifier[1].Properties
      assert.equal(fn.Runtime, 'nodejs24.x')
      assert.equal(fn.Handler, 'origin-verifier/dist/origin-verifier/handler.handler')
      assert.equal(fn.PackageType, undefined)
      assert.ok((fn.Code as { S3Key?: string }).S3Key?.endsWith('.zip'))
      assert.equal(fn.VpcConfig, undefined)
      assert.equal(fn.MemorySize, 128)
      assert.ok(Number(fn.Timeout) <= 10)
      assert.ok(Number(fn.ReservedConcurrentExecutions) >= 1)
      assert.equal(body.Parameters[parameterOf(fn.Role) ?? '']?.Default, `/dian-einvoice/${stage}/compute/roles/${ORIGIN_VERIFIER}`)
      const environment = (fn.Environment as { Variables: Record<string, unknown> }).Variables
      assert.deepEqual(Object.keys(environment).sort(), ['AWS_ENDPOINT_URL', 'ORIGIN_SECRET_ID', 'STAGE'])
      assert.equal(body.Parameters[parameterOf(environment.ORIGIN_SECRET_ID) ?? '']?.Default, `/dian-einvoice/${stage}/security/origin-secret-arn`)

      // Only the builds of the two functions of Node.js go in the zip, each with the logger they share.
      const zip = readFileSync(join(directory, '.package', stage, 'dian-einvoice.zip'))
      const names = [...zip.toString('latin1').matchAll(/[a-z-]+\/dist\/[a-z-]+\/[A-Za-z0-9_-]+\.mjs/g)].map((match) => match[0])
      assert.deepEqual([...new Set(names)].sort(), [
        'notifier/dist/notifier/handler.mjs', 'notifier/dist/notifier/notify.mjs', 'notifier/dist/shared/log.mjs',
        'origin-verifier/dist/origin-verifier/handler.mjs', 'origin-verifier/dist/origin-verifier/verify.mjs', 'origin-verifier/dist/shared/log.mjs',
      ])
    })

    it('subscribe the notifier, a small function of Node.js outside any VPC, to the topic of the alarms, with a dead-letter queue', () => {
      assert.ok(notifier !== undefined)
      const [notifierId, resource] = notifier
      const fn = resource.Properties
      assert.equal(fn.Runtime, 'nodejs24.x')
      assert.equal(fn.Handler, 'notifier/dist/notifier/handler.handler')
      assert.equal(fn.PackageType, undefined)
      assert.equal(fn.VpcConfig, undefined)
      assert.equal(fn.MemorySize, 128)
      assert.ok(Number(fn.Timeout) <= 30)
      assert.ok(Number(fn.ReservedConcurrentExecutions) >= 1)
      assert.equal(body.Parameters[parameterOf(fn.Role) ?? '']?.Default, `/dian-einvoice/${stage}/compute/roles/${NOTIFIER}`)

      // The address of the chat webhook from its secret, its own dead-letter queue, nothing of the application.
      const environment = (fn.Environment as { Variables: Record<string, unknown> }).Variables
      assert.deepEqual(Object.keys(environment).sort(), ['AWS_ENDPOINT_URL', 'DEAD_LETTER_QUEUE_URL', 'STAGE', 'WEBHOOK_SECRET_ID'])
      assert.equal(body.Parameters[parameterOf(environment.WEBHOOK_SECRET_ID) ?? '']?.Default, `/dian-einvoice/${stage}/alerts/chat-webhook-secret-arn`)
      assert.equal(body.Parameters[parameterOf(environment.DEAD_LETTER_QUEUE_URL) ?? '']?.Default, `/dian-einvoice/${stage}/alerts/notifier-dlq-url`)

      // Its asynchronous invocations retry twice and then go to the dead-letter queue; so does what SNS cannot hand it.
      const invoke = resources(body, 'AWS::Lambda::EventInvokeConfig').find(([, candidate]) => parameterOf(candidate.Properties.FunctionName) === notifierId)?.[1]
      assert.equal(invoke?.Properties.MaximumRetryAttempts, 2)
      const deadLetters = `/dian-einvoice/${stage}/alerts/notifier-dlq-arn`
      assert.equal(body.Parameters[parameterOf((invoke?.Properties.DestinationConfig as { OnFailure: { Destination: unknown } }).OnFailure.Destination) ?? '']?.Default, deadLetters)

      const subscriptions = resources(body, 'AWS::SNS::Subscription')
      assert.equal(subscriptions.length, 1)
      const subscription = subscriptions[0]?.[1].Properties as { TopicArn: unknown, Protocol: string, Endpoint: unknown, RedrivePolicy: { deadLetterTargetArn: unknown } }
      assert.equal(subscription.Protocol, 'lambda')
      assert.deepEqual(subscription.Endpoint, { 'Fn::GetAtt': [notifierId, 'Arn'] })
      assert.equal(body.Parameters[parameterOf(subscription.TopicArn) ?? '']?.Default, `/dian-einvoice/${stage}/alerts/alarms-topic-arn`)
      assert.equal(body.Parameters[parameterOf(subscription.RedrivePolicy.deadLetterTargetArn) ?? '']?.Default, deadLetters)

      // Only the topic of the alarms invokes it.
      const permission = resources(body, 'AWS::Lambda::Permission').find(([, candidate]) => candidate.Properties.Principal === 'sns.amazonaws.com')?.[1].Properties
      assert.deepEqual(permission?.FunctionName, { 'Fn::GetAtt': [notifierId, 'Arn'] })
      assert.deepEqual(permission?.SourceArn, subscription.TopicArn)
    })

    it('give every function a log group with its retention, with the system logs of Lambda and the access logs of API Gateway in JSON', () => {
      const groups = resources(body, 'AWS::Logs::LogGroup').map(([, group]) => group.Properties as { LogGroupName: string, RetentionInDays: number })

      for (const [id, fn] of everyFunction) {
        const group = groups.find((candidate) => candidate.LogGroupName === `/aws/lambda/${String(fn.Properties.FunctionName)}`)
        assert.ok(group !== undefined, `${id} has its log group`)
        assert.equal(group.RetentionInDays, LOG_RETENTION[stage], id)
        assert.deepEqual(fn.Properties.LoggingConfig, { LogFormat: 'JSON', SystemLogLevel: 'INFO' }, id)
      }

      // One JSON object per request, without its query, its headers or the address it came from.
      const stageResource = resources(body, 'AWS::ApiGatewayV2::Stage')[0]?.[1].Properties as { AccessLogSettings: { Format: string, DestinationArn: unknown } }
      const format = JSON.parse(stageResource.AccessLogSettings.Format) as Record<string, string>
      assert.ok(Object.values(format).every((value) => value.startsWith('$context.')), 'every field is a value of API Gateway')
      assert.ok(['requestId', 'status', 'responseLatency', 'integrationLatency', 'authorizerError'].every((field) => field in format))
      assert.ok(!Object.values(format).some((value) => /sourceIp|querystring|header|userAgent/i.test(value)), 'no address, query or header of the client')
      assert.equal(groups.find((group) => group.LogGroupName === `/aws/http-api/dian-einvoice-${stage}-functions`)?.RetentionInDays, LOG_RETENTION[stage])
    })

    it('make the application log one JSON object per line, and run php artisan through the handler of the application', () => {
      for (const [id, fn] of functions) {
        const environment = (fn.Properties.Environment as { Variables: Record<string, unknown> }).Variables
        assert.equal(environment.LOG_CHANNEL, 'stderr', id)
        assert.equal(environment.LOG_STDERR_FORMATTER, 'App\\Support\\Observability\\JsonLogFormatter', id)
        assert.equal(environment.APP_STAGE, stage, id)
        assert.equal(environment.LOG_REQUESTS, 'true', id)
        assert.equal(environment.LOG_JOBS, 'true', id)
        assert.equal(environment.BREF_LARAVEL_OMIT_INITLOG, '1', id)

        // No function runs the console runtime of Bref, which prints what a command prints into the log.
        const command = (fn.Properties.ImageConfig as { Command: string[] }).Command
        assert.notEqual(environment.BREF_RUNTIME, 'console', id)

        if (['relay', 'scheduler', 'console'].some((name) => String(fn.Properties.FunctionName).endsWith(`-${name}`))) {
          assert.deepEqual([environment.BREF_RUNTIME, command], ['function', ['App\\Support\\Lambda\\ArtisanHandler']], id)
        }
      }
    })

    it('publish the id of the HTTP API for the stack edge of the CDK app, and run the application behind the edge', () => {
      const parameter = resources(body, 'AWS::SSM::Parameter').map(([, resource]) => resource.Properties)
      assert.deepEqual(parameter.map((properties) => [properties.Name, properties.Value]), [[`/dian-einvoice/${stage}/${HTTP_API_PARAMETER}`, { Ref: 'HttpApi' }]])

      for (const [id, fn] of functions) {
        const environment = (fn.Properties.Environment as { Variables: Record<string, unknown> }).Variables
        assert.equal(environment.BEHIND_EDGE, 'true', id)
        assert.equal(environment.SCRAMBLE_DEV_TOOLS, 'false', id)
        assert.equal(environment.TRUSTED_PROXIES, '127.0.0.1', id)
        // The address of the client needs no count of the hops that append to X-Forwarded-For (the application drops
        // an entry API Gateway appended for its peer): no setting that differs between stages.
        assert.equal(environment.EDGE_FORWARDED_HOPS, undefined, id)
      }
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

    it('read only parameters the CDK app publishes: in prod also those of the network and of the database', () => {
      const names = published(stage)

      for (const [id, parameter] of Object.entries(body.Parameters)) {
        assert.equal(parameter.Type, 'AWS::SSM::Parameter::Value<String>', id)
        assert.ok(names.has(parameter.Default), `${id}: ${parameter.Default} is published`)
      }

      // Both stages read the secrets of the two identities; prod also the network and the managed database.
      const wanted = stage === 'prod' ? [...Object.values(NETWORK_PARAMETERS), ...Object.values(DATABASE_PARAMETERS)] : [DATABASE_PARAMETERS.secretArn, DATABASE_PARAMETERS.migrationsSecretArn]
      const read = Object.values(body.Parameters).map((parameter) => parameter.Default)
      assert.deepEqual(wanted.map((name) => `/dian-einvoice/${stage}/${name}`).filter((name) => !read.includes(name)), [], 'every parameter of the network and the database is read')

      for (const [id, fn] of functions) {
        assert.equal(fn.Properties.VpcConfig !== undefined, config.network !== null, `${id} runs in the private subnets in prod only`)
      }

      assert.equal(verifier?.[1].Properties.VpcConfig, undefined, 'the origin verifier runs outside the VPC')
      assert.equal(notifier?.[1].Properties.VpcConfig, undefined, 'the notifier runs outside the VPC')
    })

    it('give the migrations identity of the database to the console function only, and the application identity to every other one', () => {
      for (const [id, fn] of functions) {
        const environment = (fn.Properties.Environment as { Variables: Record<string, unknown> }).Variables
        const name = String(fn.Properties.FunctionName).replace(`dian-einvoice-${stage}-`, '')
        const secret = parameterOf(environment.DB_SECRETS_ID)
        assert.equal(body.Parameters[secret ?? '']?.Default, `/dian-einvoice/${stage}/${name === MIGRATIONS_FUNCTION ? DATABASE_PARAMETERS.migrationsSecretArn : DATABASE_PARAMETERS.secretArn}`, id)

        // The console provisions the application identity, so it reads that secret too; no other function
        // knows the migrations identity.
        const application = parameterOf(environment.DB_APPLICATION_SECRETS_ID)
        assert.equal(application === null ? null : body.Parameters[application]?.Default, name === MIGRATIONS_FUNCTION ? `/dian-einvoice/${stage}/${DATABASE_PARAMETERS.secretArn}` : null, id)

        // Every connection is encrypted with TLS, with the bundle of the RDS certificate authorities in the
        // image; prod also verifies the certificate of the server.
        assert.equal(environment.DB_SSL_CA, '/opt/rds/global-bundle.pem', id)
        assert.equal(environment.DB_SSL_VERIFY_SERVER_CERT, stage === 'prod' ? 'true' : 'false', id)
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
