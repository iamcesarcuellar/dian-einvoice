// The two stages of the CDK app and what differs between them. local is deployed to the local AWS
// emulator (compose service floci, profile cloud) with the normal CDK commands; prod is synthesized and
// validated, never deployed: its account is a placeholder, so the CDK CLI refuses to deploy it with the
// credentials of any real account.
import { Duration, RemovalPolicy } from 'aws-cdk-lib'

export const PROJECT = 'dian-einvoice'

export type StageName = 'local' | 'prod'

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
  // Where the functions run: the stage local on the network of compose; prod inside the private subnets
  // of the network of Infrastructure task 3, which publishes them (FUTURE_PARAMETERS).
  readonly network: 'compose' | 'private'
  // The database of the stage: the MySQL of compose, or the managed database of Infrastructure task 3, whose
  // secret the functions read at cold start (DB_SECRETS_ID).
  readonly database: 'compose' | 'managed'
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
    // Easy DKIM and a MAIL FROM subdomain, whose DNS records belong to Infrastructure task 3.
    readonly identity: 'email' | 'domain'
    readonly domain: string
  }
  // Settings of the application secret besides the generated APP_KEY. The stage local keeps the fixed
  // credentials of the local MySQL of compose.yaml (docs/limitations.md); in prod the managed database
  // brings a secret of its own (Infrastructure task 3, FUTURE_PARAMETERS.databaseSecretArn).
  readonly secretSettings: Readonly<Record<string, string>>
}

const QUEUES = {
  retention: Duration.days(4),
  maxReceiveCount: 10,
  deadLetterRetention: Duration.days(14),
}

/** The functions of the serverless service (infra/functions/serverless.yml), each with a role of its own. */
export const FUNCTIONS = ['api', 'chain', 'webhooks', 'relay', 'scheduler', 'console'] as const

export type FunctionName = (typeof FUNCTIONS)[number]

/** The name of a function, dian-einvoice-<stage>-<function>, as the serverless service names it. */
export function functionName(stage: StageConfig, fn: FunctionName): string {
  return resourceName(stage, fn)
}

/**
 * Parameters Infrastructure task 3 publishes for prod, which the stage prod of the functions already reads:
 * the private subnets and the security group of the functions, and the managed database.
 */
export const FUTURE_PARAMETERS = {
  privateSubnetIds: 'network/private-subnet-ids',
  functionsSecurityGroupId: 'network/functions-security-group-id',
  databaseHost: 'database/host',
  databasePort: 'database/port',
  databaseName: 'database/name',
  databaseSecretArn: 'database/secret-arn',
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
    network: 'compose',
    database: 'compose',
    functionImages: { tagMutability: 'MUTABLE', keep: 5 },
    deployments: { expireAfter: Duration.days(7) },
    documents: {
      infrequentAccessAfter: Duration.days(90),
      glacierInstantRetrievalAfter: Duration.days(365),
      noncurrentVersionsExpireAfter: Duration.days(30),
      accessLogsExpireAfter: Duration.days(30),
    },
    mail: { fromAddress: 'documents@dian-einvoice.example', identity: 'email', domain: 'dian-einvoice.example' },
    secretSettings: { DB_USERNAME: 'dian', DB_PASSWORD: 'dian' },
  },
  prod: {
    name: 'prod',
    // Placeholder: this stage is never deployed (docs/infrastructure.md).
    account: '111111111111',
    region: 'us-east-1',
    retainData: true,
    terminationProtection: true,
    queues: { ...QUEUES, visibilityTimeout: Duration.seconds(360) },
    network: 'private',
    database: 'managed',
    functionImages: { tagMutability: 'IMMUTABLE', keep: 20 },
    deployments: { expireAfter: Duration.days(90) },
    documents: {
      infrequentAccessAfter: Duration.days(90),
      glacierInstantRetrievalAfter: Duration.days(365),
      noncurrentVersionsExpireAfter: Duration.days(90),
      accessLogsExpireAfter: Duration.days(365),
    },
    mail: { fromAddress: 'documents@dian-einvoice.example', identity: 'domain', domain: 'dian-einvoice.example' },
    secretSettings: {},
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
