// Compute: what the functions of the serverless service (infra/functions/serverless.yml) take from outside
// it. Each function gets a role of its own, with the managed policies of the other stacks it needs and
// nothing else; the origin verifier of the edge a role that may only read the secret of the edge; EventBridge
// Scheduler gets the role it invokes the scheduled functions with; and the image of the functions of the
// application gets its ECR repository. The functions and their triggers belong to the serverless
// service, which reads the names published here; every resource is defined in exactly one tool.
import { ArnFormat, Stack, type StackProps, Validations } from 'aws-cdk-lib'
import { Repository, TagMutability } from 'aws-cdk-lib/aws-ecr'
import { ManagedPolicy, PolicyDocument, PolicyStatement, Role, ServicePrincipal } from 'aws-cdk-lib/aws-iam'
import { StringParameter } from 'aws-cdk-lib/aws-ssm'
import type { Construct } from 'constructs'
import { Published } from './published.ts'
import { DATABASE_PARAMETERS, FUNCTIONS, MIGRATIONS_FUNCTION, ORIGIN_VERIFIER, functionName, parameterPrefix, removalPolicy, resourceName, type FunctionName, type StageConfig } from './stage.ts'

export interface ComputeStackProps extends StackProps {
  readonly stage: StageConfig
}

/** What each function does, and the managed policies of the other stacks (their names without the prefix) it gets. */
export const FUNCTION_ACCESS: Readonly<Record<FunctionName, { readonly purpose: string, readonly policies: readonly string[] }>> = {
  // Writes publish their events after the commit (the fast path) and dispatch the invitation and password
  // reset emails to the chain queue; the API stores uploaded certificates in the key store.
  api: {
    purpose: 'the HTTP API behind API Gateway',
    policies: ['publish-document-events', 'send-jobs', 'documents-read-write', 'use-cache-table', 'use-sessions-table', 'use-limits-table', 'use-signing-key', 'read-app-secret'],
  },
  // Every step of the electronic document chain and the delivery: XML and PDF in the bucket, signatures
  // with the key store, the emails, the locks of the pipeline in the cache, and the next event of each step.
  chain: {
    purpose: 'the consumer of the chain queue',
    policies: ['consume-chain', 'publish-document-events', 'documents-read-write', 'use-cache-table', 'use-signing-key', 'read-app-secret', 'send-email'],
  },
  webhooks: {
    purpose: 'the consumer of the webhooks queue',
    policies: ['consume-webhooks', 'publish-document-events', 'use-cache-table', 'read-app-secret'],
  },
  // The safety net of the outbox: publishes, and reads the pause of the demo seeder in the cache.
  relay: {
    purpose: 'the relay of the outbox events left behind, once a minute',
    policies: ['publish-document-events', 'use-cache-table', 'read-app-secret'],
  },
  // The sweepers record events again for stalled chains; the locks of the schedule live in the cache.
  scheduler: {
    purpose: 'the Laravel scheduler, once a minute',
    policies: ['publish-document-events', 'use-cache-table', 'read-app-secret'],
  },
  // Migrations and operation commands, which may do anything the application does, and read the queues
  // with their dead letters.
  console: {
    purpose: 'migrations and operation commands on demand',
    policies: ['publish-document-events', 'send-jobs', 'documents-read-write', 'use-cache-table', 'use-sessions-table', 'use-limits-table', 'use-signing-key', 'read-app-secret', 'send-email', 'inspect-queues'],
  },
}

/** The functions EventBridge Scheduler invokes (the schedule events of the serverless service). */
export const SCHEDULED_FUNCTIONS: readonly FunctionName[] = ['relay', 'scheduler']

export class ComputeStack extends Stack {
  readonly roles: Readonly<Record<FunctionName, Role>>
  readonly originVerifierRole: Role
  readonly repository: Repository

  constructor(scope: Construct, id: string, props: ComputeStackProps) {
    super(scope, id, props)
    const { stage } = props
    const published = new Published(this, stage, 'compute')

    // The image of the functions, by digest: the serverless service names the image it deploys as
    // <repository>@sha256:<digest>. Lambda pulls it with the permission of this policy, for the functions
    // of the stage only.
    this.repository = new Repository(this, 'FunctionImages', {
      repositoryName: resourceName(stage, 'functions'),
      imageScanOnPush: true,
      imageTagMutability: stage.functionImages.tagMutability === 'IMMUTABLE' ? TagMutability.IMMUTABLE : TagMutability.MUTABLE,
      lifecycleRules: [{ description: 'Keep the most recent images of the functions', maxImageCount: stage.functionImages.keep }],
      removalPolicy: removalPolicy(stage),
      emptyOnDelete: !stage.retainData,
    })

    this.repository.addToResourcePolicy(new PolicyStatement({
      sid: 'LambdaPullsTheImageOfItsFunctions',
      principals: [new ServicePrincipal('lambda.amazonaws.com')],
      actions: ['ecr:BatchGetImage', 'ecr:GetDownloadUrlForLayer'],
      conditions: { StringLike: { 'aws:sourceArn': this.formatArn({ service: 'lambda', resource: 'function', resourceName: resourceName(stage, '*'), arnFormat: ArnFormat.COLON_RESOURCE_NAME }) } },
    }))

    // The secrets of the two identities of the database, published by the stack that holds them (security
    // locally, database in prod): the application identity for every function, the migrations identity for
    // the console function only, which also reads the other to provision it. Inline, as a role takes at
    // most 10 managed policies.
    const applicationSecretArn = StringParameter.valueForStringParameter(this, `${parameterPrefix(stage)}/${DATABASE_PARAMETERS.secretArn}`)
    const migrationsSecretArn = StringParameter.valueForStringParameter(this, `${parameterPrefix(stage)}/${DATABASE_PARAMETERS.migrationsSecretArn}`)

    const roles: Partial<Record<FunctionName, Role>> = {}

    for (const fn of FUNCTIONS) {
      const name = functionName(stage, fn)
      const logStreams = this.formatArn({ service: 'logs', resource: 'log-group', resourceName: `/aws/lambda/${name}:*`, arnFormat: ArnFormat.COLON_RESOURCE_NAME })
      const statements = [
        // Its own log group, which the serverless service creates; Lambda names the streams.
        new PolicyStatement({ actions: ['logs:CreateLogStream', 'logs:PutLogEvents'], resources: [logStreams] }),
      ]

      statements.push(new PolicyStatement({
        actions: ['secretsmanager:GetSecretValue'],
        resources: fn === MIGRATIONS_FUNCTION ? [migrationsSecretArn, applicationSecretArn] : [applicationSecretArn],
      }))

      if (stage.network !== null) {
        // A function in a VPC reaches it through network interfaces Lambda creates with its role; EC2
        // scopes none of these actions to a resource.
        statements.push(new PolicyStatement({
          actions: ['ec2:CreateNetworkInterface', 'ec2:DescribeNetworkInterfaces', 'ec2:DescribeSubnets', 'ec2:DeleteNetworkInterface', 'ec2:AssignPrivateIpAddresses', 'ec2:UnassignPrivateIpAddresses'],
          resources: ['*'],
        }))
      }

      const role = new Role(this, `${pascal(fn)}FunctionRole`, {
        roleName: `${name}-function`,
        description: `Role of the function ${name}: ${FUNCTION_ACCESS[fn].purpose}`,
        assumedBy: new ServicePrincipal('lambda.amazonaws.com'),
        managedPolicies: FUNCTION_ACCESS[fn].policies.map((policy) => ManagedPolicy.fromManagedPolicyName(this, `${pascal(fn)}${pascal(policy)}`, resourceName(stage, policy))),
        inlinePolicies: { function: new PolicyDocument({ statements }) },
      })

      Validations.of(role).acknowledge({
        id: `AwsSolutions::AwsSolutions-IAM5[Resource::${logStreams}]`,
        reason: 'Lambda writes the logs of each execution environment to a stream it names when the environment starts; the resource is the log group of this function only.',
      })

      if (stage.network !== null) {
        Validations.of(role).acknowledge({
          id: 'AwsSolutions::AwsSolutions-IAM5[Resource::*]',
          reason: 'The network interfaces of a function in a VPC are created and removed by Lambda with the role of the function, and EC2 allows no resource for these actions (as in AWSLambdaVPCAccessExecutionRole).',
        })
      }

      roles[fn] = role
      published.add(`${pascal(fn)}FunctionRoleArn`, `roles/${fn}`, role.roleArn, `Role of the function ${name}`)
    }

    this.roles = roles as Record<FunctionName, Role>

    // The origin verifier, the authorizer of the HTTP API: its log group and the secret of the edge, nothing else.
    // It runs outside the VPC and reaches no database.
    const verifier = resourceName(stage, ORIGIN_VERIFIER)
    const verifierLogs = this.formatArn({ service: 'logs', resource: 'log-group', resourceName: `/aws/lambda/${verifier}:*`, arnFormat: ArnFormat.COLON_RESOURCE_NAME })
    const originSecretArn = StringParameter.valueForStringParameter(this, `${parameterPrefix(stage)}/security/origin-secret-arn`)
    this.originVerifierRole = new Role(this, 'OriginVerifierFunctionRole', {
      roleName: `${verifier}-function`,
      description: `Role of the function ${verifier}: the authorizer of the HTTP API, which reads the secret of the edge`,
      assumedBy: new ServicePrincipal('lambda.amazonaws.com'),
      inlinePolicies: {
        function: new PolicyDocument({
          statements: [
            new PolicyStatement({ actions: ['logs:CreateLogStream', 'logs:PutLogEvents'], resources: [verifierLogs] }),
            new PolicyStatement({ actions: ['secretsmanager:GetSecretValue'], resources: [originSecretArn] }),
          ],
        }),
      },
    })

    Validations.of(this.originVerifierRole).acknowledge({
      id: `AwsSolutions::AwsSolutions-IAM5[Resource::${verifierLogs}]`,
      reason: 'Lambda writes the logs of each execution environment to a stream it names when the environment starts; the resource is the log group of this function only.',
    })

    published.add('OriginVerifierFunctionRoleArn', `roles/${ORIGIN_VERIFIER}`, this.originVerifierRole.roleArn, `Role of the function ${verifier}`)

    // EventBridge Scheduler invokes the scheduled functions with this role, from this account only.
    const schedules = new Role(this, 'SchedulesRole', {
      roleName: resourceName(stage, 'schedules'),
      description: `Role EventBridge Scheduler invokes the scheduled functions of dian-einvoice (${stage.name}) with`,
      assumedBy: new ServicePrincipal('scheduler.amazonaws.com', { conditions: { StringEquals: { 'aws:SourceAccount': this.account } } }),
      inlinePolicies: {
        invoke: new PolicyDocument({
          statements: [new PolicyStatement({
            actions: ['lambda:InvokeFunction'],
            resources: SCHEDULED_FUNCTIONS.map((fn) => this.formatArn({ service: 'lambda', resource: 'function', resourceName: functionName(stage, fn), arnFormat: ArnFormat.COLON_RESOURCE_NAME })),
          })],
        }),
      },
    })

    published.add('SchedulesRoleArn', 'roles/schedules', schedules.roleArn, 'Role EventBridge Scheduler invokes the scheduled functions with')
    published.add('FunctionImagesRepositoryName', 'functions-repository-name', this.repository.repositoryName, 'ECR repository of the image of the functions')
    published.add('FunctionImagesRepositoryUri', 'functions-repository-uri', this.repository.repositoryUri, 'URI of the ECR repository of the image of the functions')
  }
}

/** chain → Chain, send-email → SendEmail: logical ids. */
function pascal(name: string): string {
  return name.split('-').map((part) => part.charAt(0).toUpperCase() + part.slice(1)).join('')
}
