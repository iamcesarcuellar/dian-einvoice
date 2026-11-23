// The two stages of the CDK app and what differs between them. local is deployed to the local AWS
// emulator (compose service floci, profile cloud) with the normal CDK commands; prod is synthesized and
// validated, never deployed: its account is a placeholder, so the CDK CLI refuses to deploy it with the
// credentials of any real account.
import { Duration, RemovalPolicy } from 'aws-cdk-lib'
import { RetentionDays } from 'aws-cdk-lib/aws-logs'

export const PROJECT = 'dian-einvoice'

export type StageName = 'local' | 'prod'

/**
 * The private network of the functions and of the managed database (stack network, prod only; the reasons
 * of each setting are in docs/infrastructure.md).
 */
export interface NetworkSettings {
  readonly cidr: string
  // By name: each account maps the names to zones of its own (the zone ids).
  readonly availabilityZones: readonly string[]
  // 1, in the first zone, or one per zone: what leaves for the internet is asynchronous and retried.
  readonly natGateways: number
  // Interface endpoints for the AWS services of the adapters that have no gateway endpoint; off, they go
  // through the NAT gateway.
  readonly interfaceEndpoints: boolean
  readonly flowLogsRetention: RetentionDays
}

/** The managed database (stack database, prod only): Aurora MySQL Serverless v2. */
export interface ManagedDatabaseSettings {
  // Aurora MySQL 8.4: 8.4.mysql_aurora.<the community release it is compatible with>.
  readonly engineVersion: string
  // Aurora capacity units (about 2 GiB of memory each). The maximum also sets max_connections, which a
  // test holds above the connections of the functions and a margin (test/database.test.ts).
  readonly minCapacity: number
  readonly maxCapacity: number
  // A reader in the second zone, which a failover promotes.
  readonly reader: boolean
  // RDS Proxy in front of the database.
  readonly proxy: boolean
  readonly port: number
  readonly backupRetention: Duration
  // UTC; Colombia is UTC-5.
  readonly backupWindow: string
  readonly maintenanceWindow: string
  // The error, slow query and audit logs exported to CloudWatch Logs.
  readonly logRetention: RetentionDays
  // How often the rotation functions of AWS change the passwords of both identities.
  readonly rotateAfter: Duration
}

/**
 * The database of a stage and its two identities: migrations (may change the schema; only the console
 * function uses it) and application (reads and writes data, nothing of the schema; every other function).
 * Each has a secret in Secrets Manager in both stages.
 */
export interface DatabaseSettings {
  readonly name: string
  readonly migrationsUser: string
  readonly applicationUser: string
  // null: the MySQL of compose.
  readonly managed: ManagedDatabaseSettings | null
}

/** The domain of the platform at the edge: the alternate name of the distribution and its certificate. */
export interface EdgeDomain {
  readonly name: string
  // A certificate of ACM in us-east-1, as CloudFront requires, for that name. No lookup in an account: it is a
  // deployment parameter (cdk deploy -c edgeDomainName=... -c edgeCertificateArn=...).
  readonly certificateArn: string
}

/** The firewall of the edge (AWS WAF): its rate-based rules, per address of a client, in each window. */
export interface FirewallSettings {
  readonly window: Duration
  // Every request of an address: a ceiling against floods, well above what the application allows an API key.
  readonly requestsPerAddress: number
  // The sign-in, sign-up, password reset and invitation points, stricter.
  readonly authenticationRequestsPerAddress: number
}

/**
 * The edge (stack edge): the content delivery network in front of the web app and the API, its firewall and its
 * logs. The reasons of each setting are in docs/infrastructure.md.
 */
export interface EdgeSettings {
  // null: the domain CloudFront gives the distribution, with its default certificate.
  readonly domain: EdgeDomain | null
  // How the value of the secret of the edge reaches the distribution, which sends it to API Gateway: a
  // parameter of the deployment, which make cloud-up reads from the secret (the emulator expands no dynamic
  // reference outside RDS), or a dynamic reference to Secrets Manager that CloudFormation resolves.
  readonly originSecret: 'parameter' | 'dynamic-reference'
  // The access logs of the distribution, and the server access logs of the bucket of the web app, kept this long.
  readonly logsExpireAfter: Duration
  // The versions an upload of the web app replaced stay this long, to go back to them.
  readonly panelVersionsExpireAfter: Duration
  // null: no firewall (the emulator applies none).
  readonly firewall: FirewallSettings | null
}

/**
 * Where the alarms of a stage go (stacks alerts and observability): the notifier posts each change of state to a
 * chat webhook compatible with Slack, and the alarm on the failures of the notifier also emails a fallback address.
 */
export interface AlertsSettings {
  // The first value of the secret of the chat webhook. Empty: the notifier only logs each alarm, until the operators
  // put the address of their webhook in the secret (it is never in a template in prod).
  readonly chatWebhookUrl: string
  // The address subscribed to the topic of the failures of the notifier; empty, none. A deployment parameter in
  // prod (cdk deploy -c alertsEmail=...), never a lookup.
  readonly fallbackEmail: string
}

/**
 * The delivery pipeline of a real account (stack delivery, prod only): who may deploy, from where. The workflow
 * .github/workflows/deploy.yml assumes the deploy role with the OpenID Connect token of GitHub Actions, so no
 * long-lived key exists; the role trusts only the environment of this repository (docs/deployment.md).
 */
export interface DeliverySettings {
  // The repository on GitHub whose workflow deploys: deployment parameters (cdk deploy -c githubOwner=... -c
  // githubRepository=...), never a lookup; without them the placeholders of this file.
  readonly githubOwner: string
  readonly githubRepository: string
  // The environment of GitHub whose jobs may assume the role; its required reviewers approve each deployment.
  readonly environment: string
  // An OpenID Connect provider of token.actions.githubusercontent.com the account already has (an account holds one
  // per URL): -c githubOidcProviderArn=arn:aws:iam::<account>:oidc-provider/token.actions.githubusercontent.com.
  // null: the stack creates it.
  readonly oidcProviderArn: string | null
  // The qualifier of the bootstrap of CDK in the account (cdk bootstrap --qualifier), whose roles cdk deploy assumes.
  readonly cdkQualifier: string
}

/**
 * How the saved queries of Logs Insights reach a stage (stack observability): as resources of the stack, or, where
 * the CloudFormation of the emulator cannot create that type (Floci 2.5.0), in the metadata of its template, from
 * which make cloud-up saves them through the API of Logs (infra/functions/scripts/observability.mjs queries).
 */
export type SavedQueries = 'resources' | 'template-metadata'

export interface StageConfig {
  readonly name: StageName
  readonly account: string
  readonly region: string
  // Data that must outlive a mistaken delete (documents, keys, the secret) is retained in prod.
  readonly retainData: boolean
  readonly terminationProtection: boolean
  readonly queues: {
    // At least the timeout of the consumer function of the queue (60 s, infra/functions/serverless.yml),
    // which Lambda requires of a mapping; in prod six times it, as AWS recommends, so a batch that Lambda
    // retries after a throttle is not received twice. A message a consumer held when it died comes back
    // after this long. A test ties both numbers (test/functions.test.ts).
    readonly visibilityTimeout: Duration
    readonly retention: Duration
    // Receives before SQS moves a message to the dead-letter queue: the attempts of a job.
    readonly maxReceiveCount: number
    readonly deadLetterRetention: Duration
  }
  // Where the functions run: null on the network of compose (local), or the private network of the stack
  // network (prod), which also holds the managed database.
  readonly network: NetworkSettings | null
  readonly database: DatabaseSettings
  readonly functionImages: {
    // Tags of the image of the functions in ECR. Locally every make cloud-up pushes the current image
    // under the tag of its id, again when it did not change; prod tags can never be moved.
    readonly tagMutability: 'MUTABLE' | 'IMMUTABLE'
    // Images ECR keeps; the functions name theirs by digest.
    readonly keep: number
    // At rest: 'kms', with the AWS managed key of ECR (every pull and push in CloudTrail, no key policy to keep), or
    // 'default', the AES-256 of ECR the repository of the local stage was created with (a repository cannot change
    // its encryption once created).
    readonly encryption: 'default' | 'kms'
  }
  readonly deployments: {
    // Artifacts of the deployments of the serverless service (templates and state) kept this long.
    readonly expireAfter: Duration
  }
  readonly documents: {
    readonly infrequentAccessAfter: Duration
    readonly glacierInstantRetrievalAfter: Duration
    readonly noncurrentVersionsExpireAfter: Duration
    readonly accessLogsExpireAfter: Duration
  }
  readonly mail: {
    readonly fromAddress: string
    // An email identity is verified at once by the emulator; in AWS the whole domain is verified with
    // Easy DKIM and a MAIL FROM subdomain, whose DNS records the owner of the domain publishes (docs/limitations.md).
    readonly identity: 'email' | 'domain'
    readonly domain: string
  }
  readonly edge: EdgeSettings
  readonly alerts: AlertsSettings
  readonly observability: { readonly savedQueries: SavedQueries }
  // null: no delivery pipeline (the emulator deploys with make cloud-up).
  readonly delivery: DeliverySettings | null
}

const QUEUES = {
  retention: Duration.days(4),
  maxReceiveCount: 10,
  deadLetterRetention: Duration.days(14),
}

/** The functions of the serverless service (infra/functions/serverless.yml), each with a role of its own. */
export const FUNCTIONS = ['api', 'chain', 'webhooks', 'relay', 'scheduler', 'console'] as const

export type FunctionName = (typeof FUNCTIONS)[number]

/**
 * The origin verifier of the edge (infra/functions/origin-verifier), the Lambda authorizer of the HTTP API: a
 * function of its own in TypeScript on Node.js, apart from the functions of the application, without access to the
 * database or to any resource but the secret of the edge.
 */
export const ORIGIN_VERIFIER = 'origin-verifier'

/**
 * The notifier (infra/functions/notifier): a function in TypeScript on Node.js, subscribed to the topic of the alarms,
 * that turns each change of state of an alarm into a short message to a chat webhook; outside the VPC, with a role
 * that may only read the secret of that webhook and send to its own dead-letter queue.
 */
export const NOTIFIER = 'notifier'

/**
 * The company id of the local webhook receiver of the DIAN simulator under which the local stage takes the messages
 * of the notifier, as a chat would (make cloud-check reads them there).
 */
export const ALARM_RECEIVER = '0alarmnotifications0000000'

/** The SSM parameter the serverless service publishes with the id of its HTTP API, the origin of the API behind the edge. */
export const HTTP_API_PARAMETER = 'functions/http-api-id'

/** The secret of the edge, whose value the distribution sends to API Gateway in X-Origin-Verify. */
export function originSecretName(stage: StageConfig): string {
  return `${PROJECT}/${stage.name}/edge/origin-verify`
}

/** The only function that uses the migrations identity of the database; every other one uses the application identity. */
export const MIGRATIONS_FUNCTION: FunctionName = 'console'

/** The name of a function, dian-einvoice-<stage>-<function>, as the serverless service names it. */
export function functionName(stage: StageConfig, fn: FunctionName): string {
  return resourceName(stage, fn)
}

/**
 * The SSM parameters of the network and of the database, under /dian-einvoice/<stage>/, which the prod stage
 * of the functions reads (infra/functions/stages/prod.yml and resources/parameters.yml). The secrets of the
 * two identities exist in both stages; the rest only where there is a network and a managed database.
 */
export const NETWORK_PARAMETERS = {
  privateSubnetIds: 'network/private-subnet-ids',
  functionsSecurityGroupId: 'network/functions-security-group-id',
} as const

export const DATABASE_PARAMETERS = {
  host: 'database/host',
  port: 'database/port',
  name: 'database/name',
  // The application identity, which every function but the console reads at cold start.
  secretArn: 'database/secret-arn',
  // The migrations identity, for the console function only.
  migrationsSecretArn: 'database/migrations-secret-arn',
} as const

export const STAGES: Readonly<Record<StageName, StageConfig>> = {
  local: {
    name: 'local',
    // The default account and region of the emulator.
    account: '000000000000',
    region: 'us-east-1',
    retainData: false,
    terminationProtection: false,
    queues: { ...QUEUES, visibilityTimeout: Duration.seconds(60) },
    network: null,
    // The MySQL of compose, a database of its own and two users of its own (make cloud-up creates them),
    // apart from the local stack, which keeps its single user.
    database: { name: 'dian_einvoice_cloud', migrationsUser: 'dian_cloud_migrations', applicationUser: 'dian_cloud_app', managed: null },
    functionImages: { tagMutability: 'MUTABLE', keep: 5, encryption: 'default' },
    deployments: { expireAfter: Duration.days(7) },
    documents: {
      infrequentAccessAfter: Duration.days(90),
      glacierInstantRetrievalAfter: Duration.days(365),
      noncurrentVersionsExpireAfter: Duration.days(30),
      accessLogsExpireAfter: Duration.days(30),
    },
    mail: { fromAddress: 'documents@dian-einvoice.example', identity: 'email', domain: 'dian-einvoice.example' },
    edge: {
      domain: null,
      originSecret: 'parameter',
      logsExpireAfter: Duration.days(30),
      panelVersionsExpireAfter: Duration.days(7),
      firewall: null,
    },
    // The local webhook receiver of the DIAN simulator, on the network of compose, takes the messages as a chat would.
    alerts: { chatWebhookUrl: `http://simulator:8602/cloud/webhook-receiver/${ALARM_RECEIVER}`, fallbackEmail: 'operators@dian-einvoice.example' },
    observability: { savedQueries: 'template-metadata' },
    delivery: null,
  },
  prod: {
    name: 'prod',
    // Placeholder: this stage is never deployed (docs/infrastructure.md).
    account: '111111111111',
    region: 'us-east-1',
    retainData: true,
    terminationProtection: true,
    queues: { ...QUEUES, visibilityTimeout: Duration.seconds(360) },
    network: {
      cidr: '10.40.0.0/16',
      availabilityZones: ['us-east-1a', 'us-east-1b'],
      natGateways: 1,
      interfaceEndpoints: false,
      flowLogsRetention: RetentionDays.ONE_YEAR,
    },
    database: {
      name: 'dian_einvoice',
      migrationsUser: 'dian_migrations',
      applicationUser: 'dian_app',
      managed: {
        // The newest release the offline validators know (the CloudFormation schema of CDK and cfn-lint); 8.4.10
        // came out on 2026-10-26 and is a change of this line once they do.
        engineVersion: '8.4.mysql_aurora.8.4.8',
        minCapacity: 0.5,
        maxCapacity: 4,
        reader: true,
        proxy: false,
        port: 3306,
        backupRetention: Duration.days(35),
        // 01:00 to 02:00 and Sundays 02:30 to 03:30 in Colombia, when nobody issues invoices.
        backupWindow: '06:00-07:00',
        maintenanceWindow: 'sun:07:30-sun:08:30',
        logRetention: RetentionDays.ONE_YEAR,
        rotateAfter: Duration.days(30),
      },
    },
    functionImages: { tagMutability: 'IMMUTABLE', keep: 20, encryption: 'kms' },
    deployments: { expireAfter: Duration.days(90) },
    documents: {
      infrequentAccessAfter: Duration.days(90),
      glacierInstantRetrievalAfter: Duration.days(365),
      noncurrentVersionsExpireAfter: Duration.days(90),
      accessLogsExpireAfter: Duration.days(365),
    },
    mail: { fromAddress: 'documents@dian-einvoice.example', identity: 'domain', domain: 'dian-einvoice.example' },
    edge: {
      // Placeholders of a domain and a certificate, replaced by the deployment parameters of a real account.
      domain: { name: 'app.dian-einvoice.example', certificateArn: 'arn:aws:acm:us-east-1:111111111111:certificate/00000000-0000-0000-0000-000000000000' },
      originSecret: 'dynamic-reference',
      logsExpireAfter: Duration.days(365),
      panelVersionsExpireAfter: Duration.days(90),
      firewall: { window: Duration.minutes(5), requestsPerAddress: 3000, authenticationRequestsPerAddress: 100 },
    },
    // The address of the chat webhook is put in its secret by the operators; the email is a placeholder of a
    // deployment parameter.
    alerts: { chatWebhookUrl: '', fallbackEmail: 'operators@dian-einvoice.example' },
    observability: { savedQueries: 'resources' },
    // Placeholders of the repository, replaced by the deployment parameters of a real account.
    delivery: { githubOwner: 'example-owner', githubRepository: 'dian-einvoice', environment: 'production', oidcProviderArn: null, cdkQualifier: 'hnb659fds' },
  },
}

/** dian-einvoice-<stage>-<what>: every resource name carries the stage. */
export function resourceName(stage: StageConfig, what: string): string {
  return `${PROJECT}-${stage.name}-${what}`
}

/** Bucket names are global: they also carry the account and the region. */
export function bucketName(stage: StageConfig, what: string): string {
  return `${resourceName(stage, what)}-${stage.account}-${stage.region}`
}

/** Prefix of the SSM parameters that publish the resources of a stage, such as /dian-einvoice/local. */
export function parameterPrefix(stage: StageConfig): string {
  return `/${PROJECT}/${stage.name}`
}

export function removalPolicy(stage: StageConfig): RemovalPolicy {
  return stage.retainData ? RemovalPolicy.RETAIN : RemovalPolicy.DESTROY
}
