// The notifier: the Lambda function subscribed to the topic of the alarms, which turns every change of state of an
// alarm into a short message to the chat webhook whose address is in Secrets Manager (notify.mts). It runs outside the
// VPC, with a role that may only read that secret and send to its own dead-letter queue, and writes its lines in the
// schema of the platform. The AWS SDK for JavaScript is that of the Node.js runtime of Lambda.
import { GetSecretValueCommand, SecretsManagerClient } from '@aws-sdk/client-secrets-manager'
import { SendMessageCommand, SQSClient } from '@aws-sdk/client-sqs'
import { correlationId, logger } from '../shared/log.mts'
import { notify, type ChatMessage, type PostResult, type SnsEvent, type SnsRecord } from './notify.mts'

const secretId = process.env.WEBHOOK_SECRET_ID ?? ''
const deadLetterQueueUrl = process.env.DEAD_LETTER_QUEUE_URL ?? ''
// The emulated cloud names the endpoint of its emulator; in AWS the variable is empty and the SDK finds the service.
const endpoint = process.env.AWS_ENDPOINT_URL ?? ''
const options = endpoint === '' ? {} : { endpoint }
const secrets = new SecretsManagerClient(options)
const sqs = new SQSClient(options)
const log = logger('notifier')

/** The address is read once per five minutes: a new one set by the operators is used soon, without a read per alarm. */
const KEEP_MILLISECONDS = 300_000
const POST_TIMEOUT_MILLISECONDS = 5_000
let cached: { url: string, at: number } | null = null

/** The address of the webhook in the secret, {"url": "..."} or the address alone; '' when none is set. */
export function urlOfSecret(value: string | undefined): string {
  const text = (value ?? '').trim()

  if (text.startsWith('{')) {
    const parsed = JSON.parse(text) as { url?: unknown }

    return typeof parsed.url === 'string' ? parsed.url.trim() : ''
  }

  return text
}

async function webhookUrl(): Promise<string> {
  if (cached !== null && Date.now() - cached.at < KEEP_MILLISECONDS) {
    return cached.url
  }

  const answer = await secrets.send(new GetSecretValueCommand({ SecretId: secretId }))
  cached = { url: urlOfSecret(answer.SecretString), at: Date.now() }

  return cached.url
}

async function post(url: string, message: ChatMessage): Promise<PostResult> {
  try {
    const response = await fetch(url, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(message), signal: AbortSignal.timeout(POST_TIMEOUT_MILLISECONDS), redirect: 'error' })
    await response.arrayBuffer()

    return { status: response.status }
  } catch (error) {
    return { error }
  }
}

async function deadLetter(record: SnsRecord, reason: string): Promise<void> {
  await sqs.send(new SendMessageCommand({
    QueueUrl: deadLetterQueueUrl,
    MessageBody: record.Sns.Message,
    MessageAttributes: { reason: { DataType: 'String', StringValue: reason } },
  }))
}

export async function handler(event: SnsEvent, context: { readonly awsRequestId?: string } = {}): Promise<void> {
  if (secretId === '' || deadLetterQueueUrl === '') {
    throw new Error('WEBHOOK_SECRET_ID and DEAD_LETTER_QUEUE_URL must be set.')
  }

  await notify(event, context.awsRequestId ?? null, { webhookUrl, post, deadLetter, log, correlationId: () => correlationId() })
}
