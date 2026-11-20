// The CDK app built once for the tests, with the flags of cdk.json and the event routes of the backend,
// into a temporary directory.
import { mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { Template } from 'aws-cdk-lib/assertions'
import type { Stack } from 'aws-cdk-lib'
import { buildApp, cdkContext, type PlatformStacks } from '../lib/application.ts'
import { loadEventRoutes } from '../lib/event-routes.ts'

export const routes = loadEventRoutes()

export const built = buildApp({ context: cdkContext(), routes, outdir: mkdtempSync(join(tmpdir(), 'cdk-test-')) })

export function stageOf(name: 'local' | 'prod'): PlatformStacks {
  const stage = built.stages.find((stacks) => stacks.stage.node.id === name)

  if (stage === undefined) {
    throw new Error(`No stage ${name}.`)
  }

  return stage
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
