// The delivery of prod (Infrastructure task 6), on the synthesized templates and the templates osls packaged, never
// deployed: the repository of the image of the functions (immutable tags, scan on push, lifecycle, encryption), the
// role of the deployment workflow (trusted through OpenID Connect by exactly one environment of one repository, an
// hour at most, no administrator and no wildcard without its reason), the reuse of a provider the account already
// has, the role CloudFormation deploys the functions with, the functions deployed by the digest of their image, and
// the reserved concurrency as a parameter of the stage.
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { Match } from 'aws-cdk-lib/assertions'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { GITHUB_OIDC, deliveryRoleNames, githubSubject, wildcards } from '../lib/delivery-stack.ts'
import { STAGES } from '../lib/stage.ts'
import { functionsTemplate, stageOf, template, variantOf } from './support.ts'

const prod = STAGES.prod
const delivery = prod.delivery

if (delivery === null) {
  throw new Error('prod has a delivery pipeline.')
}

interface Statement {
  readonly Sid?: string
  readonly Effect: string
  readonly Action: string | string[]
  readonly Resource: string | string[]
  readonly Principal?: Record<string, unknown>
  readonly Condition?: Record<string, Record<string, unknown>>
}

interface RoleProperties {
  readonly RoleName: string
  readonly AssumeRolePolicyDocument: { Statement: Statement[] }
  readonly Policies?: { PolicyName: string, PolicyDocument: { Statement: Statement[] } }[]
  readonly ManagedPolicyArns?: unknown[]
  readonly MaxSessionDuration?: number
}

function roles(stacks: ReturnType<typeof stageOf>): Record<string, RoleProperties> {
  assert.ok(stacks.delivery !== null)

  return Object.fromEntries(Object.values(template(stacks.delivery).findResources('AWS::IAM::Role')).map((role) => {
    const properties = (role as { Properties: RoleProperties }).Properties

    return [properties.RoleName, properties]
  }))
}

const names = deliveryRoleNames(prod)
const prodRoles = roles(stageOf('prod'))
const deploy = prodRoles[names.deploy]
const functionsDeployment = prodRoles[names.functionsDeployment]
const list = (value: string | string[]): string[] => [value].flat()

describe('the repository of the image of the functions', () => {
  for (const stage of ['local', 'prod'] as const) {
    it(`in ${stage}: scanned on push, ${stage === 'prod' ? 'immutable tags, encrypted with the AWS managed key of ECR' : 'tags the emulator moves on every make cloud-up'}, its recent images kept`, () => {
      const repository = template(stageOf(stage).compute)
      repository.hasResourceProperties('AWS::ECR::Repository', {
        RepositoryName: `dian-einvoice-${stage}-functions`,
        ImageScanningConfiguration: { ScanOnPush: true },
        ImageTagMutability: stage === 'prod' ? 'IMMUTABLE' : 'MUTABLE',
        EncryptionConfiguration: stage === 'prod' ? { EncryptionType: 'KMS' } : Match.absent(),
        LifecyclePolicy: { LifecyclePolicyText: Match.stringLikeRegexp(`"countNumber":${STAGES[stage].functionImages.keep}`) },
      })
    })
  }

  it('is what the functions name their image from, by digest, in both stages', () => {
    for (const stage of ['local', 'prod'] as const) {
      const images = Object.values(functionsTemplate(stage).Resources).filter((resource) => resource.Type === 'AWS::Lambda::Function' && resource.Properties.PackageType === 'Image')
      assert.equal(images.length, 6)

      for (const fn of images) {
        assert.match(String((fn.Properties.Code as { ImageUri: string }).ImageUri), new RegExp(`^\\d{12}\\.dkr\\.ecr\\.us-east-1\\.amazonaws\\.com/dian-einvoice-${stage}-functions@sha256:[0-9a-f]{64}$`))
      }
    }
  })
})

describe('the role of the deployment workflow (prod)', () => {
  it('is trusted by the OpenID Connect provider of GitHub Actions, for the environment production of this repository only', () => {
    assert.ok(deploy !== undefined)
    assert.equal(deploy.AssumeRolePolicyDocument.Statement.length, 1)
    const [trust] = deploy.AssumeRolePolicyDocument.Statement
    assert.ok(trust !== undefined)
    assert.equal(trust.Effect, 'Allow')
    assert.equal(trust.Action, 'sts:AssumeRoleWithWebIdentity')
    assert.deepEqual(Object.keys(trust.Principal ?? {}), ['Federated'])
    // Exactly the audience and the subject, compared as they are: no StringLike, no other condition.
    assert.deepEqual(trust.Condition, {
      StringEquals: {
        'token.actions.githubusercontent.com:aud': 'sts.amazonaws.com',
        'token.actions.githubusercontent.com:sub': 'repo:example-owner/dian-einvoice:environment:production',
      },
    })
    assert.equal(githubSubject(delivery), 'repo:example-owner/dian-einvoice:environment:production')
  })

  it('creates the provider of GitHub Actions, without a pinned thumbprint, when the account has none', () => {
    const stack = stageOf('prod').delivery
    assert.ok(stack !== null)
    const providers = template(stack).findResources('AWS::IAM::OIDCProvider')
    assert.equal(Object.keys(providers).length, 1)
    const [provider] = Object.values(providers) as { Properties: { Url: string, ClientIdList: string[], ThumbprintList?: string[] } }[]
    assert.equal(provider?.Properties.Url, `https://${GITHUB_OIDC.host}`)
    assert.deepEqual(provider?.Properties.ClientIdList, [GITHUB_OIDC.audience])
    assert.equal(provider?.Properties.ThumbprintList, undefined)
    assert.deepEqual(deploy?.AssumeRolePolicyDocument.Statement[0]?.Principal, { Federated: { Ref: Object.keys(providers)[0] } })
  })

  it('reuses the provider the account already has, and takes the owner and the repository as parameters', () => {
    const existing = 'arn:aws:iam::111111111111:oidc-provider/token.actions.githubusercontent.com'
    const { stacks } = variantOf({ ...prod, delivery: { ...delivery, githubOwner: 'another-owner', githubRepository: 'another-repository', oidcProviderArn: existing } })
    assert.ok(stacks.delivery !== null)
    assert.equal(Object.keys(template(stacks.delivery).findResources('AWS::IAM::OIDCProvider')).length, 0)
    const trust = roles(stacks)[names.deploy]?.AssumeRolePolicyDocument.Statement[0]
    assert.deepEqual(trust?.Principal, { Federated: existing })
    assert.equal(trust?.Condition?.StringEquals?.['token.actions.githubusercontent.com:sub'], 'repo:another-owner/another-repository:environment:production')
  })

  it('lasts an hour at most, and holds no managed policy, no administrator and no action with a wildcard', () => {
    assert.equal(deploy?.MaxSessionDuration, 3600)

    for (const role of [deploy, functionsDeployment]) {
      assert.ok(role !== undefined)
      assert.equal(role.ManagedPolicyArns, undefined, `${role.RoleName} attaches no managed policy (AdministratorAccess, PowerUserAccess...)`)

      for (const statement of (role.Policies ?? []).flatMap((policy) => policy.PolicyDocument.Statement)) {
        assert.equal(statement.Effect, 'Allow')

        for (const action of list(statement.Action)) {
          assert.match(action, /^[a-z0-9-]+:[A-Za-z]+$/, `${role.RoleName}: ${action} names one action`)
          assert.ok(!/^iam:(Create|Put|Attach|Update|Delete)/.test(action), `${role.RoleName} changes no IAM: ${action}`)
        }
      }
    }
  })

  it('has a written reason for every resource with a wildcard, and passes its roles only to the service that needs them', () => {
    const reasons = wildcards(prod)

    for (const role of [deploy, functionsDeployment]) {
      for (const statement of (role?.Policies ?? []).flatMap((policy) => policy.PolicyDocument.Statement)) {
        for (const resource of list(statement.Resource).filter((candidate) => candidate.includes('*'))) {
          assert.ok((reasons[resource] ?? '').length >= 60, `${role?.RoleName}: ${resource} (${statement.Sid}) has its reason`)
        }

        if (list(statement.Action).includes('iam:PassRole')) {
          assert.ok(typeof statement.Condition?.StringEquals?.['iam:PassedToService'] === 'string', `${statement.Sid} passes a role to one service only`)
        }
      }
    }

    // Every reason is used: no stale exception.
    const used = new Set([deploy, functionsDeployment].flatMap((role) => (role?.Policies ?? []).flatMap((policy) => policy.PolicyDocument.Statement.flatMap((statement) => list(statement.Resource)))))
    assert.deepEqual(Object.keys(reasons).filter((resource) => !used.has(resource)), [])
  })

  it('does what the steps of scripts/deploy.sh do, on the resources of this project only', () => {
    const allowed = (action: string): string[] => (deploy?.Policies ?? []).flatMap((policy) => policy.PolicyDocument.Statement).filter((statement) => list(statement.Action).includes(action)).flatMap((statement) => list(statement.Resource))
    const account = prod.account

    assert.deepEqual(allowed('sts:AssumeRole').sort(), ['deploy', 'file-publishing', 'lookup'].map((role) => `arn:aws:iam::${account}:role/cdk-hnb659fds-${role}-role-${account}-us-east-1`).sort())
    assert.deepEqual(allowed('cloudformation:UpdateStack').sort(), [`arn:aws:cloudformation:us-east-1:${account}:stack/dian-einvoice-prod-functions/*`, `arn:aws:cloudformation:us-east-1:${account}:changeSet/dian-einvoice-prod-functions-change-set/*`].sort())
    assert.deepEqual(allowed('iam:PassRole'), [`arn:aws:iam::${account}:role/${names.functionsDeployment}`])
    assert.deepEqual(allowed('ecr:PutImage'), [`arn:aws:ecr:us-east-1:${account}:repository/dian-einvoice-prod-functions`])
    assert.deepEqual(allowed('lambda:InvokeFunction'), [`arn:aws:lambda:us-east-1:${account}:function:dian-einvoice-prod-console`])
    assert.deepEqual(allowed('s3:PutObject').sort(), [`arn:aws:s3:::dian-einvoice-prod-deployments-${account}-us-east-1/serverless/dian-einvoice/prod/*`, `arn:aws:s3:::dian-einvoice-prod-panel-${account}-us-east-1/*`].sort())
    const invalidation = (deploy?.Policies ?? []).flatMap((policy) => policy.PolicyDocument.Statement).find((statement) => list(statement.Action).includes('cloudfront:CreateInvalidation'))
    assert.deepEqual(invalidation?.Condition, { StringEquals: { 'aws:ResourceTag/project': 'dian-einvoice', 'aws:ResourceTag/stage': 'prod' } })
    // Nothing that would let the workflow change the functions, their code or their roles outside CloudFormation.
    assert.deepEqual(['lambda:UpdateFunctionCode', 'lambda:CreateFunction', 'lambda:PutFunctionConcurrency', 'iam:CreateRole', 'secretsmanager:GetSecretValue'].filter((action) => allowed(action).length > 0), [])
  })
})

describe('the role CloudFormation deploys the functions with (prod)', () => {
  it('is assumed by CloudFormation only, and is the one the serverless service names', () => {
    assert.ok(functionsDeployment !== undefined)
    assert.deepEqual(functionsDeployment.AssumeRolePolicyDocument.Statement.map((statement) => [statement.Action, statement.Principal]), [['sts:AssumeRole', { Service: 'cloudformation.amazonaws.com' }]])
    const state = JSON.parse(readFileSync(join(process.env.FUNCTIONS_TEMPLATES ?? join(import.meta.dirname, '../../functions'), '.package', 'prod', 'serverless-state.json'), 'utf8')) as { service: { provider: { iam?: { deploymentRole?: string } } } }
    assert.equal(state.service.provider.iam?.deploymentRole, `arn:aws:iam::${prod.account}:role/${names.functionsDeployment}`)
  })

  it('creates the mappings of the functions of the stage only, and passes the roles of the functions only to Lambda', () => {
    const statements = (functionsDeployment?.Policies ?? []).flatMap((policy) => policy.PolicyDocument.Statement)
    const mappings = statements.find((statement) => list(statement.Action).includes('lambda:CreateEventSourceMapping'))
    assert.deepEqual(mappings?.Condition, { ArnLike: { 'lambda:FunctionArn': `arn:aws:lambda:us-east-1:${prod.account}:function:dian-einvoice-prod-*` } })
    const passes = statements.filter((statement) => list(statement.Action).includes('iam:PassRole')).map((statement) => [statement.Resource, statement.Condition?.StringEquals?.['iam:PassedToService']])
    assert.deepEqual(passes, [[`arn:aws:iam::${prod.account}:role/dian-einvoice-prod-*-function`, 'lambda.amazonaws.com'], [`arn:aws:iam::${prod.account}:role/dian-einvoice-prod-schedules`, 'scheduler.amazonaws.com']])
  })
})

describe('the reserved concurrency, a parameter of the stage', () => {
  it('is reserved in both stages by default, and left out for an account whose quota is too low to reserve it', () => {
    const reserved = (stage: 'local' | 'prod' | 'prod-unreserved') => Object.values(functionsTemplate(stage).Resources).filter((resource) => resource.Type === 'AWS::Lambda::Function').map((fn) => [String(fn.Properties.FunctionName).replace(/^dian-einvoice-prod-|^dian-einvoice-local-/, ''), fn.Properties.ReservedConcurrentExecutions])
    const expected = { api: 40, chain: 10, webhooks: 5, relay: 1, scheduler: 2, console: 2, 'origin-verifier': 10, notifier: 2 }

    assert.deepEqual(Object.fromEntries(reserved('local')), expected)
    assert.deepEqual(Object.fromEntries(reserved('prod')), expected)
    assert.deepEqual(Object.fromEntries(reserved('prod-unreserved')), Object.fromEntries(Object.keys(expected).map((fn) => [fn, { Ref: 'AWS::NoValue' }])))
  })

  it('leaves the mappings of the queues capped without it, and the console function may run an image of its own before the others', () => {
    const unreserved = functionsTemplate('prod-unreserved').Resources
    const caps = Object.values(unreserved).filter((resource) => resource.Type === 'AWS::Lambda::EventSourceMapping').map((mapping) => (mapping.Properties.ScalingConfig as { MaximumConcurrency: number }).MaximumConcurrency)
    assert.deepEqual(caps.sort(), [10, 5].sort())
    const images = Object.values(unreserved).filter((resource) => resource.Type === 'AWS::Lambda::Function' && resource.Properties.PackageType === 'Image').map((fn) => [String(fn.Properties.FunctionName), String((fn.Properties.Code as { ImageUri: string }).ImageUri).slice(-8)])
    assert.deepEqual(Object.fromEntries(images), { 'dian-einvoice-prod-api': '00000000', 'dian-einvoice-prod-chain': '00000000', 'dian-einvoice-prod-webhooks': '00000000', 'dian-einvoice-prod-relay': '00000000', 'dian-einvoice-prod-scheduler': '00000000', 'dian-einvoice-prod-console': '11111111' })
  })
})
