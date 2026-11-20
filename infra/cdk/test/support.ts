// The CDK app built once for the tests, with the flags of cdk.json, the event routes and the AWS services of
// the backend, into a temporary directory; and helpers over the synthesized templates.
import { mkdtempSync, readFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { Template } from 'aws-cdk-lib/assertions'
import type { Stack } from 'aws-cdk-lib'
import { buildApp, cdkContext, type PlatformStacks } from '../lib/application.ts'
import { loadAwsServices } from '../lib/aws-services.ts'
import { loadEventRoutes } from '../lib/event-routes.ts'
import type { StageConfig } from '../lib/stage.ts'

export const routes = loadEventRoutes()

export const services = loadAwsServices()

export const built = buildApp({ context: cdkContext(), routes, services, outdir: mkdtempSync(join(tmpdir(), 'cdk-test-')) })

export function stageOf(name: 'local' | 'prod'): PlatformStacks {
  const stage = built.stages.find((stacks) => stacks.stage.node.id === name)

  if (stage === undefined) {
    throw new Error(`No stage ${name}.`)
  }

  return stage
}

/** The stage prod built alone with other settings: the options of the network and of the database. */
export function variantOf(config: StageConfig): { app: ReturnType<typeof buildApp>['app'], stacks: PlatformStacks } {
  const variant = buildApp({ stages: ['prod'], configs: { prod: config }, context: cdkContext(), routes, services, outdir: mkdtempSync(join(tmpdir(), 'cdk-variant-')) })
  const stacks = variant.stages[0]

  if (stacks === undefined) {
    throw new Error('No stage prod.')
  }

  return { app: variant.app, stacks }
}

const templates = new Map<Stack, Template>()

export function template(stack: Stack): Template {
  let found = templates.get(stack)

  if (found === undefined) {
    found = Template.fromStack(stack)
    templates.set(stack, found)
  }

  return found
}

/** The logical id of the only resource of a type whose properties match. */
export function logicalId(stack: Stack, type: string, properties: Record<string, unknown>): string {
  const ids = Object.keys(template(stack).findResources(type, { Properties: properties }))

  if (ids.length !== 1) {
    throw new Error(`Expected one ${type} matching, found ${ids.length}.`)
  }

  return ids[0] as string
}

/** The packaged template of the functions of a stage (make test-infra and make cdk-check package them first). */
export function functionsTemplate(stage: 'local' | 'prod'): { Parameters: Record<string, { Type: string, Default: string }>, Resources: Record<string, { Type: string, Properties: Record<string, unknown> }> } {
  const directory = process.env.FUNCTIONS_TEMPLATES ?? join(import.meta.dirname, '../../functions')

  return JSON.parse(readFileSync(join(directory, '.package', stage, 'cloudformation-template-update-stack.json'), 'utf8')) as ReturnType<typeof functionsTemplate>
}

/**
 * The connections the functions of a stage may hold at once: one per execution environment of a function of the
 * application, their reserved concurrency. The origin verifier of the edge never connects to the database.
 */
export function connectionBudget(stage: 'local' | 'prod'): number {
  return Object.values(functionsTemplate(stage).Resources)
    .filter((resource) => resource.Type === 'AWS::Lambda::Function' && resource.Properties.PackageType === 'Image')
    .reduce((sum, fn) => sum + Number(fn.Properties.ReservedConcurrentExecutions), 0)
}

/** The report of a validation plugin of a synthesized assembly (cdk-nag is AwsSolutions). */
export interface PluginReport {
  readonly pluginName: string
  readonly conclusion: string
  readonly violations: readonly { readonly ruleName: string, readonly violatingConstructs: readonly { readonly constructPath: string }[] }[]
  readonly suppressedViolations: readonly { readonly ruleName: string, readonly reason: string, readonly violatingConstructs: readonly { readonly constructPath: string }[] }[]
}

export function validationReport(directory: string, plugin: string): PluginReport | undefined {
  const report = JSON.parse(readFileSync(join(directory, 'validation-report.json'), 'utf8')) as { pluginReports: PluginReport[] }

  return report.pluginReports.find((candidate) => candidate.pluginName === plugin)
}
