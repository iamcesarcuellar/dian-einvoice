// What the observability of the platform is made of, as data the stack observability builds and the tests and
// docs/observability.md check: the metrics of the application (each one a metric filter over the structured log
// lines of the functions that write its event: the application calls no API to publish a metric), the runbooks the
// alarms name (written in Infrastructure task 6), the objectives of service the alarms defend, and the saved queries
// of CloudWatch Logs Insights. The fields every filter and query reads are fields of the schema of the log lines
// (backend/resources/observability/log-schema.json), which a test holds them to.
import { Unit } from 'aws-cdk-lib/aws-cloudwatch'
import { FilterPattern, type IFilterPattern } from 'aws-cdk-lib/aws-logs'
import { FUNCTIONS, NOTIFIER, ORIGIN_VERIFIER, PROJECT, type FunctionName, type StageConfig } from './stage.ts'

/** Every function of the serverless service, the TypeScript ones included, each with its log group. */
export const ALL_FUNCTIONS = [...FUNCTIONS, ORIGIN_VERIFIER, NOTIFIER] as const

export type AnyFunction = (typeof ALL_FUNCTIONS)[number]

/** The namespace of the metrics of the application of a stage. */
export function metricNamespace(stage: StageConfig): string {
  return `${PROJECT}/${stage.name}`
}

/** The log group of a function, which the serverless service creates with its retention. */
export function logGroupOf(stage: StageConfig, fn: AnyFunction): string {
  return `/aws/lambda/${PROJECT}-${stage.name}-${fn}`
}

/** A metric of the application, from the lines of one event of the schema. */
export interface ApplicationMetric {
  readonly name: string
  // What it tells and why it is worth a metric (docs/observability.md).
  readonly why: string
  readonly event: string
  // The outcome of the schema it counts, when it counts one.
  readonly outcome?: string
  // More fields that must hold a value, by their path in the line: { 'detail.queue': 'chain' }.
  readonly where?: Readonly<Record<string, string>>
  // 1 for each line, or a numeric field of the line.
  readonly value: '1' | 'duration_ms' | 'detail.lag_seconds'
  readonly unit: Unit
  // The statistic it is read with on the dashboard and by its alarm.
  readonly statistic: 'Sum' | 'Maximum' | 'p95'
  // The functions that write the event: one metric filter on the log group of each.
  readonly functions: readonly AnyFunction[]
  // Dimensions from fields of the line: { Job: 'detail.job' }.
  readonly dimensions?: Readonly<Record<string, string>>
}

const PHP: readonly FunctionName[] = FUNCTIONS

export const APPLICATION_METRICS: readonly ApplicationMetric[] = [
  { name: 'DocumentsIssued', why: 'The business volume: invoices and notes numbered and sent into the chain. Zero during business hours means the API or the companies cannot issue.', event: 'document.issued', value: '1', unit: Unit.COUNT, statistic: 'Sum', functions: ['api', 'console'] },
  { name: 'DocumentsAccepted', why: 'Documents the DIAN accepted: what the customers of the companies receive. It should follow DocumentsIssued within minutes.', event: 'dian.response', outcome: 'accepted', value: '1', unit: Unit.COUNT, statistic: 'Sum', functions: ['chain', 'console'] },
  { name: 'DocumentsRejected', why: 'Documents the DIAN rejected by a rule. A rise means the XML, the CUFE or a rule of the DIAN changed; the saved query of rejections by rule says which.', event: 'dian.response', outcome: 'rejected', value: '1', unit: Unit.COUNT, statistic: 'Sum', functions: ['chain', 'console'] },
  { name: 'DianCalls', why: 'Every call to the web service of the DIAN: the denominator of its error rate.', event: 'dian.call', value: '1', unit: Unit.COUNT, statistic: 'Sum', functions: ['chain', 'console'] },
  { name: 'DianErrors', why: 'Calls to the DIAN without a validation result (timeouts, refused connections, 5xx, answers that are not one): the DIAN or the way out is failing, and the chain waits on its retries.', event: 'dian.call', outcome: 'failure', value: '1', unit: Unit.COUNT, statistic: 'Sum', functions: ['chain', 'console'] },
  { name: 'DianLatency', why: 'How long the DIAN takes to answer: most of the time from issue to acceptance, and the first sign of a DIAN about to fail.', event: 'dian.call', value: 'duration_ms', unit: Unit.MILLISECONDS, statistic: 'p95', functions: ['chain', 'console'] },
  { name: 'ChainStepDuration', why: 'How long each job of the chain takes (XML, signature, transmission, PDF, email), by job: where the time from issue to delivery goes, and a job close to its timeout.', event: 'queue.job', where: { 'detail.queue': 'chain' }, value: 'duration_ms', unit: Unit.MILLISECONDS, statistic: 'p95', functions: ['chain'], dimensions: { Job: 'detail.job' } },
  { name: 'WebhookDeliveriesFailed', why: 'Webhook attempts an endpoint did not take: one endpoint down is the problem of its company; many at once, the way out of the platform.', event: 'webhook.delivery', outcome: 'failure', value: '1', unit: Unit.COUNT, statistic: 'Sum', functions: ['webhooks'] },
  { name: 'RelayPublishedEvents', why: 'Outbox events the relay published because their writer did not: almost never, or the fast path is failing (SNS unreachable, writers dying after their commit).', event: 'outbox.relayed', value: '1', unit: Unit.COUNT, statistic: 'Sum', functions: ['relay'] },
  { name: 'RelayRuns', why: 'One per run of the relay, every minute: its heartbeat. Missing, nothing publishes what the fast path leaves behind.', event: 'outbox.relay_run', value: '1', unit: Unit.COUNT, statistic: 'Sum', functions: ['relay'] },
  { name: 'OutboxLag', why: 'The age of the oldest outbox event still unpublished after a run of the relay: a chain that does not move although every function runs.', event: 'outbox.relay_run', value: 'detail.lag_seconds', unit: Unit.SECONDS, statistic: 'Maximum', functions: ['relay'] },
  { name: 'SecretRereads', why: 'The database refused the credentials of a warm function, which read its secret again: expected once per environment after a rotation, a problem when it keeps happening.', event: 'secret.reread', value: '1', unit: Unit.COUNT, statistic: 'Sum', functions: PHP },
  { name: 'AuthenticationFailures', why: 'Sign-ins with a wrong password and requests with API keys that are not valid: a rise is someone guessing, or an integration with a revoked key.', event: 'auth.refused', value: '1', unit: Unit.COUNT, statistic: 'Sum', functions: ['api'] },
  { name: 'OriginVerifierFailures', why: 'The origin verifier could not read the secret of the edge: without the values it holds, every request through the edge would be refused.', event: 'edge.secret', outcome: 'failure', value: '1', unit: Unit.COUNT, statistic: 'Sum', functions: [ORIGIN_VERIFIER] },
]

/** The fields of the lines a metric reads: its event, its outcome, its conditions, its value and its dimensions. */
export function metricFields(metric: ApplicationMetric): string[] {
  return [
    'event',
    ...(metric.outcome === undefined ? [] : ['outcome']),
    ...Object.keys(metric.where ?? {}),
    ...(metric.value === '1' ? [] : [metric.value]),
    ...Object.values(metric.dimensions ?? {}),
  ]
}

/** The pattern of the metric filter of a metric over JSON lines: its event and its other conditions. */
export function filterPatternOf(metric: ApplicationMetric): IFilterPattern {
  const conditions = [
    FilterPattern.stringValue('$.event', '=', metric.event),
    ...(metric.outcome === undefined ? [] : [FilterPattern.stringValue('$.outcome', '=', metric.outcome)]),
    ...Object.entries(metric.where ?? {}).map(([field, value]) => FilterPattern.stringValue(`$.${field}`, '=', value)),
  ]

  return conditions.length === 1 ? conditions[0] as IFilterPattern : FilterPattern.all(...conditions)
}

/** The runbook of each alarm, written in Infrastructure task 6. */
export const RUNBOOKS = {
  deadLetters: 'docs/runbooks/dead-letters.md',
  queueBacklog: 'docs/runbooks/queue-backlog.md',
  functionErrors: 'docs/runbooks/function-errors.md',
  functionThrottles: 'docs/runbooks/function-throttles.md',
  apiErrors: 'docs/runbooks/api-errors.md',
  apiLatency: 'docs/runbooks/api-latency.md',
  outboxRelay: 'docs/runbooks/outbox-relay.md',
  dianErrors: 'docs/runbooks/dian-errors.md',
  webhookFailures: 'docs/runbooks/webhook-failures.md',
  database: 'docs/runbooks/database-capacity.md',
  originVerifier: 'docs/runbooks/origin-verifier.md',
  notifier: 'docs/runbooks/notifier.md',
  serviceDegraded: 'docs/runbooks/service-degraded.md',
} as const

/** The description of an alarm: what its state means, and the runbook that attends it. */
export function alarmDescription(meaning: string, runbook: string): string {
  return `${meaning} Runbook: ${runbook}`
}

/** The objectives of service (docs/observability.md), and the alarms that defend each one, by their short name. */
export const SERVICE_OBJECTIVES = [
  { name: 'API availability', objective: '99.9% of API requests over 30 days return no 5xx (an error budget of 43 minutes a month).', alarms: ['api-server-errors', 'function-errors', 'function-throttles', 'origin-verifier-errors', 'database-connections', 'service-degraded'] },
  { name: 'API latency', objective: '99% of API requests over 30 days get a response within 1 second at API Gateway.', alarms: ['api-latency', 'database-capacity', 'function-throttles', 'service-degraded'] },
  { name: 'Time to the DIAN response', objective: '99% of the documents issued over 30 days are accepted or rejected by the DIAN within 5 minutes of their issue, not counting the minutes the DIAN itself is down.', alarms: ['chain-queue-age', 'chain-dead-letters', 'dian-error-rate', 'relay-not-running', 'outbox-lag', 'service-degraded'] },
] as const

/** The commands of Logs Insights the local emulator evaluates; a query with any other (stats, parse) fails there. */
export const EMULATED_QUERY_COMMANDS = ['fields', 'filter', 'sort', 'dedup', 'limit'] as const

/** What a saved query looks for in its follow query, replaced by the id of the journey to follow. */
export const CORRELATION_PLACEHOLDER = 'REPLACE-WITH-THE-CORRELATION-ID'

export interface SavedQuery {
  readonly name: string
  readonly why: string
  readonly functions: readonly AnyFunction[]
  readonly query: string
}

/** The saved queries of CloudWatch Logs Insights, under dian-einvoice/<stage>/ in the console. */
export const SAVED_QUERIES: readonly SavedQuery[] = [
  {
    name: 'follow-a-journey',
    why: 'Every line of one journey (a document from its issue to its webhooks, or a scheduled run), in order: paste its correlation id, the X-Correlation-Id the API answered.',
    functions: ['api', 'chain', 'webhooks', 'relay', 'scheduler', 'console'],
    query: [
      'fields @timestamp, function, level, event, outcome, message, document_id, duration_ms',
      `filter correlation_id = "${CORRELATION_PLACEHOLDER}"`,
      'sort @timestamp asc',
      'limit 1000',
    ].join('\n| '),
  },
  {
    name: 'errors-by-function',
    why: 'How many lines of error each function wrote, worst first.',
    functions: ALL_FUNCTIONS,
    query: [
      'filter level in ["error", "critical", "alert", "emergency"]',
      'stats count(*) as errors by function',
      'sort errors desc',
    ].join('\n| '),
  },
  {
    name: 'slow-requests',
    why: 'The requests to the API that took more than a second, slowest first, with their route and their journey.',
    functions: ['api'],
    query: [
      'fields @timestamp, detail.method, detail.route, detail.status, duration_ms, correlation_id',
      'filter event = "http.request" and duration_ms > 1000',
      'sort duration_ms desc',
      'limit 100',
    ].join('\n| '),
  },
  {
    name: 'dian-rejections-by-rule',
    why: 'Which rules of the DIAN reject documents, most frequent first.',
    functions: ['chain', 'console'],
    query: [
      'filter event = "dian.rule" and outcome = "rejected"',
      'stats count(*) as rejections by detail.rule',
      'sort rejections desc',
    ].join('\n| '),
  },
]

/** The commands of a query, in order: the first word of each of its parts. */
export function queryCommands(query: string): string[] {
  return query.split('|').map((part) => part.trim().split(/\s+/)[0] ?? '')
}
