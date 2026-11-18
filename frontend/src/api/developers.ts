import { request } from './client'
import type {
  ApiKey,
  ApiKeyInput,
  CreatedApiKey,
  DataEnvelope,
  ListParams,
  Paginated,
  ReceiverMode,
  ReceiverModeInput,
  ReceiverStatus,
  WebhookDelivery,
  WebhookEventType,
  WebhookSubscription,
  WebhookSubscriptionInput,
  WebhookSubscriptionWithSecret,
} from './types'

/** API keys of the current company. Owners and admins, in the web app only. */
export const apiKeysApi = {
  list(params: ListParams = {}): Promise<Paginated<ApiKey>> {
    return request<Paginated<ApiKey>>('GET', '/api-keys', undefined, params)
  },

  /** The answer carries the key itself, once. */
  async create(input: ApiKeyInput): Promise<CreatedApiKey> {
    return (await request<DataEnvelope<CreatedApiKey>>('POST', '/api-keys', input)).data
  },

  async revoke(id: string): Promise<ApiKey> {
    return (await request<DataEnvelope<ApiKey>>('POST', `/api-keys/${id}/revoke`)).data
  },
}

/** Webhook subscriptions of the current company and their deliveries. Owners and admins. */
export const webhooksApi = {
  async eventTypes(): Promise<WebhookEventType[]> {
    return (await request<DataEnvelope<WebhookEventType[]>>('GET', '/webhooks/event-types')).data
  },

  list(params: ListParams = {}): Promise<Paginated<WebhookSubscription>> {
    return request<Paginated<WebhookSubscription>>('GET', '/webhooks', undefined, params)
  },

  /** The answer carries the signing secret, once. */
  async create(input: WebhookSubscriptionInput): Promise<WebhookSubscriptionWithSecret> {
    return (await request<DataEnvelope<WebhookSubscriptionWithSecret>>('POST', '/webhooks', input)).data
  },

  async update(id: string, input: Partial<WebhookSubscriptionInput>): Promise<WebhookSubscription> {
    return (await request<DataEnvelope<WebhookSubscription>>('PATCH', `/webhooks/${id}`, input)).data
  },

  remove(id: string): Promise<void> {
    return request<void>('DELETE', `/webhooks/${id}`)
  },

  /** A new secret replaces the current one at once; the answer carries it, once. */
  async rotateSecret(id: string): Promise<WebhookSubscriptionWithSecret> {
    return (await request<DataEnvelope<WebhookSubscriptionWithSecret>>('POST', `/webhooks/${id}/rotate-secret`)).data
  },

  async sendTest(id: string): Promise<WebhookDelivery> {
    return (await request<DataEnvelope<WebhookDelivery>>('POST', `/webhooks/${id}/test`)).data
  },

  deliveries(id: string, params: ListParams = {}): Promise<Paginated<WebhookDelivery>> {
    return request<Paginated<WebhookDelivery>>('GET', `/webhooks/${id}/deliveries`, undefined, params)
  },

  async delivery(id: string): Promise<WebhookDelivery> {
    return (await request<DataEnvelope<WebhookDelivery>>('GET', `/webhook-deliveries/${id}`)).data
  },

  async redeliver(id: string): Promise<WebhookDelivery> {
    return (await request<DataEnvelope<WebhookDelivery>>('POST', `/webhook-deliveries/${id}/redeliver`)).data
  },
}

/**
 * The local webhook receiver, a development tool inside the simulator service. The API talks to it;
 * the browser never does.
 */
export const webhookReceiverApi = {
  async get(limit = 20): Promise<ReceiverStatus> {
    return (await request<DataEnvelope<ReceiverStatus>>('GET', '/webhook-receiver', undefined, { limit })).data
  },

  async setMode(input: ReceiverModeInput): Promise<ReceiverMode> {
    return (await request<DataEnvelope<{ mode: ReceiverMode }>>('PUT', '/webhook-receiver/mode', input)).data.mode
  },
}
