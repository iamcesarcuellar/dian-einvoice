import type { MaybeRefOrGetter } from 'vue'
import { useQuery } from '@tanstack/vue-query'
import { computed, toValue } from 'vue'
import { webhookReceiverApi } from '@/api/developers'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/** The local webhook receiver as the current company sees it, refreshed every 5 seconds. */
export function useWebhookReceiver(enabled: MaybeRefOrGetter<boolean> = true) {
  const session = useSessionStore()

  return useQuery({
    queryKey: computed(() => tenantKey(session.currentCompanyId, 'webhook-receiver')),
    queryFn: () => webhookReceiverApi.get(20),
    enabled: computed(() => toValue(enabled)),
    refetchInterval: 5000,
  })
}
