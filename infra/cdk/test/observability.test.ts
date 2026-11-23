// The observability of both stages (Infrastructure task 5), on the synthesized templates and the template osls
// packaged for the functions: every metric filter reads fields of the schema of the log lines and lies on the log
// group of a function that exists; every alarm has its actions and a description that names its runbook; the topics
// of the alarms are encrypted with a key CloudWatch may use; the dashboard draws each function against its reserved
// concurrency; the saved queries read groups that exist, the one that follows a journey with commands the emulator
// evaluates; the objectives of service name alarms that exist; the firewall of prod logs what it blocks without
// secrets; and docs/observability.md names every metric and alarm.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, it } from 'node:test'
import { loadLogSchema, fieldExists } from '../lib/log-schema.ts'
import { APPLICATION_METRICS, CORRELATION_PLACEHOLDER, EMULATED_QUERY_COMMANDS, RUNBOOKS, SAVED_QUERIES, SERVICE_OBJECTIVES, logGroupOf, queryCommands } from '../lib/observability.ts'
import { RESERVED_CONCURRENCY, SAVED_QUERIES_METADATA } from '../lib/observability-stack.ts'
import { STAGES } from '../lib/stage.ts'
import { functionsTemplate, stageOf, template } from './support.ts'

const schema = loadLogSchema()

/** The alarms of every stage, by their short name; prod adds those of its managed database and the composite one. */
const ALARMS = ['chain-dead-letters', 'webhooks-dead-letters', 'chain-queue-age', 'webhooks-queue-age', 'function-errors', 'function-throttles', 'api-server-errors', 'api-latency', 'relay-not-running', 'outbox-lag', 'dian-error-rate', 'webhook-failures', 'origin-verifier-errors', 'notifier-failures']
const PROD_ALARMS = ['database-capacity', 'database-connections']

interface AlarmProperties {
  readonly AlarmName: string
  readonly AlarmDescription: string
  readonly AlarmActions: readonly unknown[]
  readonly OKActions: readonly unknown[]
  readonly ActionsEnabled: boolean
  readonly TreatMissingData?: string
  readonly Metrics?: readonly unknown[]
}

/** The fields a pattern of a metric filter names ($.event, $.detail.queue) and the events it asks for. */
function patternFields(pattern: string): { fields: string[], events: string[] } {
  return {
    fields: [...pattern.matchAll(/\$\.([A-Za-z0-9_.]+)/g)].map((match) => match[1] ?? ''),
    events: [...pattern.matchAll(/\$\.event\s*=\s*"([^"]+)"/g)].map((match) => match[1] ?? ''),
  }
}

describe('the metrics of the application', () => {
  it('are between 10 and 15, each with its reason, of events of the schema and fields it has', () => {
    assert.ok(APPLICATION_METRICS.length >= 10 && APPLICATION_METRICS.length <= 15, `${APPLICATION_METRICS.length} metrics`)
    assert.equal(new Set(APPLICATION_METRICS.map((metric) => metric.name)).size, APPLICATION_METRICS.length)

    for (const metric of APPLICATION_METRICS) {
      assert.ok(metric.why.length >= 60, `${metric.name} says why it is worth a metric`)
      assert.ok(metric.event in schema.events, `${metric.name}: the event ${metric.event}`)
      assert.ok(metric.outcome === undefined || (schema.fields.find((field) => field.name === 'outcome')?.values ?? []).includes(metric.outcome), `${metric.name}: the outcome ${metric.outcome}`)
    }
  })

  for (const stage of ['local', 'prod'] as const) {
    it(`in ${stage}, are metric filters whose patterns read only fields of the schema, on the log group of a function that exists`, () => {
      const filters = Object.values(template(stageOf(stage).observability).findResources('AWS::Logs::MetricFilter')) as { Properties: { LogGroupName: string, FilterPattern: string, MetricTransformations: { MetricName: string, MetricNamespace: string, MetricValue: string, Dimensions?: { Key: string, Value: string }[] }[] } }[]
      const groups = new Set(Object.values(functionsTemplate(stage).Resources).filter((resource) => resource.Type === 'AWS::Logs::LogGroup').map((resource) => String(resource.Properties.LogGroupName)))

      assert.equal(filters.length, APPLICATION_METRICS.reduce((sum, metric) => sum + metric.functions.length, 0))

      for (const filter of filters) {
        const [transformation] = filter.Properties.MetricTransformations
        assert.ok(transformation !== undefined)
        const { fields, events } = patternFields(filter.Properties.FilterPattern)
        const read = [...fields, ...patternFields(transformation.MetricValue).fields, ...(transformation.Dimensions ?? []).flatMap((dimension) => patternFields(dimension.Value).fields)]

        assert.ok(groups.has(filter.Properties.LogGroupName), `${transformation.MetricName}: ${filter.Properties.LogGroupName} is the log group of a function`)
        assert.equal(transformation.MetricNamespace, `dian-einvoice/${stage}`)
        assert.equal(events.length, 1, filter.Properties.FilterPattern)
        assert.ok(events.every((event) => event in schema.events), `${transformation.MetricName}: ${events.join(', ')}`)
        assert.deepEqual(read.filter((field) => !fieldExists(schema, field)), [], `${transformation.MetricName} reads fields of the schema only`)
        assert.match(transformation.MetricValue, /^(1|\$\.[a-z_.]+)$/)
      }
    })
  }
})

for (const stage of ['local', 'prod'] as const) {
  const stacks = stageOf(stage)
  const observability = template(stacks.observability)
  const parameters = (observability.toJSON() as { Parameters?: Record<string, { Default?: string }> }).Parameters ?? {}
  const parameterOf = (value: unknown): string | undefined => parameters[String((value as { Ref?: string }).Ref)]?.Default
  const alarms = (Object.values(observability.findResources('AWS::CloudWatch::Alarm')) as { Properties: AlarmProperties }[]).map((alarm) => alarm.Properties)

  describe(`the alarms of the stage ${stage}`, () => {
    it('are the ones the platform needs, few, each with an action on ALARM and on OK, and a description that names its runbook', () => {
      assert.deepEqual(alarms.map((alarm) => alarm.AlarmName).sort(), [...ALARMS, ...(stage === 'prod' ? PROD_ALARMS : [])].map((name) => `dian-einvoice-${stage}-${name}`).sort())

      for (const alarm of alarms) {
        assert.equal(alarm.ActionsEnabled, true, alarm.AlarmName)
        assert.ok(alarm.AlarmActions.length >= 1 && alarm.OKActions.length >= 1, `${alarm.AlarmName} has its actions`)
        assert.ok(alarm.AlarmActions.every((action) => /\/alerts\/alarms(-fallback)?-topic-arn$/.test(parameterOf(action) ?? '')), `${alarm.AlarmName} publishes to a topic of the alerts`)
        assert.ok(alarm.AlarmActions.some((action) => parameterOf(action) === `/dian-einvoice/${stage}/alerts/alarms-topic-arn`), `${alarm.AlarmName} publishes to the topic of the alarms`)
        const runbook = /Runbook: (docs\/runbooks\/[a-z-]+\.md)$/.exec(alarm.AlarmDescription)?.[1]
        assert.ok(runbook !== undefined && (Object.values(RUNBOOKS) as string[]).includes(runbook), `${alarm.AlarmName} names its runbook: ${alarm.AlarmDescription}`)
        assert.ok(alarm.AlarmDescription.length >= 80, `${alarm.AlarmName} says what it means`)
      }
    })

    it('write an alarm on one metric with the metric itself, not a query of metric data, which the CloudFormation of the emulator drops', () => {
      for (const alarm of alarms as (AlarmProperties & { MetricName?: string, Namespace?: string })[]) {
        const math = ['function-errors', 'function-throttles', 'dian-error-rate'].some((name) => alarm.AlarmName.endsWith(`-${name}`))
        assert.equal(alarm.Metrics !== undefined, math, alarm.AlarmName)
        assert.equal(alarm.MetricName !== undefined && alarm.Namespace !== undefined, !math, alarm.AlarmName)
      }
    })

    it('send the failures of the notifier to the fallback topic too, whose email does not depend on the notifier', () => {
      const notifier = alarms.find((alarm) => alarm.AlarmName === `dian-einvoice-${stage}-notifier-failures`)
      assert.deepEqual(notifier?.AlarmActions.map(parameterOf).sort(), [`/dian-einvoice/${stage}/alerts/alarms-fallback-topic-arn`, `/dian-einvoice/${stage}/alerts/alarms-topic-arn`])
      assert.equal(alarms.filter((alarm) => alarm.AlarmActions.length > 1).length, 1)
    })

    it('treat a relay that writes nothing as one that does not run', () => {
      assert.equal(alarms.find((alarm) => alarm.AlarmName === `dian-einvoice-${stage}-relay-not-running`)?.TreatMissingData, 'breaching')
      assert.ok(alarms.filter((alarm) => !alarm.AlarmName.endsWith('relay-not-running')).every((alarm) => alarm.TreatMissingData === 'notBreaching'))
    })

    it(stage === 'prod' ? 'add a composite alarm of a degraded service, with its actions' : 'have no composite alarm: the emulator runs none of its actions', () => {
      const composites = (Object.values(observability.findResources('AWS::CloudWatch::CompositeAlarm')) as { Properties: { AlarmName: string, AlarmRule: string, AlarmActions: unknown[], OKActions: unknown[], AlarmDescription: string } }[]).map((alarm) => alarm.Properties)
      assert.equal(composites.length, stage === 'prod' ? 1 : 0)

      if (stage === 'prod') {
        const [degraded] = composites
        assert.equal(degraded?.AlarmName, 'dian-einvoice-prod-service-degraded')
        assert.match(JSON.stringify(degraded?.AlarmRule), /ApiServerErrorsAlarm.*ApiLatencyAlarm.*ChainDeadLettersAlarm.*ChainQueueAgeAlarm.*DianErrorRateAlarm.*RelayNotRunningAlarm.*OriginVerifierErrorsAlarm.*FunctionErrorsAlarm/s)
        assert.equal((JSON.stringify(degraded?.AlarmRule).match(/ OR ALARM/g) ?? []).length, 7, 'any of the eight')
        assert.equal(degraded?.AlarmActions.length, 1)
        assert.equal(degraded?.OKActions.length, 1)
        assert.match(degraded?.AlarmDescription ?? '', /Runbook: docs\/runbooks\/service-degraded\.md$/)
      }
    })

    it('defend the objectives of service with alarms that exist', () => {
      const names = new Set([...alarms.map((alarm) => alarm.AlarmName), `dian-einvoice-${stage}-service-degraded`])

      for (const objective of SERVICE_OBJECTIVES) {
        for (const alarm of objective.alarms) {
          if (stage === 'prod' || !['database-capacity', 'database-connections', 'service-degraded'].includes(alarm)) {
            assert.ok(names.has(`dian-einvoice-${stage}-${alarm}`), `${objective.name}: ${alarm}`)
          }
        }
      }
    })

    it('publish to topics encrypted with a key of their own that CloudWatch may use, and that take alarms of this account only', () => {
      const alerts = template(stacks.alerts)
      const topics = Object.values(alerts.findResources('AWS::SNS::Topic')) as { Properties: { TopicName: string, KmsMasterKeyId: unknown } }[]
      assert.deepEqual(topics.map((topic) => topic.Properties.TopicName).sort(), [`dian-einvoice-${stage}-alarms`, `dian-einvoice-${stage}-alarms-fallback`])
      assert.ok(topics.every((topic) => JSON.stringify(topic.Properties.KmsMasterKeyId).includes('AlarmsKey')), 'encrypted with the key of the alarms')

      const key = Object.values(alerts.findResources('AWS::KMS::Key'))[0] as { Properties: { EnableKeyRotation: boolean, KeyPolicy: { Statement: { Principal?: { Service?: string }, Action: string[] | string }[] } } }
      assert.equal(key.Properties.EnableKeyRotation, true)
      assert.ok(key.Properties.KeyPolicy.Statement.some((statement) => statement.Principal?.Service === 'cloudwatch.amazonaws.com' && [statement.Action].flat().includes('kms:GenerateDataKey*')))

      const policies = Object.values(alerts.findResources('AWS::SNS::TopicPolicy')) as { Properties: { PolicyDocument: { Statement: { Sid?: string, Principal?: { Service?: string }, Condition?: Record<string, Record<string, unknown>> }[] } } }[]
      assert.equal(policies.length, 2)

      for (const policy of policies) {
        const alarmsOnly = policy.Properties.PolicyDocument.Statement.find((statement) => statement.Sid === 'AlarmsOfThisAccount')
        assert.equal(alarmsOnly?.Principal?.Service, 'cloudwatch.amazonaws.com')
        assert.ok(alarmsOnly?.Condition?.StringEquals?.['aws:SourceAccount'] !== undefined && alarmsOnly.Condition.ArnLike?.['aws:SourceArn'] !== undefined)
      }

      // The fallback email, a parameter of the deployment; the secret of the chat webhook, empty in prod.
      alerts.hasResourceProperties('AWS::SNS::Subscription', { Protocol: 'email', Endpoint: STAGES[stage].alerts.fallbackEmail })
      const secret = Object.values(alerts.findResources('AWS::SecretsManager::Secret'))[0] as { Properties: { Name: string, SecretString: string } }
      assert.equal(secret.Properties.Name, `dian-einvoice/${stage}/alerts/chat-webhook`)
      assert.deepEqual(JSON.parse(secret.Properties.SecretString), { url: STAGES[stage].alerts.chatWebhookUrl })
      assert.equal(STAGES[stage].alerts.chatWebhookUrl === '', stage === 'prod', 'prod never holds the address of a webhook in a template')
    })
  })

  describe(`the dashboard and the saved queries of the stage ${stage}`, () => {
    it('draw one operations dashboard, with the concurrency of each function against its reserved concurrency', () => {
      const dashboards = Object.values(observability.findResources('AWS::CloudWatch::Dashboard')) as { Properties: { DashboardName: string, DashboardBody: unknown } }[]
      assert.equal(dashboards.length, 1)
      assert.equal(dashboards[0]?.Properties.DashboardName, `dian-einvoice-${stage}-operations`)
      const body = JSON.stringify(dashboards[0]?.Properties.DashboardBody)

      for (const title of ['API requests and errors', 'API latency', 'Edge: requests', 'Documents issued, accepted and rejected', 'Queues: messages waiting', 'Dead-letter queues', 'DIAN calls', 'Outbox: relay runs', 'Function errors and throttles', 'Concurrency: api', ...(stage === 'prod' ? ['Database: capacity', 'Database: connections', 'Firewall', 'Outbound traffic'] : [])]) {
        assert.ok(body.includes(title), `the dashboard shows ${title}`)
      }

      // The limits it draws are those the serverless service sets.
      const reserved = Object.fromEntries(Object.values(functionsTemplate(stage).Resources).filter((resource) => resource.Type === 'AWS::Lambda::Function').map((fn) => [String(fn.Properties.FunctionName).replace(`dian-einvoice-${stage}-`, ''), Number(fn.Properties.ReservedConcurrentExecutions)]))
      assert.deepEqual(reserved, { ...RESERVED_CONCURRENCY })
    })

    it('save the queries of an operator over log groups that exist, the one that follows a journey in the commands the emulator evaluates', () => {
      // Resources in prod; in the metadata of the template locally, whose CloudFormation cannot create them.
      const resources = (Object.values(observability.findResources('AWS::Logs::QueryDefinition')) as { Properties: { Name: string, QueryString: string, LogGroupNames: string[] } }[]).map((query) => query.Properties)
      const metadata = ((observability.toJSON() as { Metadata?: Record<string, unknown> }).Metadata?.[SAVED_QUERIES_METADATA] ?? []) as { name: string, queryString: string, logGroupNames: string[] }[]
      assert.equal(resources.length > 0, STAGES[stage].observability.savedQueries === 'resources')
      assert.equal(metadata.length > 0, STAGES[stage].observability.savedQueries === 'template-metadata')
      const queries = [...resources, ...metadata.map((query) => ({ Name: query.name, QueryString: query.queryString, LogGroupNames: query.logGroupNames }))]
      const groups = new Set(Object.values(functionsTemplate(stage).Resources).filter((resource) => resource.Type === 'AWS::Logs::LogGroup').map((resource) => String(resource.Properties.LogGroupName)))
      assert.deepEqual(queries.map((query) => query.Name).sort(), SAVED_QUERIES.map((query) => `dian-einvoice/${stage}/${query.name}`).sort())

      for (const query of queries) {
        assert.ok(query.LogGroupNames.length > 0 && query.LogGroupNames.every((group) => groups.has(group)), query.Name)

        for (const field of [...query.QueryString.matchAll(/\b(detail\.[a-z_]+|correlation_id|duration_ms|document_id|event|outcome|level|function)\b/g)].map((match) => match[1] ?? '')) {
          assert.ok(fieldExists(schema, field), `${query.Name} reads ${field}, a field of the schema`)
        }
      }

      const follow = queries.find((query) => query.Name.endsWith('/follow-a-journey'))
      assert.ok(follow !== undefined)
      assert.ok(queryCommands(follow.QueryString).every((command) => (EMULATED_QUERY_COMMANDS as readonly string[]).includes(command)), follow.QueryString)
      assert.ok(follow.QueryString.includes(`correlation_id = "${CORRELATION_PLACEHOLDER}"`))
      assert.deepEqual(['api', 'chain', 'webhooks'].filter((fn) => !follow.LogGroupNames.includes(logGroupOf(STAGES[stage], fn as 'api'))), [])
    })
  })
}

describe('the firewall of prod', () => {
  it('logs the requests it blocked or counted, without the headers that carry keys and sessions', () => {
    const edge = template(stageOf('prod').edge)
    edge.hasResourceProperties('AWS::Logs::LogGroup', { LogGroupName: 'aws-waf-logs-dian-einvoice-prod-edge', RetentionInDays: 365 })
    const logging = Object.values(edge.findResources('AWS::WAFv2::LoggingConfiguration'))[0] as { Properties: { RedactedFields: { SingleHeader: { Name: string } }[], LoggingFilter: { DefaultBehavior: string, Filters: { Conditions: { ActionCondition: { Action: string } }[] }[] } } }
    assert.deepEqual(logging.Properties.RedactedFields.map((field) => field.SingleHeader.Name).sort(), ['authorization', 'cookie'])
    assert.equal(logging.Properties.LoggingFilter.DefaultBehavior, 'DROP')
    assert.deepEqual(logging.Properties.LoggingFilter.Filters.flatMap((filter) => filter.Conditions.map((condition) => condition.ActionCondition.Action)).sort(), ['BLOCK', 'COUNT'])
    edge.hasResourceProperties('AWS::Logs::ResourcePolicy', { PolicyName: 'aws-waf-logs-dian-einvoice-prod-edge-delivery' })
    assert.equal(Object.keys(template(stageOf('local').edge).findResources('AWS::WAFv2::LoggingConfiguration')).length, 0)
  })
})

describe('docs/observability.md', () => {
  it('names every metric, alarm and saved query', () => {
    const docs = readFileSync(join(process.env.DOCS ?? join(import.meta.dirname, '../../../docs'), 'observability.md'), 'utf8')

    for (const name of [...APPLICATION_METRICS.map((metric) => metric.name), ...ALARMS, ...PROD_ALARMS, 'service-degraded', ...SAVED_QUERIES.map((query) => query.name)]) {
      assert.ok(docs.includes(`\`${name}\``), `docs/observability.md names ${name}`)
    }
  })
})
