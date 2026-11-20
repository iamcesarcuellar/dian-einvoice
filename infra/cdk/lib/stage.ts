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
    // Easy DKIM and a MAIL FROM subdomain, whose DNS records belong to Infrastructure task 4.
    readonly identity: 'email' | 'domain'
    readonly domain: string
  }
}

const QUEUES = {
  retention: Duration.days(4),
  maxReceiveCount: 10,
  deadLetterRetention: Duration.days(14),
}

/** The functions of the serverless service (infra/functions/serverless.yml), each with a role of its own. */
export const FUNCTIONS = ['api', 'chain', 'webhooks', 'relay', 'scheduler', 'console'] as const

export type FunctionName = (typeof FUNCTIONS)[number]

/** The only function that uses the migrations identity of the database; every other one uses the application identity. */
export const MIGRATIONS_FUNCTION: FunctionName = 'console'

/** The name of a function, dian-einvoice-<stage>-<function>, as the serverless service names it. */
export function functionName(stage: StageConfig, fn: FunctionName): string {
  return resourceName(stage, fn)
}

/**
 * The SSM parameters of the network and of the database, under /dian-einvoice/<stage>/, which the stage prod
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
    functionImages: { tagMutability: 'MUTABLE', keep: 5 },
    deployments: { expireAfter: Duration.days(7) },
    documents: {
      infrequentAccessAfter: Duration.days(90),
      glacierInstantRetrievalAfter: Duration.days(365),
      noncurrentVersionsExpireAfter: Duration.days(30),
      accessLogsExpireAfter: Duration.days(30),
    },
    mail: { fromAddress: 'documents@dian-einvoice.example', identity: 'email', domain: 'dian-einvoice.example' },
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
    functionImages: { tagMutability: 'IMMUTABLE', keep: 20 },
    deployments: { expireAfter: Duration.days(90) },
    documents: {
      infrequentAccessAfter: Duration.days(90),
      glacierInstantRetrievalAfter: Duration.days(365),
      noncurrentVersionsExpireAfter: Duration.days(90),
      accessLogsExpireAfter: Duration.days(365),
    },
    mail: { fromAddress: 'documents@dian-einvoice.example', identity: 'domain', domain: 'dian-einvoice.example' },
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
