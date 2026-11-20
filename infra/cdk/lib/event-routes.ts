// The event types each SQS queue receives from the topic of document events. The backend owns them: they
// come from the subscriptions of its modules (php artisan outbox:routes --write writes
// backend/resources/outbox/routes.json, and a backend test fails when the file is out of date). Compose
// mounts that directory read-only at /workspace/outbox; EVENT_ROUTES may point elsewhere.
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

export type Channel = 'chain' | 'webhooks'

export type EventRoutes = Readonly<Record<Channel, readonly string[]>>

const CHANNELS: readonly Channel[] = ['chain', 'webhooks']

export function eventRoutesFile(): string {
  return process.env.EVENT_ROUTES ?? resolve(import.meta.dirname, '../../outbox/routes.json')
}

export function loadEventRoutes(file: string = eventRoutesFile()): EventRoutes {
  const parsed: unknown = JSON.parse(readFileSync(file, 'utf8'))
  const channels = typeof parsed === 'object' && parsed !== null && 'channels' in parsed ? parsed.channels : null

  if (typeof channels !== 'object' || channels === null) {
    throw new Error(`${file} has no channels.`)
  }

  const routes: Partial<Record<Channel, readonly string[]>> = {}

  for (const channel of CHANNELS) {
    const types: unknown = (channels as Record<string, unknown>)[channel]

    if (!Array.isArray(types) || types.length === 0 || !types.every((type) => typeof type === 'string' && /^[a-z_]+\.[a-z_]+$/.test(type))) {
      throw new Error(`${file}: the ${channel} channel must list event types such as invoice.issued.`)
    }

    routes[channel] = types as string[]
  }

  return routes as EventRoutes
}
