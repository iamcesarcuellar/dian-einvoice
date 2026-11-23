// Delivery (prod only, synthesized and validated, never deployed): what the deployment
// pipeline of a real account needs, and nothing more.
//
// - The OpenID Connect provider of GitHub Actions, or the one the account already has (an account holds one per URL).
// - The deploy role, which the workflow .github/workflows/deploy.yml assumes with the token GitHub gives each job: no
//   long-lived key exists anywhere, the credentials last an hour, and the trust names exactly the environment
//   production of one repository, whose owner and name are deployment parameters. Its permissions are those of the
//   steps of scripts/deploy.sh, each on the resources of this project: cdk deploy through the roles of the bootstrap
//   of CDK, osls deploy of the stack of the functions through the functions deployment role below, the image pushed
//   to its repository, the console function invoked for the migrations, the web app uploaded to its bucket and
//   invalidated, and the reads of the smoke test and the summary.
// - The functions deployment role, which CloudFormation deploys the stack of the functions with: the functions, their
//   HTTP API, log groups, schedules, mappings, the subscription of the notifier and the parameter the stack publishes.
//
// Every resource with a wildcard says why in WILDCARDS, which acknowledges it in cdk-nag; a test holds the roles to
// them (test/delivery.test.ts). The reasons of each choice are in docs/deployment.md.
import { Duration, Stack, type StackProps, Validations } from 'aws-cdk-lib'
import { OidcProviderNative, PolicyDocument, PolicyStatement, Role, ServicePrincipal, WebIdentityPrincipal } from 'aws-cdk-lib/aws-iam'
import type { Construct } from 'constructs'
import { Published } from './published.ts'
import { PROJECT, bucketName, functionName, parameterPrefix, resourceName, type DeliverySettings, type StageConfig } from './stage.ts'

export interface DeliveryStackProps extends StackProps {
  readonly stage: StageConfig
  readonly delivery: DeliverySettings
}

/** The issuer of the tokens of GitHub Actions and the audience the official action asks for. */
export const GITHUB_OIDC = { host: 'token.actions.githubusercontent.com', audience: 'sts.amazonaws.com' } as const

/** The role the workflow assumes, and the one CloudFormation deploys the stack of the functions with. */
export function deliveryRoleNames(stage: StageConfig): { deploy: string, functionsDeployment: string } {
  return { deploy: resourceName(stage, 'deploy'), functionsDeployment: resourceName(stage, 'functions-deployment') }
}

/** The subject of the token of a job of the environment of a repository, which the trust of the deploy role names. */
export function githubSubject(delivery: DeliverySettings): string {
  return `repo:${delivery.githubOwner}/${delivery.githubRepository}:environment:${delivery.environment}`
}

/**
 * Every resource with a wildcard in the policies of the two roles, with the reason it cannot be narrower. The
 * resources are written with the account and the region of the stage, so the strings are those cdk-nag reports.
 */
export function wildcards(stage: StageConfig): Readonly<Record<string, string>> {
  const { account, region } = stage
  const prefix = `${PROJECT}-${stage.name}`

  return {
    '*': 'ecr:GetAuthorizationToken, cloudformation:ValidateTemplate, the describe actions of EC2 that Lambda calls for a function in a VPC, the log deliveries of the access log of API Gateway, and lambda:CreateEventSourceMapping (held to the functions of the stage by lambda:FunctionArn) take no resource ARN.',
    [`arn:aws:cloudformation:${region}:${account}:stack/${prefix}-functions/*`]: 'The stack of the functions by its name; CloudFormation appends an id of its own to the ARN of a stack.',
    [`arn:aws:cloudformation:${region}:${account}:changeSet/${prefix}-functions-change-set/*`]: 'The change set osls creates for each deployment of the stack of the functions, by its name; CloudFormation appends an id of its own.',
    [`arn:aws:cloudformation:${region}:${account}:stack/${prefix}-*/*`]: 'DescribeStacks of the stacks of the stage, for the summary of a deployment: read only.',
    [`arn:aws:s3:::${bucketName(stage, 'deployments')}/serverless/dian-einvoice/${stage.name}/*`]: 'The artifacts osls uploads for each deployment of the stage, under a prefix with its time.',
    [`arn:aws:s3:::${bucketName(stage, 'panel')}/*`]: 'Every file of the build of the web app, whose names carry the hash of their content.',
    [`arn:aws:cloudfront::${account}:distribution/*`]: 'The distribution of the edge, whose id CloudFormation gives when the pipeline deploys the stack edge: held to it by its tags project and stage.',
    [`arn:aws:ssm:${region}:${account}:parameter${parameterPrefix(stage)}/*`]: 'The parameters the stacks of the stage publish, which the smoke test and CloudFormation read: read only.',
    [`arn:aws:ssm:${region}:${account}:parameter${parameterPrefix(stage)}/functions/*`]: 'The parameters the stack of the functions publishes under /dian-einvoice/<stage>/functions/ (the id of its HTTP API).',
    [`arn:aws:lambda:${region}:${account}:function:${prefix}-*`]: 'The functions of the stage, which the stack of the functions names dian-einvoice-<stage>-<function>.',
    [`arn:aws:lambda:${region}:${account}:event-source-mapping:*`]: 'The mappings of the queues, whose ids Lambda gives; held to the functions of the stage by lambda:FunctionArn.',
    [`arn:aws:iam::${account}:role/${prefix}-*-function`]: 'The roles of the functions of the stage, dian-einvoice-<stage>-<function>-function (stack compute), passed only to Lambda.',
    [`arn:aws:apigateway:${region}::/apis/*`]: 'The HTTP API of the stack of the functions, its routes, integrations, authorizers and stage, whose ids API Gateway gives.',
    [`arn:aws:apigateway:${region}::/tags/*`]: 'The tags of the HTTP API of the stack of the functions, whose ARN carries the id API Gateway gives it.',
    [`arn:aws:logs:${region}:${account}:log-group:/aws/lambda/${prefix}-*`]: 'The log groups of the functions of the stage, /aws/lambda/dian-einvoice-<stage>-<function>, which the stack of the functions creates.',
    [`arn:aws:logs:${region}:${account}:log-group:/aws/http-api/${prefix}-*`]: 'The log group of the access log of the HTTP API of the stage, which the stack of the functions creates.',
    [`arn:aws:logs:${region}:${account}:log-group:*`]: 'DescribeLogGroups, which CloudFormation calls for the log groups it manages, takes the log groups of the account: read only.',
    [`arn:aws:scheduler:${region}:${account}:schedule/default/${prefix}-*`]: 'The schedules of the relay and the scheduler of the stage, dian-einvoice-<stage>-<function>, in the default group.',
    [`arn:aws:sns:${region}:${account}:${prefix}-alarms:*`]: 'The subscription of the notifier to the topic of the alarms, whose id SNS appends to the ARN of the topic.',
  }
}

export class DeliveryStack extends Stack {
  readonly deployRole: Role
  readonly functionsDeploymentRole: Role
  readonly oidcProviderArn: string

  constructor(scope: Construct, id: string, props: DeliveryStackProps) {
    super(scope, id, props)
    const { stage, delivery } = props
    const { account, region } = stage
    const prefix = `${PROJECT}-${stage.name}`
    const names = deliveryRoleNames(stage)
    const published = new Published(this, stage, 'delivery')

    // The provider of the tokens of GitHub Actions. IAM trusts the certificate of GitHub through its own library of
    // root authorities, so no thumbprint is pinned.
    this.oidcProviderArn = delivery.oidcProviderArn ?? new OidcProviderNative(this, 'GitHubActions', {
      url: `https://${GITHUB_OIDC.host}`,
      clientIds: [GITHUB_OIDC.audience],
    }).oidcProviderArn

    const arns = {
      functionsStack: `arn:aws:cloudformation:${region}:${account}:stack/${prefix}-functions/*`,
      functionsChangeSet: `arn:aws:cloudformation:${region}:${account}:changeSet/${prefix}-functions-change-set/*`,
      stacks: `arn:aws:cloudformation:${region}:${account}:stack/${prefix}-*/*`,
      deploymentsBucket: `arn:aws:s3:::${bucketName(stage, 'deployments')}`,
      deployments: `arn:aws:s3:::${bucketName(stage, 'deployments')}/serverless/dian-einvoice/${stage.name}/*`,
      panelBucket: `arn:aws:s3:::${bucketName(stage, 'panel')}`,
      panel: `arn:aws:s3:::${bucketName(stage, 'panel')}/*`,
      distributions: `arn:aws:cloudfront::${account}:distribution/*`,
      repository: `arn:aws:ecr:${region}:${account}:repository/${resourceName(stage, 'functions')}`,
      console: `arn:aws:lambda:${region}:${account}:function:${functionName(stage, 'console')}`,
      functions: `arn:aws:lambda:${region}:${account}:function:${prefix}-*`,
      mappings: `arn:aws:lambda:${region}:${account}:event-source-mapping:*`,
      parameters: `arn:aws:ssm:${region}:${account}:parameter${parameterPrefix(stage)}/*`,
      functionsParameters: `arn:aws:ssm:${region}:${account}:parameter${parameterPrefix(stage)}/functions/*`,
      functionRoles: `arn:aws:iam::${account}:role/${prefix}-*-function`,
      schedulesRole: `arn:aws:iam::${account}:role/${resourceName(stage, 'schedules')}`,
      functionsDeploymentRole: `arn:aws:iam::${account}:role/${names.functionsDeployment}`,
      bootstrapRoles: ['deploy', 'file-publishing', 'lookup'].map((role) => `arn:aws:iam::${account}:role/cdk-${delivery.cdkQualifier}-${role}-role-${account}-${region}`),
      apis: `arn:aws:apigateway:${region}::/apis`,
      api: `arn:aws:apigateway:${region}::/apis/*`,
      apiTags: `arn:aws:apigateway:${region}::/tags/*`,
      functionLogs: `arn:aws:logs:${region}:${account}:log-group:/aws/lambda/${prefix}-*`,
      apiLogs: `arn:aws:logs:${region}:${account}:log-group:/aws/http-api/${prefix}-*`,
      logGroups: `arn:aws:logs:${region}:${account}:log-group:*`,
      schedules: `arn:aws:scheduler:${region}:${account}:schedule/default/${prefix}-*`,
      alarmsTopic: `arn:aws:sns:${region}:${account}:${resourceName(stage, 'alarms')}`,
      alarmsSubscriptions: `arn:aws:sns:${region}:${account}:${prefix}-alarms:*`,
    }
    const ofTheStage = { ArnLike: { 'lambda:FunctionArn': arns.functions } }

    // What CloudFormation may do with the stack of the functions.
    const functionsStatements = [
      new PolicyStatement({
        sid: 'TheFunctionsOfTheStage',
        actions: [
          'lambda:CreateFunction', 'lambda:DeleteFunction', 'lambda:GetFunction', 'lambda:GetFunctionConfiguration', 'lambda:UpdateFunctionCode', 'lambda:UpdateFunctionConfiguration',
          'lambda:PutFunctionConcurrency', 'lambda:DeleteFunctionConcurrency', 'lambda:GetFunctionConcurrency',
          'lambda:PutFunctionEventInvokeConfig', 'lambda:UpdateFunctionEventInvokeConfig', 'lambda:DeleteFunctionEventInvokeConfig', 'lambda:GetFunctionEventInvokeConfig',
          'lambda:AddPermission', 'lambda:RemovePermission', 'lambda:GetPolicy', 'lambda:TagResource', 'lambda:UntagResource', 'lambda:ListTags', 'lambda:ListVersionsByFunction',
        ],
        resources: [arns.functions],
      }),
      new PolicyStatement({ sid: 'CreateTheMappingsOfItsQueues', actions: ['lambda:CreateEventSourceMapping'], resources: ['*'], conditions: ofTheStage }),
      new PolicyStatement({ sid: 'TheMappingsOfItsQueues', actions: ['lambda:GetEventSourceMapping', 'lambda:UpdateEventSourceMapping', 'lambda:DeleteEventSourceMapping'], resources: [arns.mappings] }),
      new PolicyStatement({ sid: 'PassTheRolesOfTheFunctions', actions: ['iam:PassRole'], resources: [arns.functionRoles], conditions: { StringEquals: { 'iam:PassedToService': 'lambda.amazonaws.com' } } }),
      new PolicyStatement({ sid: 'PassTheRoleOfTheSchedules', actions: ['iam:PassRole'], resources: [arns.schedulesRole], conditions: { StringEquals: { 'iam:PassedToService': 'scheduler.amazonaws.com' } } }),
      new PolicyStatement({ sid: 'TheHttpApi', actions: ['apigateway:GET', 'apigateway:POST', 'apigateway:PATCH', 'apigateway:PUT', 'apigateway:DELETE'], resources: [arns.apis, arns.api, arns.apiTags] }),
      new PolicyStatement({
        sid: 'TheLogGroups',
        actions: ['logs:CreateLogGroup', 'logs:DeleteLogGroup', 'logs:PutRetentionPolicy', 'logs:DeleteRetentionPolicy', 'logs:TagResource', 'logs:UntagResource', 'logs:TagLogGroup', 'logs:ListTagsForResource'],
        resources: [arns.functionLogs, arns.apiLogs],
      }),
      new PolicyStatement({ sid: 'DescribeTheLogGroups', actions: ['logs:DescribeLogGroups'], resources: [arns.logGroups] }),
      // API Gateway writes its access log through a log delivery its caller creates; these actions take no resource.
      new PolicyStatement({
        sid: 'TheAccessLogOfTheHttpApi',
        actions: ['logs:CreateLogDelivery', 'logs:GetLogDelivery', 'logs:UpdateLogDelivery', 'logs:DeleteLogDelivery', 'logs:ListLogDeliveries', 'logs:PutResourcePolicy', 'logs:DescribeResourcePolicies'],
        resources: ['*'],
      }),
      new PolicyStatement({ sid: 'TheSchedules', actions: ['scheduler:CreateSchedule', 'scheduler:UpdateSchedule', 'scheduler:DeleteSchedule', 'scheduler:GetSchedule', 'scheduler:TagResource', 'scheduler:UntagResource', 'scheduler:ListTagsForResource'], resources: [arns.schedules] }),
      new PolicyStatement({ sid: 'TheSubscriptionOfTheNotifier', actions: ['sns:Subscribe', 'sns:Unsubscribe', 'sns:GetSubscriptionAttributes', 'sns:SetSubscriptionAttributes'], resources: [arns.alarmsTopic, arns.alarmsSubscriptions] }),
      new PolicyStatement({ sid: 'ThePublishedParameter', actions: ['ssm:PutParameter', 'ssm:DeleteParameter', 'ssm:AddTagsToResource', 'ssm:RemoveTagsFromResource', 'ssm:ListTagsForResource'], resources: [arns.functionsParameters] }),
      new PolicyStatement({ sid: 'TheParametersOfTheTemplate', actions: ['ssm:GetParameter', 'ssm:GetParameters'], resources: [arns.parameters] }),
      // Lambda reads the image with the credentials of whoever creates or updates a function.
      new PolicyStatement({ sid: 'TheImageOfTheFunctions', actions: ['ecr:BatchGetImage', 'ecr:GetDownloadUrlForLayer', 'ecr:GetRepositoryPolicy'], resources: [arns.repository] }),
      // Lambda checks the subnets and the security group of a function in a VPC with the same credentials.
      new PolicyStatement({ sid: 'TheNetworkOfTheFunctions', actions: ['ec2:DescribeSecurityGroups', 'ec2:DescribeSubnets', 'ec2:DescribeVpcs'], resources: ['*'] }),
      new PolicyStatement({ sid: 'TheTemplateOfEachDeployment', actions: ['s3:GetObject'], resources: [arns.deployments] }),
    ]

    // What the workflow may do: the steps of scripts/deploy.sh.
    const deployStatements = [
      // cdk diff and cdk deploy work through the roles of the bootstrap of CDK, whose execution policy the
      // account chooses when it bootstraps (docs/deployment.md).
      new PolicyStatement({ sid: 'TheRolesOfTheBootstrapOfCdk', actions: ['sts:AssumeRole'], resources: arns.bootstrapRoles }),
      new PolicyStatement({
        sid: 'TheStackOfTheFunctions',
        actions: [
          'cloudformation:CreateStack', 'cloudformation:UpdateStack', 'cloudformation:DescribeStacks', 'cloudformation:DescribeStackEvents', 'cloudformation:DescribeStackResource',
          'cloudformation:DescribeStackResources', 'cloudformation:ListStackResources', 'cloudformation:GetTemplate', 'cloudformation:SetStackPolicy',
          'cloudformation:CreateChangeSet', 'cloudformation:DescribeChangeSet', 'cloudformation:ExecuteChangeSet', 'cloudformation:DeleteChangeSet',
        ],
        resources: [arns.functionsStack, arns.functionsChangeSet],
      }),
      new PolicyStatement({ sid: 'ValidateTheTemplates', actions: ['cloudformation:ValidateTemplate'], resources: ['*'] }),
      new PolicyStatement({ sid: 'PassTheFunctionsDeploymentRole', actions: ['iam:PassRole'], resources: [arns.functionsDeploymentRole], conditions: { StringEquals: { 'iam:PassedToService': 'cloudformation.amazonaws.com' } } }),
      new PolicyStatement({ sid: 'TheBucketOfTheDeployments', actions: ['s3:ListBucket', 's3:GetBucketLocation', 's3:GetBucketPolicy', 's3:GetBucketVersioning'], resources: [arns.deploymentsBucket] }),
      new PolicyStatement({ sid: 'TheArtifactsOfTheDeployments', actions: ['s3:GetObject', 's3:PutObject', 's3:DeleteObject'], resources: [arns.deployments] }),
      new PolicyStatement({ sid: 'SignInToTheRegistry', actions: ['ecr:GetAuthorizationToken'], resources: ['*'] }),
      new PolicyStatement({
        sid: 'PushTheImageOfTheFunctions',
        actions: ['ecr:BatchCheckLayerAvailability', 'ecr:InitiateLayerUpload', 'ecr:UploadLayerPart', 'ecr:CompleteLayerUpload', 'ecr:PutImage', 'ecr:BatchGetImage', 'ecr:DescribeImages', 'ecr:DescribeRepositories'],
        resources: [arns.repository],
      }),
      new PolicyStatement({ sid: 'MigrateWithTheConsoleFunction', actions: ['lambda:InvokeFunction'], resources: [arns.console] }),
      new PolicyStatement({ sid: 'TheBucketOfTheWebApp', actions: ['s3:ListBucket', 's3:ListBucketVersions'], resources: [arns.panelBucket] }),
      new PolicyStatement({ sid: 'TheFilesOfTheWebApp', actions: ['s3:GetObject', 's3:GetObjectVersion', 's3:PutObject', 's3:DeleteObject'], resources: [arns.panel] }),
      new PolicyStatement({
        sid: 'InvalidateTheEdge',
        actions: ['cloudfront:CreateInvalidation', 'cloudfront:GetInvalidation'],
        resources: [arns.distributions],
        conditions: { StringEquals: { 'aws:ResourceTag/project': PROJECT, 'aws:ResourceTag/stage': stage.name } },
      }),
      // The smoke test and the summary: the parameters of the stage, the image each function runs, the stacks.
      new PolicyStatement({ sid: 'ReadTheParametersOfTheStage', actions: ['ssm:GetParameter', 'ssm:GetParameters'], resources: [arns.parameters] }),
      new PolicyStatement({ sid: 'ReadTheFunctionsOfTheStage', actions: ['lambda:GetFunction'], resources: [arns.functions] }),
      new PolicyStatement({ sid: 'ReadTheStacksOfTheStage', actions: ['cloudformation:DescribeStacks'], resources: [arns.stacks] }),
      new PolicyStatement({ sid: 'ReadTheApiOfTheFunctions', actions: ['apigateway:GET'], resources: [arns.api] }),
      new PolicyStatement({ sid: 'ReadTheLogsOfTheFunctions', actions: ['logs:DescribeSubscriptionFilters'], resources: [arns.functionLogs] }),
    ]

    // CloudFormation deploys the stack of the functions with this role (provider.iam.deploymentRole of the serverless
    // service): whoever runs osls deploy may only hand the template to CloudFormation.
    this.functionsDeploymentRole = new Role(this, 'FunctionsDeploymentRole', {
      roleName: names.functionsDeployment,
      description: `Role CloudFormation deploys the stack ${prefix}-functions with: its functions and their triggers, nothing else`,
      assumedBy: new ServicePrincipal('cloudformation.amazonaws.com'),
      inlinePolicies: {
        functions: new PolicyDocument({
          statements: functionsStatements,
        }),
      },
    })

    // The workflow of GitHub Actions, from the environment production of this repository only.
    this.deployRole = new Role(this, 'DeployRole', {
      roleName: names.deploy,
      description: `Role the deployment workflow of ${delivery.githubOwner}/${delivery.githubRepository} assumes from its environment ${delivery.environment}`,
      assumedBy: new WebIdentityPrincipal(this.oidcProviderArn, {
        StringEquals: {
          [`${GITHUB_OIDC.host}:aud`]: GITHUB_OIDC.audience,
          [`${GITHUB_OIDC.host}:sub`]: githubSubject(delivery),
        },
      }),
      maxSessionDuration: Duration.hours(1),
      inlinePolicies: {
        deploy: new PolicyDocument({
          statements: deployStatements,
        }),
      },
    })

    // A wildcard without its reason stops synthesis; each one is acknowledged in cdk-nag with it.
    const reasons = wildcards(stage)

    for (const [role, statements] of [[this.deployRole, deployStatements], [this.functionsDeploymentRole, functionsStatements]] as const) {
      const resources = new Set(statements.flatMap((statement) => (statement.toStatementJson() as { Resource?: string | string[] }).Resource ?? []).flat().filter((resource) => resource.includes('*')))

      for (const resource of resources) {
        const reason = reasons[resource]

        if (reason === undefined) {
          throw new Error(`${role.node.path}: the resource ${resource} has a wildcard without a reason in wildcards() of lib/delivery-stack.ts.`)
        }

        Validations.of(role).acknowledge({ id: `AwsSolutions::AwsSolutions-IAM5[Resource::${resource}]`, reason })
      }
    }

    published.add('DeployRoleArn', 'deploy-role-arn', this.deployRole.roleArn, 'Role the deployment workflow of GitHub Actions assumes (the variable DEPLOY_ROLE_ARN of its environment)')
    published.add('FunctionsDeploymentRoleArn', 'functions-deployment-role-arn', this.functionsDeploymentRole.roleArn, 'Role CloudFormation deploys the stack of the functions with')
  }
}
