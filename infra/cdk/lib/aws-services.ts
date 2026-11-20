// The AWS services the adapters of the backend call, by the name of their VPC endpoint. The backend owns the
// list (backend/resources/aws/services.json, which a backend test checks against the code); compose mounts
// that directory read-only at /workspace/aws, and AWS_SERVICES may point elsewhere. The network gives each
// service a path from the private subnets of the functions: a gateway endpoint where AWS offers one, and
// otherwise the NAT gateway or, when the stage asks for them, an interface endpoint.
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/** The services AWS reaches through a gateway endpoint, free, on the route tables of the subnets. */
export const GATEWAY_SERVICES = ['s3', 'dynamodb'] as const

export function awsServicesFile(): string {
  return process.env.AWS_SERVICES ?? resolve(import.meta.dirname, '../../aws/services.json')
}

/** The endpoint names of the services, such as s3 or secretsmanager, with what calls each one. */
export function loadAwsServices(file: string = awsServicesFile()): Readonly<Record<string, string>> {
  const parsed: unknown = JSON.parse(readFileSync(file, 'utf8'))
  const services = typeof parsed === 'object' && parsed !== null && 'services' in parsed ? parsed.services : null

  if (typeof services !== 'object' || services === null || Array.isArray(services) || Object.keys(services).length === 0) {
    throw new Error(`${file} lists no services.`)
  }

  for (const [name, use] of Object.entries(services as Record<string, unknown>)) {
    if (!/^[a-z0-9-]+$/.test(name) || typeof use !== 'string' || use === '') {
      throw new Error(`${file}: ${name} must be the endpoint name of a service, with what calls it.`)
    }
  }

  return services as Record<string, string>
}
