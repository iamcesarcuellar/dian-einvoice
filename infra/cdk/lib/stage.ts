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
    // Longer than the 35 s a job may run, so a message is never handled twice at once.
    readonly visibilityTimeout: Duration
    readonly retention: Duration
    // Receives before SQS moves a message to the dead-letter queue: the attempts of a job.
    readonly maxReceiveCount: number
    readonly deadLetterRetention: Duration
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
    // Easy DKIM and a MAIL FROM subdomain, whose DNS records belong to Infrastructure task 2.
    readonly identity: 'email' | 'domain'
    readonly domain: string
  }
  // Settings of the application secret besides the generated APP_KEY. The stage local keeps the fixed
  // credentials of the local MySQL of compose.yaml (docs/limitations.md); in prod the database brings a
  // secret of its own (Infrastructure task 2).
  readonly secretSettings: Readonly<Record<string, string>>
}

const QUEUES = {
  visibilityTimeout: Duration.seconds(45),
  retention: Duration.days(4),
  maxReceiveCount: 10,
  deadLetterRetention: Duration.days(14),
}

export const STAGES: Readonly<Record<StageName, StageConfig>> = {
  local: {
    name: 'local',
    // The default account and region of the emulator.
    account: '000000000000',
    region: 'us-east-1',
    retainData: false,
    terminationProtection: false,
    queues: QUEUES,
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
    queues: QUEUES,
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
