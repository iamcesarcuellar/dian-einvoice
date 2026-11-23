<script setup lang="ts">
import type { SimulatorModeInput, SimulatorModeName, SimulatorSettings } from '@/api/types'
import type { Option } from '@/lib/options'
import { FlaskConicalIcon, RefreshCwIcon } from '@lucide/vue'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import type { DataTableColumn } from '@/components/data-table/types'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { simulatorApi } from '@/api/dian'
import DataTable from '@/components/data-table/DataTable.vue'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import FormSection from '@/components/FormSection.vue'
import SelectInput from '@/components/SelectInput.vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormMutation } from '@/composables/useFormMutation'
import { formatDateTime, formatInteger } from '@/lib/format'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * The local DIAN simulator, a development tool, as this company sees it: the failure mode for its
 * requests, the controls to change it, and the last requests the simulator received from it. The
 * API talks to the simulator; the browser never does.
 */
const { t } = useI18n()
const queryClient = useQueryClient()
const session = useSessionStore()

const settingsKey = computed(() => tenantKey(session.currentCompanyId, 'dian-simulator', 'settings'))
const requestsKey = computed(() => tenantKey(session.currentCompanyId, 'dian-simulator', 'requests'))

const { data: settings, error: settingsError } = useQuery({
  queryKey: settingsKey,
  queryFn: () => simulatorApi.settings(),
})

const {
  data: requests,
  error: requestsError,
  refetch,
  isFetching,
  isPending: requestsPending,
} = useQuery({
  queryKey: requestsKey,
  queryFn: () => simulatorApi.requests(30),
  refetchInterval: 5000,
})

interface ModeForm {
  mode: string | null
  rule: string | null
  delay_ms: string
  failure_rate: string
  count: string
}

function formFrom(value: SimulatorSettings | undefined): ModeForm {
  const mode = value?.mode

  return {
    mode: mode?.scope === 'company' ? mode.mode : 'normal',
    rule: mode?.rule ?? null,
    delay_ms: mode?.delay_ms === null || mode?.delay_ms === undefined ? '3000' : String(mode.delay_ms),
    failure_rate: mode?.failure_rate === null || mode?.failure_rate === undefined ? '50' : String(mode.failure_rate),
    count: '',
  }
}

const form = ref<ModeForm>(formFrom(settings.value))

watch(settings, (value) => {
  form.value = formFrom(value)
})

const modeOptions = computed<Option[]>(() => (settings.value?.modes ?? []).map((mode) => ({ value: mode, label: t(`settings.dian.simulator.modes.${mode}`) })))

const ruleOptions = computed<Option[]>(() =>
  (settings.value?.rules ?? []).filter((rule) => rule.kind === 'rejection').map((rule) => ({ value: rule.code, label: `${rule.code} · ${rule.message}` })),
)

const current = computed(() => settings.value?.mode ?? null)

const currentLabel = computed(() => {
  const mode = current.value

  if (!mode) {
    return ''
  }

  const parts = [t(`settings.dian.simulator.modes.${mode.mode}`)]

  if (mode.mode === 'reject' && mode.rule) {
    parts.push(mode.rule)
  }

  if (mode.mode === 'slow' && mode.delay_ms !== null) {
    parts.push(t('settings.dian.simulator.duration', { ms: mode.delay_ms }))
  }

  if (mode.mode === 'intermittent' && mode.failure_rate !== null) {
    parts.push(`${mode.failure_rate} %`)
  }

  if (mode.mode !== 'normal') {
    parts.push(
      mode.remaining === null ? t('settings.dian.simulator.untilChanged') : t('settings.dian.simulator.remaining', { count: mode.remaining }, mode.remaining),
    )
  }

  if (mode.scope === 'all') {
    parts.push(t('settings.dian.simulator.everyCompany'))
  }

  return parts.join(' · ')
})

function numberOrNull(value: string): number | null {
  const trimmed = value.trim()

  return trimmed === '' ? null : Number(trimmed)
}

function toInput(): SimulatorModeInput {
  const mode = (form.value.mode ?? 'normal') as SimulatorModeName

  return {
    mode,
    rule: mode === 'reject' ? form.value.rule : null,
    delay_ms: mode === 'slow' ? numberOrNull(form.value.delay_ms) : null,
    failure_rate: mode === 'intermittent' ? numberOrNull(form.value.failure_rate) : null,
    count: mode === 'normal' ? null : numberOrNull(form.value.count),
  }
}

const requestRows = computed(() => (requests.value ?? []).map((request, index) => ({ ...request, id: `${request.at}-${index}` })))

const requestColumns = computed<DataTableColumn[]>(() => [
  { key: 'at', label: t('settings.dian.simulator.columns.time') },
  { key: 'operation', label: t('settings.dian.simulator.columns.operation') },
  { key: 'document', label: t('settings.dian.simulator.columns.document'), truncate: true },
  { key: 'result', label: t('settings.dian.simulator.columns.result') },
  { key: 'duration', label: t('settings.dian.simulator.columns.duration'), align: 'right' },
])

const { submit, isPending, error, errors, reset } = useFormMutation({
  mutationFn: () => simulatorApi.setMode(toInput()),
  success: t('settings.dian.simulator.applied'),
  onSuccess: (mode) => {
    queryClient.setQueryData(settingsKey.value, settings.value ? { ...settings.value, mode } : settings.value)
  },
})

watch(
  () => session.currentCompanyId,
  () => {
    reset()
  },
)
</script>

<template>
  <FormSection :title="t('settings.dian.simulator.title')" :description="t('settings.dian.simulator.description')">
    <div class="grid gap-6">
      <FormError :error="settingsError" />

      <form v-if="settings" class="grid gap-4" novalidate @submit.prevent="submit">
        <div class="flex flex-wrap items-center gap-2">
          <Badge variant="secondary">
            <FlaskConicalIcon />
            {{ t('settings.dian.simulator.current') }}
          </Badge>
          <span class="text-sm">{{ currentLabel }}</span>
        </div>

        <FormError :error="error" />

        <div class="grid gap-4 sm:grid-cols-2">
          <FormField
            id="simulator-mode"
            :label="t('settings.dian.simulator.mode')"
            :hint="form.mode ? t(`settings.dian.simulator.modeHints.${form.mode}`) : undefined"
            :error="errors.mode?.[0]"
          >
            <SelectInput id="simulator-mode" v-model="form.mode" :options="modeOptions" :disabled="isPending" :invalid="!!errors.mode" />
          </FormField>

          <FormField v-if="form.mode === 'reject'" id="simulator-rule" :label="t('settings.dian.simulator.rule')" :error="errors.rule?.[0]">
            <SelectInput id="simulator-rule" v-model="form.rule" :options="ruleOptions" :disabled="isPending" :invalid="!!errors.rule" />
          </FormField>

          <FormField v-if="form.mode === 'slow'" id="simulator-delay" :label="t('settings.dian.simulator.delay')" :error="errors.delay_ms?.[0]">
            <Input id="simulator-delay" v-model="form.delay_ms" inputmode="numeric" :disabled="isPending" :aria-invalid="!!errors.delay_ms" />
          </FormField>

          <FormField
            v-if="form.mode === 'intermittent'"
            id="simulator-rate"
            :label="t('settings.dian.simulator.failureRate')"
            :error="errors.failure_rate?.[0]"
          >
            <Input id="simulator-rate" v-model="form.failure_rate" inputmode="numeric" :disabled="isPending" :aria-invalid="!!errors.failure_rate" />
          </FormField>

          <FormField
            v-if="form.mode && form.mode !== 'normal'"
            id="simulator-count"
            :label="t('settings.dian.simulator.count')"
            :hint="t('settings.dian.simulator.countHint')"
            :error="errors.count?.[0]"
            optional
          >
            <Input id="simulator-count" v-model="form.count" inputmode="numeric" :disabled="isPending" :aria-invalid="!!errors.count" />
          </FormField>
        </div>

        <div class="flex items-center justify-end gap-3">
          <Button type="submit" :disabled="isPending || !form.mode">
            {{ isPending ? t('settings.dian.simulator.applying') : t('settings.dian.simulator.apply') }}
          </Button>
        </div>
      </form>

      <div v-else-if="!settingsError" class="grid gap-2" aria-busy="true">
        <Skeleton class="h-5 w-40" />
        <Skeleton class="h-9 w-full" />
      </div>

      <div class="grid gap-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="grid gap-0.5">
            <h3 class="text-sm font-medium">{{ t('settings.dian.simulator.activity') }}</h3>
            <p class="text-xs text-muted-foreground">{{ t('settings.dian.simulator.activityDescription') }}</p>
          </div>
          <Button variant="outline" size="sm" :disabled="isFetching" @click="refetch()">
            <RefreshCwIcon :class="isFetching ? 'animate-spin' : ''" />
            {{ t('settings.dian.simulator.refresh') }}
          </Button>
        </div>

        <DataTable
          :columns="requestColumns"
          :rows="requestRows"
          :loading="requestsPending"
          :error="requestsError"
          :empty-title="t('settings.dian.simulator.activityEmpty')"
          :skeleton-rows="3"
          @retry="refetch()"
        >
          <template #cell-at="{ row }">
            <span class="whitespace-nowrap tabular-nums">{{ formatDateTime(row.at) }}</span>
          </template>
          <template #cell-operation="{ row }">
            <span class="whitespace-nowrap">{{ row.operation }}</span>
          </template>
          <template #cell-document="{ row }">
            <span class="block truncate tabular-nums" :title="row.document ?? undefined">{{ row.document ?? '—' }}</span>
          </template>
          <template #cell-result="{ row }">
            <span class="grid gap-0.5">
              <span>{{ row.result }}</span>
              <span v-if="row.mode || row.rules.length > 0" class="text-xs text-muted-foreground">
                {{ [row.mode, row.rules.join(', ')].filter(Boolean).join(' · ') }}
              </span>
            </span>
          </template>
          <template #cell-duration="{ row }">
            <span class="whitespace-nowrap tabular-nums">{{ t('settings.dian.simulator.duration', { ms: formatInteger(row.duration_ms) }) }}</span>
          </template>
        </DataTable>
      </div>
    </div>
  </FormSection>
</template>
