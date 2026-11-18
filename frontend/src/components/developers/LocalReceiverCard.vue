<script setup lang="ts">
import type { ReceiverModeInput, ReceiverModeName } from '@/api/types'
import type { Option } from '@/lib/options'
import { FlaskConicalIcon, InfoIcon, RefreshCwIcon } from '@lucide/vue'
import { useQueryClient } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
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
import {
  Table,
  TableBody,
  TableCell,
  TableEmpty,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useFormMutation } from '@/composables/useFormMutation'
import { useWebhookReceiver } from '@/composables/useWebhookReceiver'
import { formatDateTime } from '@/lib/format'
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

const form = ref({ mode: 'normal' as string | null, count: '', delay_ms: '7000' })

const modeOptions = computed<Option[]>(() => (receiver.value?.modes ?? ['normal', 'error_500', 'slow']).map((mode) => ({ value: mode, label: t(`developers.receiver.modes.${mode}`) })))

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
    parts.push(mode.remaining === null ? t('developers.receiver.untilChanged') : t('developers.receiver.remaining', { count: mode.remaining }))
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

const applied = ref(false)
let appliedTimer: ReturnType<typeof setTimeout> | undefined

const { submit, isPending, error, errors } = useFormMutation({
  mutationFn: () => webhookReceiverApi.setMode(toInput()),
  onSuccess: async () => {
    applied.value = true
    clearTimeout(appliedTimer)
    appliedTimer = setTimeout(() => (applied.value = false), 4000)
    await queryClient.invalidateQueries({ queryKey: tenantKey(session.currentCompanyId, 'webhook-receiver') })
  },
})

// The form follows the mode of the receiver, unless a change is being applied.
watch(() => receiver.value?.mode, (mode) => {
  if (mode && !isPending.value) {
    form.value = { mode: mode.mode, count: '', delay_ms: String(mode.delay_ms ?? 7000) }
  }
}, { immediate: true })

const verificationVariant = (verified: boolean): 'secondary' | 'destructive' => (verified ? 'secondary' : 'destructive')

onBeforeUnmount(() => clearTimeout(appliedTimer))
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
            <FormField id="receiver-mode" :label="t('developers.receiver.mode')" :hint="form.mode ? t(`developers.receiver.modeHints.${form.mode}`) : undefined" :error="errors.mode?.[0]">
              <SelectInput id="receiver-mode" v-model="form.mode" :options="modeOptions" :disabled="isPending" :invalid="!!errors.mode" />
            </FormField>

            <FormField v-if="form.mode && form.mode !== 'normal'" id="receiver-count" :label="t('developers.receiver.count')" :hint="t('developers.receiver.countHint')" :error="errors.count?.[0]" optional>
              <Input id="receiver-count" v-model="form.count" inputmode="numeric" :disabled="isPending" :aria-invalid="!!errors.count" />
            </FormField>

            <FormField v-if="form.mode === 'slow'" id="receiver-delay" :label="t('developers.receiver.delay')" :hint="t('developers.receiver.delayHint')" :error="errors.delay_ms?.[0]">
              <Input id="receiver-delay" v-model="form.delay_ms" inputmode="numeric" :disabled="isPending" :aria-invalid="!!errors.delay_ms" />
            </FormField>
          </div>

          <div class="flex items-center justify-end gap-3">
            <p v-if="applied" class="text-sm text-muted-foreground" role="status">{{ t('developers.receiver.applied') }}</p>
            <Button type="submit" :disabled="isPending || !form.mode">
              {{ isPending ? t('developers.receiver.applying') : t('developers.receiver.apply') }}
            </Button>
          </div>
        </form>

        <div class="grid gap-3">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="grid gap-0.5">
              <h3 class="text-sm font-medium">{{ t('developers.receiver.received') }}</h3>
              <p class="text-xs text-muted-foreground">{{ t('developers.receiver.receivedDescription', { count: receiver.secrets }) }}</p>
            </div>
            <Button variant="outline" size="sm" :disabled="isFetching" @click="refetch()">
              <RefreshCwIcon :class="isFetching ? 'animate-spin' : ''" />
              {{ t('developers.refresh') }}
            </Button>
          </div>

          <div class="-mx-6 overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow class="hover:bg-transparent">
                  <TableHead class="pl-6">{{ t('developers.receiver.columns.time') }}</TableHead>
                  <TableHead>{{ t('developers.receiver.columns.event') }}</TableHead>
                  <TableHead>{{ t('developers.receiver.columns.signature') }}</TableHead>
                  <TableHead>{{ t('developers.receiver.columns.answer') }}</TableHead>
                  <TableHead class="pr-6">{{ t('developers.receiver.columns.webhookId') }}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <template v-if="receiver.received.length > 0">
                  <TableRow v-for="(hook, index) in receiver.received" :key="`${hook.received_at}-${index}`">
                    <TableCell class="pl-6 whitespace-nowrap tabular-nums">{{ formatDateTime(hook.received_at) }}</TableCell>
                    <TableCell class="font-mono text-xs whitespace-nowrap">{{ hook.event_type ?? '—' }}</TableCell>
                    <TableCell>
                      <Badge :variant="verificationVariant(hook.verified)">{{ t(`developers.receiver.verification.${hook.verification}`) }}</Badge>
                    </TableCell>
                    <TableCell class="whitespace-nowrap">
                      <span class="font-mono text-xs">HTTP {{ hook.response_status }}</span>
                      <span v-if="hook.mode === 'slow'" class="text-xs text-muted-foreground"> · {{ t('developers.receiver.after', { ms: hook.delay_ms }) }}</span>
                    </TableCell>
                    <TableCell class="pr-6 font-mono text-xs whitespace-nowrap">{{ hook.webhook_id ?? '—' }}</TableCell>
                  </TableRow>
                </template>
                <TableEmpty v-else :colspan="5">
                  <p class="text-muted-foreground">{{ t('developers.receiver.empty') }}</p>
                </TableEmpty>
              </TableBody>
            </Table>
          </div>
        </div>
      </template>
      <p v-else-if="!receiverError" class="text-muted-foreground">{{ t('common.loading') }}</p>

      <Alert>
        <InfoIcon />
        <AlertDescription>{{ t('developers.receiver.note') }}</AlertDescription>
      </Alert>
    </div>
  </FormSection>
</template>
