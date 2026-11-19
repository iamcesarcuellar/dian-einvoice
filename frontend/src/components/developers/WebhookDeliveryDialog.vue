<script setup lang="ts">
import { RefreshCwIcon, SendIcon } from '@lucide/vue'
import { useMutation, useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { webhooksApi } from '@/api/developers'
import FormError from '@/components/FormError.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { formatDateTime } from '@/lib/format'
import { notify } from '@/lib/notify'
import { invalidateTenant, tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'
import CodeBlock from './CodeBlock.vue'

/** One delivery: the request sent (URL, headers and body) and every attempt with its answer; Redeliver. */
const props = defineProps<{
  deliveryId: string | null
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
const session = useSessionStore()

const queryKey = computed(() => tenantKey(session.currentCompanyId, 'webhook-deliveries', props.deliveryId))

const { data: delivery, error, refetch, isFetching } = useQuery({
  queryKey,
  queryFn: () => webhooksApi.delivery(props.deliveryId ?? ''),
  enabled: computed(() => open.value && props.deliveryId !== null),
  refetchInterval: (query) => (query.state.data?.status === 'pending' ? 2000 : false),
})

const body = computed(() => {
  const raw = delivery.value?.request?.body ?? ''

  try {
    return JSON.stringify(JSON.parse(raw), null, 2)
  } catch {
    return raw
  }
})

const headers = computed(() => Object.entries(delivery.value?.request?.headers ?? {}).map(([name, value]) => `${name}: ${value}`).join('\n'))

const redeliver = useMutation({
  mutationFn: () => webhooksApi.redeliver(props.deliveryId ?? ''),
  onSuccess: async () => {
    await invalidateTenant('webhook-deliveries', 'webhooks')
    await refetch()
    notify.success(t('developers.deliveries.redelivered'))
  },
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-3xl">
      <DialogHeader>
        <DialogTitle class="flex flex-wrap items-center gap-2">
          <span class="font-mono">{{ delivery?.event_type ?? t('developers.deliveries.detailTitle') }}</span>
          <StatusBadge v-if="delivery" domain="webhookDelivery" :status="delivery.status" />
        </DialogTitle>
        <DialogDescription>
          <span v-if="delivery" class="font-mono">{{ delivery.event_id }}</span>
          <span v-else>{{ t('common.loading') }}</span>
        </DialogDescription>
      </DialogHeader>

      <div class="grid max-h-[65vh] gap-5 overflow-y-auto pr-1">
        <FormError :error="error ?? redeliver.error.value" />

        <template v-if="delivery">
          <div class="grid gap-1 text-sm">
            <p><span class="text-muted-foreground">{{ t('developers.deliveries.url') }}:</span> <span class="font-mono break-all">{{ delivery.request?.url }}</span></p>
            <p v-if="delivery.next_attempt_at"><span class="text-muted-foreground">{{ t('developers.deliveries.nextAttempt') }}:</span> {{ formatDateTime(delivery.next_attempt_at) }}</p>
            <p v-if="delivery.last_error"><span class="text-muted-foreground">{{ t('developers.deliveries.lastError') }}:</span> {{ delivery.last_error }}</p>
          </div>

          <CodeBlock :label="t('developers.deliveries.headers')" :code="headers" no-copy />
          <CodeBlock :label="t('developers.deliveries.body')" :code="body" />

          <div class="grid gap-3">
            <h3 class="text-sm font-medium">{{ t('developers.deliveries.attempts', { count: delivery.attempt_log?.length ?? 0 }) }}</h3>
            <p v-if="(delivery.attempt_log ?? []).length === 0" class="text-sm text-muted-foreground">{{ t('developers.deliveries.noAttempts') }}</p>
            <div v-for="attempt in delivery.attempt_log ?? []" :key="attempt.id" class="grid gap-2 rounded-md border p-3">
              <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                <span class="font-medium">{{ t('developers.deliveries.attempt', { number: attempt.attempt }) }}</span>
                <span v-if="attempt.status_code" class="font-mono">HTTP {{ attempt.status_code }}</span>
                <span v-if="attempt.outcome" class="text-muted-foreground">{{ t(`developers.deliveries.outcomes.${attempt.outcome}`) }}</span>
                <span v-else class="text-muted-foreground">{{ t('developers.deliveries.running') }}</span>
                <span v-if="attempt.duration_ms !== null" class="text-muted-foreground tabular-nums">{{ t('developers.deliveries.duration', { ms: attempt.duration_ms }) }}</span>
                <span class="ml-auto text-xs text-muted-foreground tabular-nums">{{ formatDateTime(attempt.started_at) }}</span>
              </div>
              <p v-if="attempt.error" class="text-xs text-muted-foreground">{{ attempt.error }}</p>
              <CodeBlock v-if="attempt.response_snippet" :label="t('developers.deliveries.response')" :code="attempt.response_snippet" no-copy />
            </div>
          </div>
        </template>
      </div>

      <DialogFooter>
        <Button type="button" variant="outline" :disabled="isFetching" @click="refetch()">
          <RefreshCwIcon :class="isFetching ? 'animate-spin' : ''" />
          {{ t('developers.refresh') }}
        </Button>
        <Button type="button" :disabled="!delivery || delivery.status === 'pending' || redeliver.isPending.value" @click="redeliver.mutate()">
          <SendIcon />
          {{ redeliver.isPending.value ? t('developers.deliveries.redelivering') : t('developers.deliveries.redeliver') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
