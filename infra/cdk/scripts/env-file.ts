// Prints backend/.env.cloud for make cloud-up: the settings of the AWS adapters of the backend, from the
// outputs of the stage local just deployed to the local emulator (cdk deploy --outputs-file). Compose gives
// them to the processes of the emulated cloud, over the shared settings of backend/.env.
//
//   node scripts/env-file.ts cdk.out/outputs-local.json
import { readFileSync } from 'node:fs'

const file = process.argv[2] ?? 'cdk.out/outputs-local.json'
const outputs = JSON.parse(readFileSync(file, 'utf8')) as Record<string, Record<string, string>>

function output(stack: string, name: string): string {
  const value = outputs[`dian-einvoice-local-${stack}`]?.[name]

  if (value === undefined || value === '') {
    throw new Error(`${file} has no output ${name} of the stack dian-einvoice-local-${stack}: deploy the stage local first.`)
  }

  return value
}

const settings: [string, string][] = [
  // APP_KEY and the database credentials come from Secrets Manager only (App\Support\Secrets\LoadSecrets):
  // empty here, so the values of backend/.env never reach the emulated cloud.
  ['APP_KEY', ''],
  ['DB_USERNAME', ''],
  ['DB_PASSWORD', ''],
  ['APP_SECRETS', 'secrets-manager'],
  ['APP_SECRETS_ID', output('security', 'AppSecretArn')],
  // A database of its own in the MySQL of the stack, so the two environments never share data.
  ['DB_DATABASE', 'dian_einvoice_cloud'],

  // The local emulator, which takes any credentials.
  ['AWS_DEFAULT_REGION', 'us-east-1'],
  ['AWS_ENDPOINT_URL', 'http://floci:4566'],
  ['AWS_USE_PATH_STYLE_ENDPOINT', 'true'],
  ['AWS_ACCESS_KEY_ID', '000000000000'],
  ['AWS_SECRET_ACCESS_KEY', 'emulated'],

  ['DOCUMENT_STORAGE', 's3'],
  ['DOCUMENTS_BUCKET', output('storage', 'DocumentsBucketName')],
  ['KEY_STORE', 'kms'],
  ['SIGNING_KEY_ID', output('security', 'SigningKeyAlias')],
  ['OUTBOX_PUBLISHER', 'sns'],
  ['EVENTS_TOPIC_ARN', output('messaging', 'EventsTopicArn')],
  ['QUEUE_CONNECTION', 'sqs'],
  ['SQS_CHAIN_QUEUE_URL', output('messaging', 'ChainQueueUrl')],
  ['SQS_WEBHOOKS_QUEUE_URL', output('messaging', 'WebhooksQueueUrl')],
  ['SQS_CHAIN_DLQ_URL', output('messaging', 'ChainDeadLetterQueueUrl')],
  ['SQS_WEBHOOKS_DLQ_URL', output('messaging', 'WebhooksDeadLetterQueueUrl')],
  ['CACHE_STORE', 'dynamodb'],
  ['DYNAMODB_CACHE_TABLE', output('data', 'CacheTableName')],
  ['SESSION_DRIVER', 'dynamodb'],
  ['SESSION_STORE', 'dynamodb-sessions'],
  ['DYNAMODB_SESSIONS_TABLE', output('data', 'SessionsTableName')],
  ['CACHE_LIMITER', 'dynamodb-limits'],
  ['DYNAMODB_LIMITS_TABLE', output('data', 'LimitsTableName')],
  ['API_KEY_LIMITS', 'cache'],
  ['MAIL_MAILER', 'ses'],
  ['MAIL_FROM_ADDRESS', output('mail', 'MailFromAddress')],
  ['HEALTH_CHECKS', 'database,cache,queue'],
]

process.stdout.write([
  '# The emulated cloud (make cloud-up): the settings of the AWS adapters of the backend, from the outputs of the',
  '# CDK stage local deployed to the local emulator. Written by make cloud-up; never committed.',
  ...settings.map(([name, value]) => `${name}=${value}`),
  '',
].join('\n'))
