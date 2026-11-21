// cdk-nag (AWS Solutions) over both stages, as cdk synth runs it: a validation plugin of the app, whose
// report (cdk.out/validation-report.json) must hold no violation and, as suppressed, exactly the expected
// exceptions, each acknowledged next to its resource with a written reason. Synthesis needs no network. The
// template of the functions goes through the same rules in test/functions.test.ts.
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { wildcards } from '../lib/delivery-stack.ts'
import { FUNCTIONS, NOTIFIER, ORIGIN_VERIFIER, STAGES, type StageConfig } from '../lib/stage.ts'
import { built, stageOf, template, validationReport } from './support.ts'

/** The exceptions of each stage: the rule and the resource it is acknowledged on. */
const EXCEPTIONS = [
  'AwsSolutions-DDB3 data/CacheTable/Resource',
  'AwsSolutions-DDB3 data/LimitsTable/Resource',
  'AwsSolutions-DDB3 data/SessionsTable/Resource',
  'AwsSolutions-IAM5[Resource::<Documents7E5B2978.Arn>/*] storage/DocumentsReadWritePolicy/Resource',
  'AwsSolutions-SMG4 security/AppSecret/Resource',
  'AwsSolutions-SMG4 security/OriginSecret/Resource',
  'AwsSolutions-CFR1 edge/Distribution/Resource',
  // The address of the chat webhook, which the chat issues, and the dead-letter queue of the notifier, which is one.
  'AwsSolutions-SMG4 alerts/ChatWebhook/Resource',
  'AwsSolutions-SQS3 alerts/NotifierDeadLetterQueue/Resource',
]

/**
 * Each function writes its logs to its own log group, in streams Lambda names: one exception per role and stage,
 * the origin verifier and the notifier included.
 */
function logStreamExceptions(stage: StageConfig): string[] {
  return [...FUNCTIONS, ORIGIN_VERIFIER, NOTIFIER].map((fn) => `AwsSolutions-IAM5[Resource::arn:aws:logs:${stage.region}:${stage.account}:log-group:/aws/lambda/dian-einvoice-${stage.name}-${fn}:*] ${stage.name}/compute/${fn.split('-').map((part) => part.charAt(0).toUpperCase() + part.slice(1)).join('')}FunctionRole/Resource`)
}

/**
 * The edge of a stage without a domain of its own answers with the default certificate of CloudFront (TLSv1, which
 * AWS sets), and one without a firewall (the emulator applies none) has no web ACL.
 */
function edgeExceptions(stage: StageConfig): string[] {
  return [
    ...(stage.edge.domain === null ? [`AwsSolutions-CFR4 ${stage.name}/edge/Distribution/Resource`] : []),
    ...(stage.edge.firewall === null ? [`AwsSolutions-CFR2 ${stage.name}/edge/Distribution/Resource`] : []),
  ]
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

/**
 * The roles of the deployment pipeline (prod): each resource with a wildcard in their policies, with the reason the
 * stack writes for it (lib/delivery-stack.ts), a resource that takes no ARN, or a name CloudFormation or a service
 * completes with an id of its own.
 */
function deliveryExceptions(stage: StageConfig): string[] {
  const stacks = stageOf(stage.name)

  if (stacks.delivery === null) {
    return []
  }

  const policies = template(stacks.delivery).findResources('AWS::IAM::Role')

  return Object.entries(policies).flatMap(([logicalId, role]) => {
    const path = logicalId.startsWith('DeployRole') ? 'DeployRole' : 'FunctionsDeploymentRole'
    const statements = ((role as { Properties: { Policies: { PolicyDocument: { Statement: { Resource: string | string[] }[] } }[] } }).Properties.Policies).flatMap((policy) => policy.PolicyDocument.Statement)
    const resources = [...new Set(statements.flatMap((statement) => [statement.Resource].flat()).filter((resource) => resource.includes('*')))]

    return resources.map((resource) => {
      assert.ok(resource in wildcards(stage), `${resource} has a reason`)

      return `AwsSolutions-IAM5[Resource::${resource}] ${stage.name}/delivery/${path}/Resource`
    })
  })
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
      ...edgeExceptions(stage),
      ...deliveryExceptions(stage),
    ])
    assert.deepEqual([...new Set(suppressed)].sort(), expected.sort())
  })

  it('synthesized the nine stacks of the stage local and the twelve of prod, which adds the network, the database and the delivery', () => {
    assert.deepEqual(assembly.nestedAssemblies.map((nested) => nested.directoryName).sort(), ['assembly-local', 'assembly-prod'])
    assert.deepEqual(assembly.nestedAssemblies.flatMap((nested) => nested.nestedAssembly.stacks.map((stack) => stack.stackName)).sort(), [
      'dian-einvoice-local-alerts', 'dian-einvoice-local-compute', 'dian-einvoice-local-data', 'dian-einvoice-local-edge', 'dian-einvoice-local-mail', 'dian-einvoice-local-messaging', 'dian-einvoice-local-observability', 'dian-einvoice-local-security', 'dian-einvoice-local-storage',
      'dian-einvoice-prod-alerts', 'dian-einvoice-prod-compute', 'dian-einvoice-prod-data', 'dian-einvoice-prod-database', 'dian-einvoice-prod-delivery', 'dian-einvoice-prod-edge', 'dian-einvoice-prod-mail', 'dian-einvoice-prod-messaging', 'dian-einvoice-prod-network', 'dian-einvoice-prod-observability', 'dian-einvoice-prod-security', 'dian-einvoice-prod-storage',
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
