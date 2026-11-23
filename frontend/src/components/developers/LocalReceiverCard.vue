<script setup lang="ts">
import type { ReceiverModeInput, ReceiverModeName } from '@/api/types'
import type { Option } from '@/lib/options'
import { FlaskConicalIcon, InfoIcon, RefreshCwIcon } from '@lucide/vue'
import { useQueryClient } from '@tanstack/vue-query'
import type { DataTableColumn } from '@/components/data-table/types'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { webhookReceiverApi } from '@/api/developers'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import FormSection from '@/components/FormSection.vue'
import SelectInput from '@/components/SelectInput.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import DataTable from '@/components/data-table/DataTable.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormMutation } from '@/composables/useFormMutation'
import { useWebhookReceiver } from '@/composables/useWebhookReceiver'
import { formatDateTime, formatInteger } from '@/lib/format'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'
import CopyButton from './CopyButton.vue'

/**
 * The local webhook receiver, a development tool inside the simulator service: the URL a
 * subscription uses, the mode for the next deliveries (normal, error 500 or slow), and the last
 * webhooks it got with the result of the signature check. The API talks to it; the browser never does.
 */
const { t } = useI18n()
const queryClient = useQueryClient()
const session = useSessionStore()

const { data: receiver, error: receiverError, refetch, isFetching } = useWebhookReceiver()

interface ReceiverForm {
  mode: string | null
  count: string
  delay_ms: string
}

const form = ref<ReceiverForm>({ mode: 'normal', count: '', delay_ms: '7000' })

const modeOptions = computed<Option[]>(() =>
  (receiver.value?.modes ?? ['normal', 'error_500', 'slow']).map((mode) => ({ value: mode, label: t(`developers.receiver.modes.${mode}`) })),
)

const currentLabel = computed(() => {
  const mode = receiver.value?.mode

  if (!mode) {
    return ''
  }

  const parts = [t(`developers.receiver.modes.${mode.mode}`)]

  if (mode.mode === 'slow' && mode.delay_ms !== null) {
    parts.push(t('developers.receiver.after', { ms: mode.delay_ms }))
  }

  if (mode.mode !== 'normal') {
    parts.push(mode.remaining === null ? t('developers.receiver.untilChanged') : t('developers.receiver.remaining', { count: mode.remaining }, mode.remaining))
  }

  return parts.join(' · ')
})

function numberOrNull(value: string): number | null {
  const trimmed = value.trim()

  return trimmed === '' ? null : Number(trimmed)
}

function toInput(): ReceiverModeInput {
  const mode = (form.value.mode ?? 'normal') as ReceiverModeName

  return {
    mode,
    count: mode === 'normal' ? null : numberOrNull(form.value.count),
    delay_ms: mode === 'slow' ? numberOrNull(form.value.delay_ms) : null,
  }
}

const { submit, isPending, error, errors } = useFormMutation({
  mutationFn: () => webhookReceiverApi.setMode(toInput()),
  success: t('developers.receiver.applied'),
  onSuccess: async () => {
    await queryClient.invalidateQueries({ queryKey: tenantKey(session.currentCompanyId, 'webhook-receiver') })
  },
})

const receivedColumns = computed<DataTableColumn[]>(() => [
  { key: 'received_at', label: t('developers.receiver.columns.time') },
  { key: 'event_type', label: t('developers.receiver.columns.event') },
  { key: 'verification', label: t('developers.receiver.columns.signature') },
  { key: 'response_status', label: t('developers.receiver.columns.answer') },
  { key: 'webhook_id', label: t('developers.receiver.columns.webhookId') },
])

const receivedRows = computed(() => (receiver.value?.received ?? []).map((hook, index) => ({ ...hook, id: `${hook.received_at}-${index}` })))

// The form follows the mode of the receiver, unless a change is being applied.
watch(
  () => receiver.value?.mode,
  (mode) => {
    if (mode && !isPending.value) {
      form.value = { mode: mode.mode, count: '', delay_ms: String(mode.delay_ms ?? 7000) }
    }
  },
  { immediate: true },
)
</script>

<template>
  <FormSection :title="t('developers.receiver.title')" :description="t('developers.receiver.description')">
    <div class="grid gap-6">
      <FormError :error="receiverError" />

      <template v-if="receiver">
        <div class="grid gap-1.5">
          <p class="text-xs font-medium text-muted-foreground">{{ t('developers.receiver.url') }}</p>
          <div class="flex flex-wrap items-center gap-2">
            <code class="min-w-0 flex-1 rounded-md border bg-muted/40 px-3 py-2 font-mono text-sm break-all">{{ receiver.url }}</code>
            <CopyButton :value="receiver.url" />
          </div>
          <p class="text-xs text-muted-foreground">{{ t('developers.receiver.urlHint') }}</p>
        </div>

        <form class="grid gap-4" novalidate @submit.prevent="submit">
          <div class="flex flex-wrap items-center gap-2">
            <Badge variant="secondary">
              <FlaskConicalIcon />
              {{ t('developers.receiver.current') }}
            </Badge>
            <span class="text-sm">{{ currentLabel }}</span>
          </div>

          <FormError :error="error" />

          <div class="grid gap-4 sm:grid-cols-3">
            <FormField
              id="receiver-mode"
              :label="t('developers.receiver.mode')"
              :hint="form.mode ? t(`developers.receiver.modeHints.${form.mode}`) : undefined"
              :error="errors.mode?.[0]"
            >
              <SelectInput id="receiver-mode" v-model="form.mode" :options="modeOptions" :disabled="isPending" :invalid="!!errors.mode" />
            </FormField>

            <FormField
              v-if="form.mode && form.mode !== 'normal'"
              id="receiver-count"
              :label="t('developers.receiver.count')"
              :hint="t('developers.receiver.countHint')"
              :error="errors.count?.[0]"
              optional
            >
              <Input id="receiver-count" v-model="form.count" inputmode="numeric" :disabled="isPending" :aria-invalid="!!errors.count" />
            </FormField>

            <FormField
              v-if="form.mode === 'slow'"
              id="receiver-delay"
              :label="t('developers.receiver.delay')"
              :hint="t('developers.receiver.delayHint')"
              :error="errors.delay_ms?.[0]"
            >
              <Input id="receiver-delay" v-model="form.delay_ms" inputmode="numeric" :disabled="isPending" :aria-invalid="!!errors.delay_ms" />
            </FormField>
          </div>

          <div class="flex items-center justify-end gap-3">
            <Button type="submit" :disabled="isPending || !form.mode">
              {{ isPending ? t('developers.receiver.applying') : t('developers.receiver.apply') }}
            </Button>
          </div>
        </form>

        <div class="grid gap-3">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="grid gap-0.5">
              <h3 class="text-sm font-medium">{{ t('developers.receiver.received') }}</h3>
              <p class="text-xs text-muted-foreground">{{ t('developers.receiver.receivedDescription', { count: receiver.secrets }, receiver.secrets) }}</p>
            </div>
            <Button variant="outline" size="sm" :disabled="isFetching" @click="refetch()">
              <RefreshCwIcon :class="isFetching ? 'animate-spin' : ''" />
              {{ t('developers.refresh') }}
            </Button>
          </div>

          <DataTable :columns="receivedColumns" :rows="receivedRows" :empty-title="t('developers.receiver.empty')">
            <template #cell-received_at="{ row }">
              <span class="whitespace-nowrap tabular-nums">{{ formatDateTime(row.received_at) }}</span>
            </template>
            <template #cell-event_type="{ row }">
              <span class="font-mono text-xs whitespace-nowrap">{{ row.event_type ?? '—' }}</span>
            </template>
            <template #cell-verification="{ row }">
              <StatusBadge domain="signature" :status="row.verification" />
            </template>
            <template #cell-response_status="{ row }">
              <span class="whitespace-nowrap">
                <span class="font-mono text-xs">HTTP {{ row.response_status }}</span>
                <span v-if="row.mode === 'slow'" class="text-xs text-muted-foreground">
                  · {{ t('developers.receiver.after', { ms: formatInteger(row.delay_ms ?? 0) }) }}</span
                >
              </span>
            </template>
            <template #cell-webhook_id="{ row }">
              <span class="font-mono text-xs whitespace-nowrap">{{ row.webhook_id ?? '—' }}</span>
            </template>
          </DataTable>
        </div>
      </template>
      <div v-else-if="!receiverError" class="grid gap-2" aria-busy="true">
        <span class="sr-only">{{ t('common.loading') }}</span>
        <Skeleton class="h-9 w-full" />
        <Skeleton class="h-32 w-full" />
      </div>

      <Alert>
        <InfoIcon />
        <AlertDescription>{{ t('developers.receiver.note') }}</AlertDescription>
      </Alert>
    </div>
  </FormSection>
</template>
