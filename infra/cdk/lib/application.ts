// The CDK app: one CDK stage per stage of the platform, with the five stacks of Infrastructure task 1,
// common tags, names with the stage and the AWS Solutions checks of cdk-nag. Every finding of cdk-nag
// must be fixed or acknowledged with its reason next to the resource; any other one stops synthesis.
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { App, Stage, Tags, Validations } from 'aws-cdk-lib'
import { AwsSolutionsChecks } from 'cdk-nag'
import { loadEventRoutes, type EventRoutes } from './event-routes.ts'
import { DataStack } from './data-stack.ts'
import { MailStack } from './mail-stack.ts'
import { MessagingStack } from './messaging-stack.ts'
import { SecurityStack } from './security-stack.ts'
import { STAGES, PROJECT, type StageConfig, type StageName } from './stage.ts'
import { StorageStack } from './storage-stack.ts'

export interface PlatformStacks {
  readonly stage: Stage
  readonly messaging: MessagingStack
  readonly storage: StorageStack
  readonly data: DataStack
  readonly security: SecurityStack
  readonly mail: MailStack
}

export function tagsOf(config: StageConfig, component: string): Record<string, string> {
  return { project: PROJECT, stage: config.name, component, 'managed-by': 'aws-cdk' }
}

/** The stacks of one stage, named local/messaging, local/storage...; deployed as dian-einvoice-local-messaging... */
export function addStage(app: App, config: StageConfig, routes: EventRoutes): PlatformStacks {
  const stage = new Stage(app, config.name, { env: { account: config.account, region: config.region } })
  const common = (component: string) => ({
    stackName: `${PROJECT}-${config.name}-${component}`,
    description: `dian-einvoice ${config.name}: ${component} (Infrastructure task 1)`,
    terminationProtection: config.terminationProtection,
    tags: tagsOf(config, component),
    stage: config,
  })

  const stacks: PlatformStacks = {
    stage,
    messaging: new MessagingStack(stage, 'messaging', { ...common('messaging'), routes }),
    storage: new StorageStack(stage, 'storage', common('storage')),
    data: new DataStack(stage, 'data', common('data')),
    security: new SecurityStack(stage, 'security', common('security')),
    mail: new MailStack(stage, 'mail', common('mail')),
  }

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
  readonly routes?: EventRoutes
  readonly outdir?: string
  readonly context?: Record<string, unknown>
}

export function buildApp(options: BuildOptions = {}): { app: App, stages: PlatformStacks[] } {
  const app = new App({
    ...(options.outdir === undefined ? {} : { outdir: options.outdir }),
    ...(options.context === undefined ? {} : { context: options.context }),
  })
  Validations.of(app).addPlugins(new AwsSolutionsChecks(app, { verbose: true }))
  const routes = options.routes ?? loadEventRoutes()
  const stages = (options.stages ?? (Object.keys(STAGES) as StageName[])).map((name) => addStage(app, STAGES[name], routes))

  return { app, stages }
}
