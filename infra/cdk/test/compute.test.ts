// Assertions on the stack compute of both stages: a role of its own for each function, with the managed
// policies of the other stacks it needs and nothing else, the role of EventBridge Scheduler, and the ECR
// repository of the image of the functions.
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { Match } from 'aws-cdk-lib/assertions'
import { FUNCTION_ACCESS, SCHEDULED_FUNCTIONS } from '../lib/compute-stack.ts'
import { FUNCTIONS, STAGES } from '../lib/stage.ts'
import { stageOf, template } from './support.ts'

interface RoleProperties {
  readonly RoleName: string
  readonly AssumeRolePolicyDocument: { readonly Statement: readonly { readonly Principal: { readonly Service: string }, readonly Condition?: unknown }[] }
  readonly ManagedPolicyArns: readonly string[]
  readonly Policies: readonly { readonly PolicyDocument: { readonly Statement: readonly { readonly Action: string | readonly string[], readonly Resource: unknown }[] } }[]
}

for (const name of ['local', 'prod'] as const) {
  const config = STAGES[name]
  const stacks = stageOf(name)
  const prefix = `dian-einvoice-${name}-`
  const compute = template(stacks.compute)
  const roles = Object.values(compute.findResources('AWS::IAM::Role')) as { Properties: RoleProperties }[]

  describe(`compute of the stage ${name}`, () => {
    it('gives each function a role of its own with the managed policies it needs, which exist in the stage', () => {
      const policies = new Set([stacks.messaging, stacks.storage, stacks.data, stacks.security, stacks.mail].flatMap((stack) => (Object.values(template(stack).findResources('AWS::IAM::ManagedPolicy')) as { Properties: { ManagedPolicyName: string } }[]).map((policy) => policy.Properties.ManagedPolicyName)))

      for (const fn of FUNCTIONS) {
        const role = roles.find((candidate) => candidate.Properties.RoleName === `${prefix}${fn}-function`)?.Properties
        assert.ok(role !== undefined, `a role for ${fn}`)
        assert.deepEqual(role.AssumeRolePolicyDocument.Statement.map((statement) => statement.Principal.Service), ['lambda.amazonaws.com'])
        assert.deepEqual([...role.ManagedPolicyArns].sort(), FUNCTION_ACCESS[fn].policies.map((policy) => `arn:aws:iam::${config.account}:policy/${prefix}${policy}`).sort())
        assert.ok(role.ManagedPolicyArns.length <= 10, 'IAM attaches at most 10 managed policies to a role')

        for (const policy of FUNCTION_ACCESS[fn].policies) {
          assert.ok(policies.has(`${prefix}${policy}`), `${fn}: the managed policy ${prefix}${policy} exists`)
        }

        // Its own log group, in every stage; in prod also the network interfaces and the database secret.
        const statements = role.Policies.flatMap((policy) => policy.PolicyDocument.Statement)
        assert.deepEqual(statements[0], { Action: ['logs:CreateLogStream', 'logs:PutLogEvents'], Effect: 'Allow', Resource: `arn:aws:logs:${config.region}:${config.account}:log-group:/aws/lambda/${prefix}${fn}:*` })
        assert.equal(statements.length, name === 'prod' ? 3 : 1)

        if (name === 'prod') {
          assert.deepEqual(statements[1]?.Resource, '*')
          assert.ok([statements[1]?.Action].flat().every((action) => typeof action === 'string' && action.startsWith('ec2:') && /NetworkInterface|Subnets|PrivateIpAddresses/.test(action)))
          assert.deepEqual(statements[2]?.Action, 'secretsmanager:GetSecretValue')
          assert.match(String((statements[2]?.Resource as { Ref?: string } | undefined)?.Ref), /^SsmParameterValue.*databasesecretarn/)
        }
      }

      assert.equal(roles.length, FUNCTIONS.length + 1)

      // The secret of the managed database comes from the parameter Infrastructure task 3 publishes.
      const parameters = compute.toJSON() as { Parameters?: Record<string, { Default?: string }> }
      const secret = Object.entries(parameters.Parameters ?? {}).find(([key]) => key.includes('databasesecretarn'))?.[1]
      assert.equal(secret?.Default, name === 'prod' ? '/dian-einvoice/prod/database/secret-arn' : undefined)
    })

    it('only the consumers consume their queues, only the console reads the dead letters, only the API and the chain sign', () => {
      const holders = (policy: string) => FUNCTIONS.filter((fn) => FUNCTION_ACCESS[fn].policies.includes(policy))
      assert.deepEqual(holders('consume-chain'), ['chain'])
      assert.deepEqual(holders('consume-webhooks'), ['webhooks'])
      assert.deepEqual(holders('inspect-queues'), ['console'])
      assert.deepEqual(holders('use-signing-key'), ['api', 'chain', 'console'])
      assert.deepEqual(holders('use-sessions-table'), ['api', 'console'])
      assert.ok(FUNCTIONS.every((fn) => FUNCTION_ACCESS[fn].policies.includes('read-app-secret')))
    })

    it('lets EventBridge Scheduler of this account invoke only the scheduled functions', () => {
      const role = roles.find((candidate) => candidate.Properties.RoleName === `${prefix}schedules`)?.Properties
      assert.ok(role !== undefined)
      assert.deepEqual(role.AssumeRolePolicyDocument.Statement, [{ Action: 'sts:AssumeRole', Effect: 'Allow', Principal: { Service: 'scheduler.amazonaws.com' }, Condition: { StringEquals: { 'aws:SourceAccount': config.account } } }])
      assert.deepEqual(role.Policies.flatMap((policy) => policy.PolicyDocument.Statement), [{
        Action: 'lambda:InvokeFunction',
        Effect: 'Allow',
        Resource: SCHEDULED_FUNCTIONS.map((fn) => `arn:aws:lambda:${config.region}:${config.account}:function:${prefix}${fn}`),
      }])
    })

    it('keeps the image of the functions in an ECR repository that only Lambda pulls from, for the functions of the stage', () => {
      compute.hasResource('AWS::ECR::Repository', {
        DeletionPolicy: name === 'prod' ? 'Retain' : 'Delete',
        Properties: {
          RepositoryName: `${prefix}functions`,
          ImageScanningConfiguration: { ScanOnPush: true },
          ImageTagMutability: config.functionImages.tagMutability,
          LifecyclePolicy: { LifecyclePolicyText: Match.stringLikeRegexp(`"countNumber":${config.functionImages.keep}`) },
          RepositoryPolicyText: {
            Statement: [{
              Action: ['ecr:BatchGetImage', 'ecr:GetDownloadUrlForLayer'],
              Effect: 'Allow',
              Principal: { Service: 'lambda.amazonaws.com' },
              Condition: { StringLike: { 'aws:sourceArn': `arn:aws:lambda:${config.region}:${config.account}:function:${prefix}*` } },
              Sid: 'LambdaPullsTheImageOfItsFunctions',
            }],
          },
        },
      })
    })

    it('deploys after the stacks whose policies its roles attach', () => {
      const dependencies = stacks.compute.dependencies.map((stack) => stack.stackName).sort()
      assert.deepEqual(dependencies, [`${prefix}data`, `${prefix}mail`, `${prefix}messaging`, `${prefix}security`, `${prefix}storage`])
    })
  })
}
