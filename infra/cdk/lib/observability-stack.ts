// Observability, deployed after the functions and the edge, whose log groups, HTTP API and
// distribution it reads: the metrics of the application, as metric filters over the structured log lines of the
// functions (lib/observability.ts); the alarms, few and each with its runbook, which publish every change of state to
// the topic of the stack alerts; in prod a composite alarm of a degraded service; one operations dashboard; and the
// saved queries of CloudWatch Logs Insights. The native metrics of AWS (Lambda, SQS, API Gateway, CloudFront, WAF,
// Aurora, the NAT gateway) cover what the logs do not. The reasons of each threshold are in docs/observability.md.
import { Duration, Stack, type StackProps } from 'aws-cdk-lib'
import {
  Alarm,
  AlarmRule,
  AlarmState,
  AlarmStatusWidget,
  ComparisonOperator,
  CompositeAlarm,
  Dashboard,
  GraphWidget,
  MathExpression,
  Metric,
  SingleValueWidget,
  TextWidget,
  TreatMissingData,
  type HorizontalAnnotation,
  type IAlarm,
  type IMetric,
  type IWidget,
} from 'aws-cdk-lib/aws-cloudwatch'
import { SnsAction } from 'aws-cdk-lib/aws-cloudwatch-actions'
import { CfnQueryDefinition, LogGroup, MetricFilter, type ILogGroup } from 'aws-cdk-lib/aws-logs'
import { Topic, type ITopic } from 'aws-cdk-lib/aws-sns'
import { StringParameter } from 'aws-cdk-lib/aws-ssm'
import type { Construct } from 'constructs'
import { fieldExists, type LogSchema } from './log-schema.ts'
import {
  ALL_FUNCTIONS,
  APPLICATION_METRICS,
  RUNBOOKS,
  SAVED_QUERIES,
  alarmDescription,
  filterPatternOf,
  logGroupOf,
  metricFields,
  metricNamespace,
  type AnyFunction,
  type ApplicationMetric,
} from './observability.ts'
import { HTTP_API_PARAMETER, PROJECT, parameterPrefix, resourceName, type StageConfig } from './stage.ts'

export interface ObservabilityStackProps extends StackProps {
  readonly stage: StageConfig
  readonly schema: LogSchema
}

/**
 * The reserved concurrency of each function, which the serverless service sets (infra/functions/serverless.yml):
 * the limits the dashboard draws the concurrency of each function against. A test holds both to each other.
 */
export const RESERVED_CONCURRENCY: Readonly<Record<AnyFunction, number>> = { api: 40, chain: 10, webhooks: 5, relay: 1, scheduler: 2, console: 2, 'origin-verifier': 10, notifier: 2 }

/** The key of the metadata of the template that holds the saved queries where they are not resources. */
export const SAVED_QUERIES_METADATA = 'dian-einvoice:saved-queries'

/** The capacity of the managed database the alarms of the database compare with (stack database, prod). */
const DATABASE_CONNECTIONS_AT_MAX = 135

export class ObservabilityStack extends Stack {
  readonly alarms: Readonly<Record<string, IAlarm>>
  readonly metricFilters: readonly MetricFilter[]
  readonly dashboard: Dashboard

  private readonly stage: StageConfig
  private readonly logGroups = new Map<AnyFunction, ILogGroup>()

  constructor(scope: Construct, id: string, props: ObservabilityStackProps) {
    super(scope, id, props)
    const { stage, schema } = props
    this.stage = stage

    // Every field a metric reads is a field of the schema of the log lines, or synthesis stops.
    for (const metric of APPLICATION_METRICS) {
      const missing = metricFields(metric).filter((field) => !fieldExists(schema, field))

      if (missing.length > 0 || !(metric.event in schema.events)) {
        throw new Error(`The metric ${metric.name} reads ${[...missing, ...(metric.event in schema.events ? [] : [`the event ${metric.event}`])].join(', ')}, which the schema of the log lines does not have.`)
      }
    }

    // The metrics of the application: one metric filter per function that writes the event of each metric.
    const filters: MetricFilter[] = []

    for (const metric of APPLICATION_METRICS) {
      for (const fn of metric.functions) {
        filters.push(new MetricFilter(this, `${metric.name}${pascal(fn)}Filter`, {
          logGroup: this.logGroup(fn),
          filterName: `${metric.name}`,
          filterPattern: filterPatternOf(metric),
          metricNamespace: metricNamespace(stage),
          metricName: metric.name,
          metricValue: metric.value === '1' ? '1' : `$.${metric.value}`,
          unit: metric.unit,
          ...(metric.dimensions === undefined ? {} : { dimensions: Object.fromEntries(Object.entries(metric.dimensions).map(([name, field]) => [name, `$.${field}`])) }),
        }))
      }
    }

    this.metricFilters = filters

    // Every alarm publishes its changes of state, to ALARM and back to OK, to the encrypted topic of the stack alerts.
    const prefix = parameterPrefix(stage)
    const topic = Topic.fromTopicArn(this, 'AlarmsTopic', StringParameter.valueForStringParameter(this, `${prefix}/alerts/alarms-topic-arn`))
    const fallback = Topic.fromTopicArn(this, 'AlarmsFallbackTopic', StringParameter.valueForStringParameter(this, `${prefix}/alerts/alarms-fallback-topic-arn`))
    const apiId = StringParameter.valueForStringParameter(this, `${prefix}/${HTTP_API_PARAMETER}`)
    const distributionId = StringParameter.valueForStringParameter(this, `${prefix}/edge/distribution-id`)
    const alarms: Record<string, IAlarm> = {}
    const alarm = (short: string, settings: AlarmSettings, actions: readonly ITopic[] = [topic]): Alarm => {
      const created = new Alarm(this, `${pascal(short)}Alarm`, {
        alarmName: resourceName(stage, short),
        alarmDescription: alarmDescription(settings.meaning, settings.runbook),
        metric: settings.metric,
        threshold: settings.threshold,
        comparisonOperator: settings.comparison,
        evaluationPeriods: settings.evaluationPeriods,
        datapointsToAlarm: settings.datapointsToAlarm ?? settings.evaluationPeriods,
        treatMissingData: settings.treatMissingData ?? TreatMissingData.NOT_BREACHING,
        actionsEnabled: true,
      })

      for (const action of actions) {
        created.addAlarmAction(new SnsAction(action))
        created.addOkAction(new SnsAction(action))
      }

      alarms[short] = created

      return created
    }

    // The queues: a message in a dead-letter queue is work that will not happen by itself; a message that waits
    // longer than every retry of its queue is a chain that stopped.
    for (const channel of ['chain', 'webhooks'] as const) {
      alarm(`${channel}-dead-letters`, {
        meaning: `A message reached the ${channel} queue's dead-letter queue: a job failed every attempt, or SNS could not deliver an event, and nothing will retry it.`,
        runbook: RUNBOOKS.deadLetters,
        metric: this.queueMetric(`${channel}-dlq`, 'ApproximateNumberOfMessagesVisible', 'Maximum', Duration.minutes(1)),
        threshold: 0,
        comparison: ComparisonOperator.GREATER_THAN_THRESHOLD,
        evaluationPeriods: 1,
      })
      alarm(`${channel}-queue-age`, {
        meaning: `For 5 minutes, the ${channel} queue's oldest message has waited more than 10 minutes, longer than any retry of its jobs: its consumer does not take it.`,
        runbook: RUNBOOKS.queueBacklog,
        metric: this.queueMetric(channel, 'ApproximateAgeOfOldestMessage', 'Maximum', Duration.minutes(1)),
        threshold: 600,
        comparison: ComparisonOperator.GREATER_THAN_THRESHOLD,
        evaluationPeriods: 5,
      })
    }

    // The functions: an invocation that failed (a crash, a timeout, a command that exited with an error) or one
    // Lambda refused at the reserved concurrency, in any function.
    for (const [short, metricName, meaning, runbook] of [
      ['function-errors', 'Errors', 'A function invocation failed (a crash, a timeout, a scheduled command that exited with an error): partial batch failures and HTTP errors are not counted here.', RUNBOOKS.functionErrors],
      ['function-throttles', 'Throttles', "Lambda refused a function's invocations at its reserved concurrency: requests got 429 or 503, or messages wait.", RUNBOOKS.functionThrottles],
    ] as const) {
      alarm(short, {
        meaning,
        runbook,
        metric: new MathExpression({
          expression: `SUM([${ALL_FUNCTIONS.map((_, index) => `m${index}`).join(', ')}])`,
          usingMetrics: Object.fromEntries(ALL_FUNCTIONS.map((fn, index) => [`m${index}`, this.lambdaMetric(fn, metricName, 'Sum', Duration.minutes(5))])),
          label: `${metricName} of every function`,
          period: Duration.minutes(5),
        }),
        threshold: 1,
        comparison: ComparisonOperator.GREATER_THAN_OR_EQUAL_TO_THRESHOLD,
        evaluationPeriods: 1,
      })
    }

    // The API, at API Gateway: platform responses that failed, and responses too slow for the objective.
    alarm('api-server-errors', {
      meaning: 'The API returned 5 or more 5xx responses in 5 minutes: requests from the web app and the integrations are failing.',
      runbook: RUNBOOKS.apiErrors,
      metric: this.apiMetric(apiId, '5xx', 'Sum', Duration.minutes(5)),
      threshold: 5,
      comparison: ComparisonOperator.GREATER_THAN_OR_EQUAL_TO_THRESHOLD,
      evaluationPeriods: 1,
    })
    alarm('api-latency', {
      meaning: "For 15 minutes, the 99th percentile of the API's response time has been above 2 seconds, twice the objective.",
      runbook: RUNBOOKS.apiLatency,
      metric: this.apiMetric(apiId, 'Latency', 'p99', Duration.minutes(5)),
      threshold: 2000,
      comparison: ComparisonOperator.GREATER_THAN_THRESHOLD,
      evaluationPeriods: 3,
    })

    // The outbox: the relay that does not run, and events that wait although it runs.
    alarm('relay-not-running', {
      meaning: 'The relay has not run for 3 minutes: events the fast path leaves behind wait, and their chains stop.',
      runbook: RUNBOOKS.outboxRelay,
      metric: this.applicationMetric('RelayRuns', Duration.minutes(1)),
      threshold: 1,
      comparison: ComparisonOperator.LESS_THAN_THRESHOLD,
      evaluationPeriods: 3,
      treatMissingData: TreatMissingData.BREACHING,
    })
    alarm('outbox-lag', {
      meaning: 'For 2 runs of the relay, an outbox event has waited more than 2 minutes to be published: the topic refuses events, or the relay cannot keep up.',
      runbook: RUNBOOKS.outboxRelay,
      metric: this.applicationMetric('OutboxLag', Duration.minutes(1)),
      threshold: 120,
      comparison: ComparisonOperator.GREATER_THAN_THRESHOLD,
      evaluationPeriods: 2,
    })

    // The DIAN: the share of calls without a validation result, once there are enough calls to tell.
    alarm('dian-error-rate', {
      meaning: 'For 10 minutes, more than 20% of the calls to the DIAN in 5 minutes (5 calls or more) got no validation result: the DIAN, or the way out to it, is failing, and the chain waits on its retries.',
      runbook: RUNBOOKS.dianErrors,
      metric: new MathExpression({
        expression: 'IF(calls >= 5, 100 * errors / calls, 0)',
        usingMetrics: { calls: this.applicationMetric('DianCalls', Duration.minutes(5)), errors: this.applicationMetric('DianErrors', Duration.minutes(5)) },
        label: 'Calls to the DIAN without a result (%)',
        period: Duration.minutes(5),
      }),
      threshold: 20,
      comparison: ComparisonOperator.GREATER_THAN_THRESHOLD,
      evaluationPeriods: 2,
    })

    alarm('webhook-failures', {
      meaning: "20 or more webhook deliveries failed in 15 minutes: the companies' endpoints, or the platform's way out, are failing.",
      runbook: RUNBOOKS.webhookFailures,
      metric: this.applicationMetric('WebhookDeliveriesFailed', Duration.minutes(15)),
      threshold: 20,
      comparison: ComparisonOperator.GREATER_THAN_OR_EQUAL_TO_THRESHOLD,
      evaluationPeriods: 1,
    })

    alarm('origin-verifier-errors', {
      meaning: 'The origin verifier could not read the edge secret: once the values it holds expire, every request through the edge is refused.',
      runbook: RUNBOOKS.originVerifier,
      metric: this.applicationMetric('OriginVerifierFailures', Duration.minutes(1)),
      threshold: 1,
      comparison: ComparisonOperator.GREATER_THAN_OR_EQUAL_TO_THRESHOLD,
      evaluationPeriods: 1,
    })

    // The notifier: a notification it could not deliver. This one also goes to the fallback topic, whose email does
    // not depend on the notifier.
    alarm('notifier-failures', {
      meaning: 'The notifier could not deliver an alarm notification to the chat: it waits in its dead-letter queue, and the chat misses alarms.',
      runbook: RUNBOOKS.notifier,
      metric: this.queueMetric('notifier-dlq', 'ApproximateNumberOfMessagesVisible', 'Maximum', Duration.minutes(1)),
      threshold: 0,
      comparison: ComparisonOperator.GREATER_THAN_THRESHOLD,
      evaluationPeriods: 1,
    }, [topic, fallback])

    // The managed database (prod): its capacity and its connections, near the budget of the stage.
    const managed = stage.database.managed

    if (managed !== null) {
      const cluster = resourceName(stage, 'database')
      alarm('database-capacity', {
        meaning: `For 15 minutes, the database has used more than ${(managed.maxCapacity * 0.875).toFixed(1)} of its ${managed.maxCapacity} ACU: it is about to stop growing, and max_connections is tied to its maximum.`,
        runbook: RUNBOOKS.database,
        metric: this.databaseMetric(cluster, 'ServerlessDatabaseCapacity', 'Maximum', Duration.minutes(5)),
        threshold: managed.maxCapacity * 0.875,
        comparison: ComparisonOperator.GREATER_THAN_OR_EQUAL_TO_THRESHOLD,
        evaluationPeriods: 3,
      })
      alarm('database-connections', {
        meaning: `For 5 minutes, the database has held more than 80% of its ${DATABASE_CONNECTIONS_AT_MAX} connections: new connections from the functions, the rotation functions and the operators are about to be refused.`,
        runbook: RUNBOOKS.database,
        metric: this.databaseMetric(cluster, 'DatabaseConnections', 'Maximum', Duration.minutes(1)),
        threshold: Math.floor(DATABASE_CONNECTIONS_AT_MAX * 0.8),
        comparison: ComparisonOperator.GREATER_THAN_OR_EQUAL_TO_THRESHOLD,
        evaluationPeriods: 5,
      })

      // One alarm that says the service is degraded, whatever part of it: the one to page on.
      const degraded = new CompositeAlarm(this, 'ServiceDegradedAlarm', {
        compositeAlarmName: resourceName(stage, 'service-degraded'),
        alarmDescription: alarmDescription('The service is degraded: the API fails or is slow, the chain does not move, or the DIAN fails. The alarms in ALARM below it say where.', RUNBOOKS.serviceDegraded),
        alarmRule: AlarmRule.anyOf(...['api-server-errors', 'api-latency', 'chain-dead-letters', 'chain-queue-age', 'dian-error-rate', 'relay-not-running', 'origin-verifier-errors', 'function-errors'].map((name) => AlarmRule.fromAlarm(alarms[name] as IAlarm, AlarmState.ALARM))),
        actionsEnabled: true,
      })
      degraded.addAlarmAction(new SnsAction(topic))
      degraded.addOkAction(new SnsAction(topic))
      alarms['service-degraded'] = degraded
    }

    this.alarms = alarms

    // The saved queries of Logs Insights, over the log groups of the functions each one reads: resources of the stack,
    // or, where the CloudFormation of the emulator cannot create them, the metadata of its template, which make
    // cloud-up saves through the API of Logs (lib/stage.ts, SavedQueries).
    const queries = SAVED_QUERIES.map((query) => ({ name: `${PROJECT}/${stage.name}/${query.name}`, queryString: query.query, logGroupNames: query.functions.map((fn) => logGroupOf(stage, fn)) }))

    if (stage.observability.savedQueries === 'resources') {
      for (const [index, query] of queries.entries()) {
        new CfnQueryDefinition(this, `${pascal(SAVED_QUERIES[index]?.name ?? String(index))}Query`, query)
      }
    } else {
      this.templateOptions.metadata = { ...this.templateOptions.metadata, [SAVED_QUERIES_METADATA]: queries }
    }

    this.dashboard = new Dashboard(this, 'Operations', {
      dashboardName: resourceName(stage, 'operations'),
      defaultInterval: Duration.hours(3),
      widgets: this.widgets(apiId, distributionId, Object.values(alarms)),
    })
  }

  /** The rows of the operations dashboard. */
  private widgets(apiId: string, distributionId: string, alarms: readonly IAlarm[]): IWidget[][] {
    const minutes = Duration.minutes(5)
    const stage = this.stage
    const lambda = (metricName: string, statistic: string) => ALL_FUNCTIONS.map((fn) => this.lambdaMetric(fn, metricName, statistic, minutes).with({ label: fn }))
    const api = (metricName: string, statistic: string) => this.apiMetric(apiId, metricName, statistic, minutes).with({ label: `${metricName} ${statistic}` })
    const queue = (name: string, metricName: string) => this.queueMetric(name, metricName, 'Maximum', minutes).with({ label: name })

    const rows: IWidget[][] = [
      [new TextWidget({ markdown: `# dian-einvoice (${stage.name}): operations\nAPI and edge traffic, errors and latency; documents through the chain; queues; the DIAN; the outbox; functions against their limits${stage.database.managed === null ? '' : '; the database and outbound traffic'}. The alarms and their runbooks are in docs/observability.md.`, width: 24, height: 2 })],
      [new AlarmStatusWidget({ title: 'Alarms', alarms: [...alarms], width: 24, height: 4 })],
      [
        new GraphWidget({ title: 'API requests and errors (API Gateway)', left: [api('Count', 'Sum')], right: [api('4xx', 'Sum'), api('5xx', 'Sum')], width: 8 }),
        new GraphWidget({ title: 'API latency (ms)', left: ['p50', 'p95', 'p99'].map((statistic) => api('Latency', statistic)), leftAnnotations: [{ value: 1000, label: 'objective: 99% within 1 s' }], width: 8 }),
        new GraphWidget({
          title: 'Edge: requests and error rate (CloudFront)',
          left: [this.edgeMetric(distributionId, 'Requests', 'Sum')],
          right: [this.edgeMetric(distributionId, '4xxErrorRate', 'Average'), this.edgeMetric(distributionId, '5xxErrorRate', 'Average')],
          width: 8,
        }),
      ],
      [
        new GraphWidget({ title: 'Documents issued, accepted and rejected', left: ['DocumentsIssued', 'DocumentsAccepted', 'DocumentsRejected'].map((name) => this.applicationMetric(name, minutes)), width: 8 }),
        new GraphWidget({ title: 'Chain job duration, p95 (ms)', left: [new MathExpression({ expression: `SEARCH('{${metricNamespace(stage)},Job} MetricName="ChainStepDuration"', 'p95', 300)`, label: 'Job', period: minutes })], width: 8 }),
        new GraphWidget({ title: 'DIAN calls, errors and latency', left: [this.applicationMetric('DianCalls', minutes), this.applicationMetric('DianErrors', minutes)], right: [this.applicationMetric('DianLatency', minutes)], width: 8 }),
      ],
      [
        new GraphWidget({ title: 'Queues: messages waiting and oldest message age (s)', left: ['chain', 'webhooks'].map((name) => queue(name, 'ApproximateNumberOfMessagesVisible')), right: ['chain', 'webhooks'].map((name) => queue(name, 'ApproximateAgeOfOldestMessage')), width: 12 }),
        new GraphWidget({ title: 'Dead-letter queues', left: ['chain-dlq', 'webhooks-dlq', 'notifier-dlq'].map((name) => queue(name, 'ApproximateNumberOfMessagesVisible')), leftAnnotations: [{ value: 0, label: 'anything above is an alarm' }], width: 12 }),
      ],
      [
        new GraphWidget({ title: 'Outbox: relay runs, events the relay published, lag (s)', left: [this.applicationMetric('RelayRuns', minutes), this.applicationMetric('RelayPublishedEvents', minutes)], right: [this.applicationMetric('OutboxLag', minutes)], width: 8 }),
        new GraphWidget({ title: 'Failed webhooks, refused authentications, secret rereads', left: ['WebhookDeliveriesFailed', 'AuthenticationFailures', 'SecretRereads', 'OriginVerifierFailures'].map((name) => this.applicationMetric(name, minutes)), width: 8 }),
        new SingleValueWidget({ title: 'Last hour', metrics: ['DocumentsIssued', 'DocumentsAccepted', 'DocumentsRejected'].map((name) => this.applicationMetric(name, Duration.hours(1))), width: 8 }),
      ],
      [
        new GraphWidget({ title: 'Function invocations', left: lambda('Invocations', 'Sum'), width: 8 }),
        new GraphWidget({ title: 'Function errors and throttles', left: lambda('Errors', 'Sum'), right: lambda('Throttles', 'Sum'), width: 8 }),
        new GraphWidget({ title: 'Function duration, p95 (ms)', left: lambda('Duration', 'p95'), width: 8 }),
      ],
      ALL_FUNCTIONS.map((fn) => new GraphWidget({
        title: `Concurrency: ${fn}`,
        left: [this.lambdaMetric(fn, 'ConcurrentExecutions', 'Maximum', Duration.minutes(1)).with({ label: fn })],
        leftAnnotations: [{ value: RESERVED_CONCURRENCY[fn], label: 'reserved concurrency' } satisfies HorizontalAnnotation],
        width: 3,
        height: 4,
      })),
    ]

    const managed = stage.database.managed

    if (managed !== null) {
      const cluster = resourceName(stage, 'database')
      rows.push([
        new GraphWidget({ title: 'Database: capacity (ACU)', left: [this.databaseMetric(cluster, 'ServerlessDatabaseCapacity', 'Maximum', minutes).with({ label: 'ACU' })], leftAnnotations: [{ value: managed.maxCapacity, label: 'maximum capacity' }], width: 6 }),
        new GraphWidget({ title: 'Database: connections', left: [this.databaseMetric(cluster, 'DatabaseConnections', 'Maximum', minutes).with({ label: 'connections' })], leftAnnotations: [{ value: DATABASE_CONNECTIONS_AT_MAX, label: 'max_connections at the maximum' }], width: 6 }),
        new GraphWidget({ title: 'Firewall: requests blocked and allowed (AWS WAF)', left: ['BlockedRequests', 'AllowedRequests'].map((metricName) => new Metric({ namespace: 'AWS/WAFV2', metricName, dimensionsMap: { WebACL: resourceName(stage, 'edge'), Rule: 'ALL' }, statistic: 'Sum', period: minutes, region: 'us-east-1' })), width: 6 }),
        new GraphWidget({
          title: 'Outbound traffic: bytes to the internet and port errors (NAT gateway)',
          left: [new MathExpression({ expression: 'SEARCH(\'{AWS/NATGateway,NatGatewayId} MetricName="BytesOutToDestination"\', \'Sum\', 300)', label: 'Bytes out', period: minutes })],
          right: [new MathExpression({ expression: 'SEARCH(\'{AWS/NATGateway,NatGatewayId} MetricName="ErrorPortAllocation"\', \'Sum\', 300)', label: 'Port allocation errors', period: minutes })],
          width: 6,
        }),
      ])
    }

    return rows
  }

  private logGroup(fn: AnyFunction): ILogGroup {
    let group = this.logGroups.get(fn)

    if (group === undefined) {
      group = LogGroup.fromLogGroupName(this, `${pascal(fn)}LogGroup`, logGroupOf(this.stage, fn))
      this.logGroups.set(fn, group)
    }

    return group
  }

  private applicationMetric(name: ApplicationMetric['name'], period: Duration): Metric {
    const metric = APPLICATION_METRICS.find((candidate) => candidate.name === name)

    if (metric === undefined) {
      throw new Error(`No metric ${name} of the application.`)
    }

    return new Metric({ namespace: metricNamespace(this.stage), metricName: name, statistic: metric.statistic, period, unit: metric.unit })
  }

  // The metrics of alarms carry no label: an alarm on a labelled metric is written as a query of metric data, which
  // the CloudFormation of the emulator drops (docs/limitations.md); the dashboard labels its own copies.
  private queueMetric(queue: string, metricName: string, statistic: string, period: Duration): Metric {
    return new Metric({ namespace: 'AWS/SQS', metricName, dimensionsMap: { QueueName: resourceName(this.stage, queue) }, statistic, period })
  }

  private lambdaMetric(fn: AnyFunction, metricName: string, statistic: string, period: Duration): Metric {
    return new Metric({ namespace: 'AWS/Lambda', metricName, dimensionsMap: { FunctionName: resourceName(this.stage, fn) }, statistic, period })
  }

  private apiMetric(apiId: string, metricName: string, statistic: string, period: Duration): Metric {
    return new Metric({ namespace: 'AWS/ApiGateway', metricName, dimensionsMap: { ApiId: apiId }, statistic, period })
  }

  private edgeMetric(distributionId: string, metricName: string, statistic: string): IMetric {
    return new Metric({ namespace: 'AWS/CloudFront', metricName, dimensionsMap: { DistributionId: distributionId, Region: 'Global' }, statistic, period: Duration.minutes(5), region: 'us-east-1', label: metricName })
  }

  private databaseMetric(cluster: string, metricName: string, statistic: string, period: Duration): Metric {
    return new Metric({ namespace: 'AWS/RDS', metricName, dimensionsMap: { DBClusterIdentifier: cluster }, statistic, period })
  }
}

interface AlarmSettings {
  readonly meaning: string
  readonly runbook: string
  readonly metric: IMetric
  readonly threshold: number
  readonly comparison: ComparisonOperator
  readonly evaluationPeriods: number
  readonly datapointsToAlarm?: number
  readonly treatMissingData?: TreatMissingData
}

/** chain → Chain, origin-verifier → OriginVerifier, chain-dead-letters → ChainDeadLetters: logical ids. */
function pascal(name: string): string {
  return name.split('-').map((part) => part.charAt(0).toUpperCase() + part.slice(1)).join('')
}
