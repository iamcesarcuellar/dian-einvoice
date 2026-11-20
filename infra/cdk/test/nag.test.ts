// cdk-nag (AWS Solutions) over both stages, as cdk synth runs it: a validation plugin of the app, whose
// report (cdk.out/validation-report.json) must hold no violation and, as suppressed, exactly the expected
// exceptions, each acknowledged next to its resource with a written reason. Synthesis needs no network. The
// template of the functions goes through the same rules in test/functions.test.ts.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, it } from 'node:test'
import { FUNCTIONS, STAGES, type StageConfig } from '../lib/stage.ts'
import { built } from './support.ts'

interface Suppressed {
  readonly ruleName: string
  readonly reason: string
  readonly violatingConstructs: readonly { readonly constructPath: string }[]
}

interface PluginReport {
  readonly pluginName: string
  readonly conclusion: string
  readonly violations: readonly unknown[]
  readonly suppressedViolations: readonly Suppressed[]
}

/** The exceptions of each stage: the rule and the resource it is acknowledged on. */
const EXCEPTIONS = [
  'AwsSolutions-DDB3 data/CacheTable/Resource',
  'AwsSolutions-DDB3 data/LimitsTable/Resource',
  'AwsSolutions-DDB3 data/SessionsTable/Resource',
  'AwsSolutions-IAM5[Resource::<Documents7E5B2978.Arn>/*] storage/DocumentsReadWritePolicy/Resource',
  'AwsSolutions-SMG4 security/AppSecret/Resource',
]

/** Each function writes its logs to its own log group, in streams Lambda names: one exception per role and stage. */
function logStreamExceptions(stage: StageConfig): string[] {
  return FUNCTIONS.map((fn) => `AwsSolutions-IAM5[Resource::arn:aws:logs:${stage.region}:${stage.account}:log-group:/aws/lambda/dian-einvoice-${stage.name}-${fn}:*] ${stage.name}/compute/${fn.charAt(0).toUpperCase()}${fn.slice(1)}FunctionRole/Resource`)
}

/** In prod the functions run in a VPC: Lambda creates their network interfaces, which EC2 scopes to no resource. */
function networkExceptions(stage: StageConfig): string[] {
  return stage.network === 'private'
    ? FUNCTIONS.map((fn) => `AwsSolutions-IAM5[Resource::*] ${stage.name}/compute/${fn.charAt(0).toUpperCase()}${fn.slice(1)}FunctionRole/Resource`)
    : []
}

const assembly = built.app.synth()
const report = JSON.parse(readFileSync(join(assembly.directory, 'validation-report.json'), 'utf8')) as { pluginReports: PluginReport[] }
const nag = report.pluginReports.find((plugin) => plugin.pluginName === 'AwsSolutions')

describe('cdk-nag', () => {
  it('ran over the whole app and found no violation left', () => {
    assert.ok(nag !== undefined, 'the AwsSolutions plugin reported')
    assert.equal(nag.conclusion, 'success')
    assert.deepEqual(nag.violations, [])
  })

  it('suppressed only the expected exceptions of each stage, each with its reason', () => {
    const suppressed = (nag?.suppressedViolations ?? []).flatMap((violation) => violation.violatingConstructs.map((construct) => {
      assert.ok(violation.reason.length >= 60, `${violation.ruleName} on ${construct.constructPath} needs a written reason`)

      return `${violation.ruleName} ${construct.constructPath}`
    }))

    const expected = [STAGES.local, STAGES.prod].flatMap((stage) => [
      ...EXCEPTIONS.map((exception) => exception.replace(' ', ` ${stage.name}/`)),
      ...logStreamExceptions(stage),
      ...networkExceptions(stage),
    ])
    assert.deepEqual([...new Set(suppressed)].sort(), expected.sort())
  })

  it('synthesized the six stacks of both stages', () => {
    assert.deepEqual(assembly.nestedAssemblies.map((nested) => nested.directoryName).sort(), ['assembly-local', 'assembly-prod'])
    assert.deepEqual(assembly.nestedAssemblies.flatMap((nested) => nested.nestedAssembly.stacks.map((stack) => stack.stackName)).sort(), [
      'dian-einvoice-local-compute', 'dian-einvoice-local-data', 'dian-einvoice-local-mail', 'dian-einvoice-local-messaging', 'dian-einvoice-local-security', 'dian-einvoice-local-storage',
      'dian-einvoice-prod-compute', 'dian-einvoice-prod-data', 'dian-einvoice-prod-mail', 'dian-einvoice-prod-messaging', 'dian-einvoice-prod-security', 'dian-einvoice-prod-storage',
    ])
  })
})
