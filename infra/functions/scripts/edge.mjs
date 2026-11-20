// The edge of the emulated cloud (make cloud-up, make cloud-check):
//
//   node scripts/edge.mjs origin-secret   prints the current value of the secret of the edge of the stage local, the
//                                         parameter of the deployment of the stack edge (the emulator expands no
//                                         dynamic reference outside RDS); make cloud-up pipes it, never prints it
//   node scripts/edge.mjs reconcile       gives the distribution of the stage local the settings of its behaviours as
//                                         the deployed template declares them, which the CloudFormation of the
//                                         emulator drops (docs/limitations.md): the methods each behaviour allows and
//                                         caches, its cache and origin request policies and its functions. A
//                                         workaround for the emulator: it creates nothing, changes nothing the template
//                                         does not declare, refuses to run anywhere but against the emulator, and is
//                                         idempotent. It prints what it had to give back, behaviour by behaviour; run
//                                         again, that it keeps what it gave (the distribution changed after
//                                         CloudFormation last deployed it); and when a distribution as CloudFormation
//                                         deployed it already holds everything, that nothing had to be applied and the
//                                         step can be retired
//   node scripts/edge.mjs check           whether the distribution holds those settings as the template declares them
import { CloudFormationClient, DescribeStackResourcesCommand, GetTemplateCommand } from '@aws-sdk/client-cloudformation'
import { CloudFrontClient, GetDistributionCommand, GetDistributionConfigCommand, UpdateDistributionCommand } from '@aws-sdk/client-cloudfront'
import { GetSecretValueCommand, SecretsManagerClient } from '@aws-sdk/client-secrets-manager'

const STAGE = process.env.STAGE ?? 'local'
const STACK = `dian-einvoice-${STAGE}-edge`
// A distribution changed more than this after CloudFormation last deployed it was changed by reconcile.
const AFTER_DEPLOYMENT_MS = 2000

async function originSecret() {
  const { SecretString: value } = await new SecretsManagerClient({}).send(new GetSecretValueCommand({ SecretId: `dian-einvoice/${STAGE}/edge/origin-verify` }))

  if (typeof value !== 'string' || value.length < 32) {
    process.stderr.write(`The secret dian-einvoice/${STAGE}/edge/origin-verify has no value: deploy the stack security first.\n`)
    process.exit(1)
  }

  process.stdout.write(value)
}

/** The distribution of the stack edge: its id, the configuration the template declares, and the physical ids of the stack. */
async function declared() {
  const cloudformation = new CloudFormationClient({})
  const template = JSON.parse((await cloudformation.send(new GetTemplateCommand({ StackName: STACK, TemplateStage: 'Original' }))).TemplateBody)
  const { StackResources: resources = [] } = await cloudformation.send(new DescribeStackResourcesCommand({ StackName: STACK }))
  const physical = Object.fromEntries(resources.map((resource) => [resource.LogicalResourceId, resource.PhysicalResourceId]))
  const deployed = Object.fromEntries(resources.map((resource) => [resource.LogicalResourceId, resource.Timestamp]))
  const [logical, distribution] = Object.entries(template.Resources).find(([, resource]) => resource.Type === 'AWS::CloudFront::Distribution') ?? []

  if (logical === undefined || physical[logical] === undefined) {
    throw new Error(`The stack ${STACK} has no distribution.`)
  }

  // A literal id, or the Ref of a policy of the stack; the function by the ARN CloudFormation gave it.
  const resolve = (value) => {
    if (typeof value === 'string') {
      return value
    }

    const reference = value?.Ref ?? value?.['Fn::GetAtt']?.[0]

    if (physical[reference] === undefined) {
      throw new Error(`${JSON.stringify(value)} names no resource of the stack ${STACK}.`)
    }

    return physical[reference]
  }

  const behaviour = (wanted) => ({
    AllowedMethods: wanted.AllowedMethods ?? ['GET', 'HEAD'],
    CachedMethods: wanted.CachedMethods ?? ['GET', 'HEAD'],
    CachePolicyId: resolve(wanted.CachePolicyId),
    OriginRequestPolicyId: wanted.OriginRequestPolicyId === undefined ? undefined : resolve(wanted.OriginRequestPolicyId),
    Functions: (wanted.FunctionAssociations ?? []).map((association) => ({ EventType: association.EventType, FunctionARN: resolve(association.FunctionARN) })),
  })

  const config = distribution.Properties.DistributionConfig

  return {
    id: physical[logical],
    // When CloudFormation last created or updated the distribution.
    deployedAt: deployed[logical],
    behaviours: [['default', behaviour(config.DefaultCacheBehavior)], ...(config.CacheBehaviors ?? []).map((wanted) => [wanted.PathPattern, behaviour(wanted)])],
  }
}

function stored(config, path) {
  return path === 'default' ? config.DefaultCacheBehavior : (config.CacheBehaviors?.Items ?? []).find((candidate) => candidate.PathPattern === path)
}

function differences(target, wanted) {
  const found = {
    AllowedMethods: target?.AllowedMethods?.Items ?? [],
    CachedMethods: target?.AllowedMethods?.CachedMethods?.Items ?? [],
    CachePolicyId: target?.CachePolicyId,
    OriginRequestPolicyId: target?.OriginRequestPolicyId || undefined,
    Functions: (target?.FunctionAssociations?.Items ?? []).map((association) => ({ EventType: association.EventType, FunctionARN: association.FunctionARN })),
  }

  return Object.keys(wanted).filter((key) => JSON.stringify(key.endsWith('Methods') ? [...(found[key] ?? [])].sort() : found[key]) !== JSON.stringify(key.endsWith('Methods') ? [...(wanted[key] ?? [])].sort() : wanted[key]))
}

async function reconcile() {
  const endpoint = process.env.AWS_ENDPOINT_URL ?? ''

  if (!/^http:\/\/floci:4566\/?$/.test(endpoint)) {
    process.stderr.write('edge.mjs reconcile works around the CloudFormation of the local emulator only; AWS_ENDPOINT_URL is not the emulator.\n')
    process.exit(2)
  }

  const { id, deployedAt, behaviours } = await declared()
  const cloudfront = new CloudFrontClient({})
  const { DistributionConfig: config, ETag: etag } = await cloudfront.send(new GetDistributionConfigCommand({ Id: id }))
  const applied = []

  for (const [path, wanted] of behaviours) {
    const target = stored(config, path)

    if (target === undefined) {
      throw new Error(`The distribution ${id} has no behaviour ${path}, which the template declares.`)
    }

    const missing = differences(target, wanted)

    if (missing.length === 0) {
      continue
    }

    target.AllowedMethods = { Quantity: wanted.AllowedMethods.length, Items: wanted.AllowedMethods, CachedMethods: { Quantity: wanted.CachedMethods.length, Items: wanted.CachedMethods } }
    target.CachePolicyId = wanted.CachePolicyId
    target.OriginRequestPolicyId = wanted.OriginRequestPolicyId
    target.FunctionAssociations = { Quantity: wanted.Functions.length, Items: wanted.Functions }

    // The policies replace the settings of the legacy cache, which CloudFront refuses next to them.
    for (const legacy of ['ForwardedValues', 'MinTTL', 'DefaultTTL', 'MaxTTL']) {
      delete target[legacy]
    }

    applied.push([path, missing])
  }

  if (applied.length > 0) {
    await cloudfront.send(new UpdateDistributionCommand({ Id: id, IfMatch: etag, DistributionConfig: config }))
    process.stdout.write(`Gave the distribution ${id} what the CloudFormation of the emulator dropped from its template (docs/limitations.md):\n`)

    for (const [path, missing] of applied) {
      process.stdout.write(`   ${path}: ${missing.join(', ')}\n`)
    }

    return
  }

  // Nothing missing: either this step gave it all before, after the last deployment, or the emulator kept it all.
  const { Distribution: distribution } = await cloudfront.send(new GetDistributionCommand({ Id: id }))
  const modifiedAt = distribution?.LastModifiedTime

  if (modifiedAt !== undefined && deployedAt !== undefined && modifiedAt.getTime() - deployedAt.getTime() > AFTER_DEPLOYMENT_MS) {
    process.stdout.write(`Nothing to apply: the distribution ${id} keeps what this step gave it on ${modifiedAt.toISOString()}; CloudFormation has not changed it since its last deployment, on ${deployedAt.toISOString()}.\n`)

    return
  }

  process.stdout.write([
    `Nothing to apply: the distribution ${id}, as CloudFormation deployed it, already holds every setting of the behaviours of its template.`,
    'The emulator no longer drops them: this workaround (edge.mjs reconcile in make cloud-up) can be retired, with its note in docs/limitations.md.',
    '',
  ].join('\n'))
}

async function check() {
  const { id, behaviours } = await declared()
  const { DistributionConfig: config } = await new CloudFrontClient({}).send(new GetDistributionConfigCommand({ Id: id }))
  const wrong = behaviours.map(([path, wanted]) => [path, differences(stored(config, path), wanted)]).filter(([, keys]) => keys.length > 0)

  process.stdout.write(`   ${wrong.length === 0 ? 'PASS' : 'FAIL'}  The distribution ${id} holds the ${behaviours.length} behaviours of its template: methods, cache and origin request policies and functions${wrong.length === 0 ? '' : `; different: ${wrong.map(([path, keys]) => `${path} (${keys.join(', ')})`).join('; ')}`}.\n`)
  process.exit(wrong.length === 0 ? 0 : 1)
}

const actions = { 'origin-secret': originSecret, reconcile, check }
const action = actions[process.argv[2] ?? '']

if (action === undefined) {
  process.stderr.write('usage: node scripts/edge.mjs origin-secret|reconcile|check\n')
  process.exit(2)
}

await action()
