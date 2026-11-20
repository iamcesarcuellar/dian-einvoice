// cdk-nag (AWS Solutions) over both stages, as cdk synth runs it: a validation plugin of the app, whose
// report (cdk.out/validation-report.json) must hold no violation and, as suppressed, exactly the expected
// exceptions, each acknowledged next to its resource with a written reason. Synthesis needs no network. The
// template of the functions goes through the same rules in test/functions.test.ts.
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { FUNCTIONS, STAGES, type StageConfig } from '../lib/stage.ts'
import { built, validationReport } from './support.ts'

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
  return stage.network !== null
    ? FUNCTIONS.map((fn) => `AwsSolutions-IAM5[Resource::*] ${stage.name}/compute/${fn.charAt(0).toUpperCase()}${fn.slice(1)}FunctionRole/Resource`)
    : []
}

/**
 * The managed database (prod): password authentication instead of IAM, the default port and no Backtrack.
 * Locally, the secrets of the identities without the rotation functions of AWS, which the emulator cannot run.
 */
function databaseExceptions(stage: StageConfig): string[] {
  return stage.database.managed !== null
    ? ['AwsSolutions-RDS6', 'AwsSolutions-RDS11', 'AwsSolutions-RDS14'].map((rule) => `${rule} ${stage.name}/database/Cluster/Resource`)
    : ['Migrations', 'Application'].map((identity) => `AwsSolutions-SMG4 ${stage.name}/security/DatabaseIdentities/${identity}/Resource`)
}

const assembly = built.app.synth()
const nag = validationReport(assembly.directory, 'AwsSolutions')

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
      ...databaseExceptions(stage),
    ])
    assert.deepEqual([...new Set(suppressed)].sort(), expected.sort())
  })

  it('synthesized the six stacks of the stage local and the eight of prod, which adds the network and the database', () => {
    assert.deepEqual(assembly.nestedAssemblies.map((nested) => nested.directoryName).sort(), ['assembly-local', 'assembly-prod'])
    assert.deepEqual(assembly.nestedAssemblies.flatMap((nested) => nested.nestedAssembly.stacks.map((stack) => stack.stackName)).sort(), [
      'dian-einvoice-local-compute', 'dian-einvoice-local-data', 'dian-einvoice-local-mail', 'dian-einvoice-local-messaging', 'dian-einvoice-local-security', 'dian-einvoice-local-storage',
      'dian-einvoice-prod-compute', 'dian-einvoice-prod-data', 'dian-einvoice-prod-database', 'dian-einvoice-prod-mail', 'dian-einvoice-prod-messaging', 'dian-einvoice-prod-network', 'dian-einvoice-prod-security', 'dian-einvoice-prod-storage',
    ])
  })

  it('needs no lookup in an AWS account, so synthesis runs without credentials and gives the same templates every time', () => {
    const missing = [assembly.manifest.missing ?? [], ...assembly.nestedAssemblies.map((nested) => nested.nestedAssembly.manifest.missing ?? [])].flat()
    assert.deepEqual(missing.map((context) => context.key), [])
    assert.deepEqual(validationReport(assembly.directory, 'Construct Annotations')?.violations ?? [], [])
  })

  it('found nothing in the CloudFormation schema validation of CDK, the zones of the subnets and instances acknowledged', () => {
    const schema = validationReport(assembly.directory, 'CloudFormation Validate')
    assert.equal(schema?.conclusion, 'success')
    assert.deepEqual(schema.violations, [])
    assert.deepEqual([...new Set(schema.suppressedViolations.map((violation) => violation.ruleName))], ['W3010'])
  })
})
