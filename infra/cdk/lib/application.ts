// The CDK app: one CDK stage per stage of the platform, with the five stacks of Infrastructure task 1, the
// compute stack of task 2 (the roles and the image repository of the functions, which the serverless service
// in infra/functions defines), the edge stack of task 4 (the distribution in front of the web app and the API,
// deployed after the functions, whose HTTP API it fronts), the alerts and observability stacks of task 5 (where the
// alarms go, before the functions, whose notifier subscribes to it; the metrics, alarms, dashboard and saved queries,
// after the functions and the edge, whose logs and metrics they read) and, in prod only, the network and database
// stacks of task 3 and the delivery stack of task 6 (the roles of the deployment pipeline of a real account), which
// are validated offline and never deployed; common tags, names with the stage and the AWS Solutions checks of cdk-nag. Every finding of cdk-nag must be fixed or acknowledged with its reason next to the resource; any
// other one stops synthesis.
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { App, Stage, Tags, Validations } from 'aws-cdk-lib'
import { AwsSolutionsChecks } from 'cdk-nag'
import { AlertsStack } from './alerts-stack.ts'
import { loadAwsServices } from './aws-services.ts'
import { loadEventRoutes, type EventRoutes } from './event-routes.ts'
import { loadLogSchema, type LogSchema } from './log-schema.ts'
import { ComputeStack } from './compute-stack.ts'
import { DatabaseStack } from './database-stack.ts'
import { DataStack } from './data-stack.ts'
import { DeliveryStack } from './delivery-stack.ts'
import { EdgeStack } from './edge-stack.ts'
import { MailStack } from './mail-stack.ts'
import { MessagingStack } from './messaging-stack.ts'
import { NetworkStack } from './network-stack.ts'
import { ObservabilityStack } from './observability-stack.ts'
import { SecurityStack } from './security-stack.ts'
import { loadApiPaths, loadAuthenticationPaths, loadSecurityHeaders } from './security-headers.ts'
import { STAGES, PROJECT, type StageConfig, type StageName } from './stage.ts'
import { StorageStack } from './storage-stack.ts'

export interface PlatformStacks {
  readonly stage: Stage
  readonly messaging: MessagingStack
  readonly storage: StorageStack
  readonly data: DataStack
  readonly security: SecurityStack
  readonly mail: MailStack
  readonly alerts: AlertsStack
  readonly compute: ComputeStack
  readonly edge: EdgeStack
  readonly observability: ObservabilityStack
  // Only in a stage with a private network and a managed database (prod).
  readonly network: NetworkStack | null
  readonly database: DatabaseStack | null
  // Only in a stage a pipeline deploys (prod).
  readonly delivery: DeliveryStack | null
}

/** The Infrastructure task that brought each stack, for its description. */
const TASKS: Readonly<Record<string, number>> = { compute: 2, network: 3, database: 3, edge: 4, alerts: 5, observability: 5, delivery: 6 }

export function tagsOf(config: StageConfig, component: string): Record<string, string> {
  return { project: PROJECT, stage: config.name, component, 'managed-by': 'aws-cdk' }
}

/** The stacks of one stage, named local/messaging, local/storage...; deployed as dian-einvoice-local-messaging... */
export function addStage(app: App, config: StageConfig, routes: EventRoutes, services: Readonly<Record<string, string>>, schema: LogSchema): PlatformStacks {
  const stage = new Stage(app, config.name, { env: { account: config.account, region: config.region } })
  const common = (component: string) => ({
    stackName: `${PROJECT}-${config.name}-${component}`,
    description: `dian-einvoice ${config.name}: ${component} (Infrastructure task ${TASKS[component] ?? 1})`,
    terminationProtection: config.terminationProtection,
    tags: tagsOf(config, component),
    stage: config,
  })
  const managed = config.database.managed

  if ((config.network === null) !== (managed === null)) {
    throw new Error(`${config.name}: a private network and a managed database go together.`)
  }

  const network = config.network === null || managed === null
    ? null
    : new NetworkStack(stage, 'network', { ...common('network'), network: config.network, services })
  const database = network === null || managed === null ? null : new DatabaseStack(stage, 'database', { ...common('database'), settings: managed, network })

  const stacks: PlatformStacks = {
    stage,
    messaging: new MessagingStack(stage, 'messaging', { ...common('messaging'), routes }),
    storage: new StorageStack(stage, 'storage', common('storage')),
    data: new DataStack(stage, 'data', common('data')),
    security: new SecurityStack(stage, 'security', common('security')),
    mail: new MailStack(stage, 'mail', common('mail')),
    alerts: new AlertsStack(stage, 'alerts', common('alerts')),
    compute: new ComputeStack(stage, 'compute', common('compute')),
    edge: new EdgeStack(stage, 'edge', { ...common('edge'), headers: loadSecurityHeaders(), apiPaths: loadApiPaths(), authenticationPaths: loadAuthenticationPaths() }),
    observability: new ObservabilityStack(stage, 'observability', { ...common('observability'), schema }),
    network,
    database,
    delivery: config.delivery === null ? null : new DeliveryStack(stage, 'delivery', { ...common('delivery'), delivery: config.delivery }),
  }

  // The roles attach the managed policies of the other stacks by name and read the parameters of the
  // identities of the database (stack security locally, stack database in prod) and of the alerts (the notifier),
  // so they deploy after them.
  for (const dependency of [stacks.messaging, stacks.storage, stacks.data, stacks.security, stacks.mail, stacks.alerts, ...(database === null ? [] : [database])]) {
    stacks.compute.addStackDependency(dependency)
  }

  // The edge reads the secret of the edge (security) and fronts the HTTP API of the serverless service, which
  // needs the roles of compute: it deploys after both, and after the functions (docs/infrastructure.md).
  stacks.edge.addStackDependency(stacks.compute)

  // The metrics and alarms read the log groups and the HTTP API of the functions and the distribution of the edge,
  // and publish to the topics of the alerts: they deploy last (make cloud-up deploys the stack alone, -e).
  stacks.observability.addStackDependency(stacks.edge)
  stacks.observability.addStackDependency(stacks.alerts)

  Tags.of(stage).add('project', PROJECT)
  Tags.of(stage).add('stage', config.name)
  Tags.of(stage).add('managed-by', 'aws-cdk')

  return stacks
}

/** The context of cdk.json (the feature flags), which the CDK CLI passes to bin/app.ts and the tests pass themselves. */
export function cdkContext(): Record<string, unknown> {
  const parsed: unknown = JSON.parse(readFileSync(resolve(import.meta.dirname, '../cdk.json'), 'utf8'))

  return typeof parsed === 'object' && parsed !== null && 'context' in parsed && typeof parsed.context === 'object' && parsed.context !== null
    ? parsed.context as Record<string, unknown>
    : {}
}

export interface BuildOptions {
  readonly stages?: readonly StageName[]
  // Other settings for a stage, such as the options of the network and the database (the tests of the variants).
  readonly configs?: Partial<Record<StageName, StageConfig>>
  readonly routes?: EventRoutes
  readonly services?: Readonly<Record<string, string>>
  readonly schema?: LogSchema
  readonly outdir?: string
  readonly context?: Record<string, unknown>
}

/**
 * The domain of the edge of prod as deployment parameters, without a lookup in an account: cdk synth|deploy -c
 * edgeDomainName=app.example.com -c edgeCertificateArn=arn:aws:acm:us-east-1:...; an empty edgeDomainName serves
 * the domain CloudFront gives the distribution. Without them, the placeholders of lib/stage.ts.
 */
export function withEdgeDomain(config: StageConfig, app: App): StageConfig {
  const name: unknown = app.node.tryGetContext('edgeDomainName')
  const certificateArn: unknown = app.node.tryGetContext('edgeCertificateArn')

  if (config.name !== 'prod' || name === undefined) {
    return config
  }

  if (typeof name !== 'string' || (name !== '' && (typeof certificateArn !== 'string' || !/^arn:aws:acm:us-east-1:\d{12}:certificate\/[0-9a-f-]+$/.test(certificateArn)))) {
    throw new Error('edgeDomainName needs edgeCertificateArn, a certificate of ACM in us-east-1, as CloudFront requires.')
  }

  return { ...config, edge: { ...config.edge, domain: name === '' ? null : { name, certificateArn: certificateArn as string } } }
}

/**
 * The fallback address of the alerts of prod as a deployment parameter: cdk synth|deploy -c alertsEmail=operators@...;
 * an empty value subscribes none. Without it, the placeholder of lib/stage.ts.
 */
export function withAlertsEmail(config: StageConfig, app: App): StageConfig {
  const email: unknown = app.node.tryGetContext('alertsEmail')

  if (config.name !== 'prod' || email === undefined) {
    return config
  }

  if (typeof email !== 'string' || (email !== '' && !/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(email))) {
    throw new Error('alertsEmail must be an email address, or empty for no email subscription.')
  }

  return { ...config, alerts: { ...config.alerts, fallbackEmail: email } }
}

/**
 * The account of prod as a deployment parameter: cdk synth|deploy -c account=123456789012. Without it, the placeholder
 * of lib/stage.ts, whose stage the CDK CLI refuses to deploy with the credentials of any real account.
 */
export function withAccount(config: StageConfig, app: App): StageConfig {
  const account: unknown = app.node.tryGetContext('account')

  if (config.name !== 'prod' || account === undefined) {
    return config
  }

  if (typeof account !== 'string' || !/^\d{12}$/.test(account)) {
    throw new Error('account must be the 12 digits of an AWS account.')
  }

  return { ...config, account }
}

/**
 * The repository whose workflow deploys prod, as deployment parameters: cdk synth|deploy -c githubOwner=<owner> -c
 * githubRepository=<name>, and -c githubOidcProviderArn=<arn> to reuse the provider of GitHub Actions the account already
 * has. Without them, the placeholders of lib/stage.ts.
 */
export function withDelivery(config: StageConfig, app: App): StageConfig {
  if (config.name !== 'prod' || config.delivery === null) {
    return config
  }

  const context = (key: string): string | undefined => {
    const value: unknown = app.node.tryGetContext(key)

    if (value !== undefined && typeof value !== 'string') {
      throw new Error(`${key} must be a string.`)
    }

    return value
  }
  const owner = context('githubOwner') ?? config.delivery.githubOwner
  const repository = context('githubRepository') ?? config.delivery.githubRepository
  const provider = context('githubOidcProviderArn')

  // The names GitHub allows: an owner of letters, digits and single hyphens, a repository of letters, digits, ., - and _.
  if (!/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/.test(owner) || !/^[A-Za-z0-9._-]{1,100}$/.test(repository)) {
    throw new Error('githubOwner and githubRepository must be the owner and the name of a repository on GitHub.')
  }

  if (provider !== undefined && !/^arn:aws:iam::\d{12}:oidc-provider\/token\.actions\.githubusercontent\.com$/.test(provider)) {
    throw new Error('githubOidcProviderArn must be the ARN of the OpenID Connect provider of token.actions.githubusercontent.com.')
  }

  return { ...config, delivery: { ...config.delivery, githubOwner: owner, githubRepository: repository, oidcProviderArn: provider ?? config.delivery.oidcProviderArn } }
}

export function buildApp(options: BuildOptions = {}): { app: App, stages: PlatformStacks[] } {
  const app = new App({
    ...(options.outdir === undefined ? {} : { outdir: options.outdir }),
    ...(options.context === undefined ? {} : { context: options.context }),
  })
  Validations.of(app).addPlugins(new AwsSolutionsChecks(app, { verbose: true }))
  const routes = options.routes ?? loadEventRoutes()
  const services = options.services ?? loadAwsServices()
  const schema = options.schema ?? loadLogSchema()
  const configured = (name: StageName): StageConfig => withDelivery(withAccount(withAlertsEmail(withEdgeDomain(options.configs?.[name] ?? STAGES[name], app), app), app), app)
  const stages = (options.stages ?? (Object.keys(STAGES) as StageName[])).map((name) => addStage(app, configured(name), routes, services, schema))

  return { app, stages }
}
