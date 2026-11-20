// The origin verifier: the Lambda authorizer of the HTTP API of API Gateway (a request authorizer, payload 2.0, with
// simple responses), so the API answers only what came through the edge. The distribution adds the secret of the
// edge to every request it sends to API Gateway in X-Origin-Verify; API Gateway answers 401 itself when the header
// is missing, calls this function otherwise, and keeps its answer for each value of the header for the time of the
// authorizer cache (infra/functions/serverless.yml). It holds no state of the application, runs outside the VPC
// with a role that may only read that secret, and is packaged apart from the image of PHP. The AWS SDK for
// JavaScript is that of the Node.js runtime of Lambda.
import { GetSecretValueCommand, SecretsManagerClient } from '@aws-sdk/client-secrets-manager'
import { OriginVerifier, type SecretValues } from './verify.mts'

/** The header the edge sends; API Gateway hands header names in lower case. */
export const HEADER = 'x-origin-verify'

/** The request of a request authorizer, payload 2.0, as much of it as the verifier reads. */
export interface AuthorizerEvent {
  readonly type?: string
  readonly headers?: Readonly<Record<string, string | undefined>>
  readonly requestContext?: { readonly requestId?: string, readonly http?: { readonly sourceIp?: string } }
}

export interface AuthorizerResult {
  readonly isAuthorized: boolean
}

const secretId = process.env.ORIGIN_SECRET_ID ?? ''
// The emulated cloud names the endpoint of its emulator; in AWS the variable is empty and the SDK finds the service.
const endpoint = process.env.AWS_ENDPOINT_URL ?? ''
const client = new SecretsManagerClient(endpoint === '' ? {} : { endpoint })

async function stage(versionStage: 'AWSCURRENT' | 'AWSPREVIOUS'): Promise<string | null> {
  try {
    const answer = await client.send(new GetSecretValueCommand({ SecretId: secretId, VersionStage: versionStage }))

    return answer.SecretString ?? null
  } catch (error) {
    // Before the first rotation the secret has no previous version.
    if (versionStage === 'AWSPREVIOUS' && error instanceof Error && error.name === 'ResourceNotFoundException') {
      return null
    }

    throw error
  }
}

async function readSecret(): Promise<SecretValues> {
  const [current, previous] = await Promise.all([stage('AWSCURRENT'), stage('AWSPREVIOUS')])

  return { current: current ?? '', previous }
}

// Thirty seconds in memory, read again at most every five seconds when a request brings a value not held.
const verifier = new OriginVerifier(readSecret, { keepMilliseconds: 30_000, refreshMilliseconds: 5_000 })

export async function handler(event: AuthorizerEvent): Promise<AuthorizerResult> {
  if (secretId === '') {
    throw new Error('ORIGIN_SECRET_ID is not set.')
  }

  const isAuthorized = await verifier.verify(event.headers?.[HEADER])

  if (!isAuthorized) {
    // Never the value received: only that it was refused, and from where API Gateway saw the request.
    console.warn(JSON.stringify({ message: 'A request did not come through the edge.', requestId: event.requestContext?.requestId, sourceIp: event.requestContext?.http?.sourceIp }))
  }

  return { isAuthorized }
}
