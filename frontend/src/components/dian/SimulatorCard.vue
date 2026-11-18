<script setup lang="ts">
import type { SimulatorModeInput, SimulatorModeName, SimulatorSettings } from '@/api/types'
import type { Option } from '@/lib/options'
import { FlaskConicalIcon, RefreshCwIcon } from '@lucide/vue'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { simulatorApi } from '@/api/dian'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import FormSection from '@/components/FormSection.vue'
import SelectInput from '@/components/SelectInput.vue'
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
import { formatDateTime } from '@/lib/format'
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

const { data: requests, error: requestsError, refetch, isFetching } = useQuery({
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

const ruleOptions = computed<Option[]>(() => (settings.value?.rules ?? [])
  .filter((rule) => rule.kind === 'rejection')
  .map((rule) => ({ value: rule.code, label: `${rule.code} · ${rule.message}` })))

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
    parts.push(mode.remaining === null ? t('settings.dian.simulator.untilChanged') : t('settings.dian.simulator.remaining', { count: mode.remaining }))
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

const applied = ref(false)
let appliedTimer: ReturnType<typeof setTimeout> | undefined

const { submit, isPending, error, errors, reset } = useFormMutation({
  mutationFn: () => simulatorApi.setMode(toInput()),
  onSuccess: (mode) => {
    queryClient.setQueryData(settingsKey.value, settings.value ? { ...settings.value, mode } : settings.value)
    applied.value = true
    clearTimeout(appliedTimer)
    appliedTimer = setTimeout(() => (applied.value = false), 4000)
  },
})

watch(() => session.currentCompanyId, () => {
  reset()
  applied.value = false
})

onBeforeUnmount(() => clearTimeout(appliedTimer))
</script>

<template>
  <FormSection :title="t('settings.dian.simulator.title')" :description="t('settings.dian.simulator.description')">
    <div class="grid gap-6">
      <FormError :error="settingsError ?? requestsError" />

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
          <FormField id="simulator-mode" :label="t('settings.dian.simulator.mode')" :hint="form.mode ? t(`settings.dian.simulator.modeHints.${form.mode}`) : undefined" :error="errors.mode?.[0]">
            <SelectInput id="simulator-mode" v-model="form.mode" :options="modeOptions" :disabled="isPending" :invalid="!!errors.mode" />
          </FormField>

          <FormField v-if="form.mode === 'reject'" id="simulator-rule" :label="t('settings.dian.simulator.rule')" :error="errors.rule?.[0]">
            <SelectInput id="simulator-rule" v-model="form.rule" :options="ruleOptions" :disabled="isPending" :invalid="!!errors.rule" />
          </FormField>

          <FormField v-if="form.mode === 'slow'" id="simulator-delay" :label="t('settings.dian.simulator.delay')" :error="errors.delay_ms?.[0]">
            <Input id="simulator-delay" v-model="form.delay_ms" inputmode="numeric" :disabled="isPending" :aria-invalid="!!errors.delay_ms" />
          </FormField>

          <FormField v-if="form.mode === 'intermittent'" id="simulator-rate" :label="t('settings.dian.simulator.failureRate')" :error="errors.failure_rate?.[0]">
            <Input id="simulator-rate" v-model="form.failure_rate" inputmode="numeric" :disabled="isPending" :aria-invalid="!!errors.failure_rate" />
          </FormField>

          <FormField v-if="form.mode && form.mode !== 'normal'" id="simulator-count" :label="t('settings.dian.simulator.count')" :hint="t('settings.dian.simulator.countHint')" :error="errors.count?.[0]" optional>
            <Input id="simulator-count" v-model="form.count" inputmode="numeric" :disabled="isPending" :aria-invalid="!!errors.count" />
          </FormField>
        </div>

        <div class="flex items-center justify-end gap-3">
          <p v-if="applied" class="text-sm text-muted-foreground" role="status">{{ t('settings.dian.simulator.applied') }}</p>
          <Button type="submit" :disabled="isPending || !form.mode">
            {{ isPending ? t('settings.dian.simulator.applying') : t('settings.dian.simulator.apply') }}
          </Button>
        </div>
      </form>

      <p v-else-if="!settingsError" class="text-muted-foreground">{{ t('common.loading') }}</p>

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

        <div class="-mx-6 overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow class="hover:bg-transparent">
                <TableHead class="pl-6">{{ t('settings.dian.simulator.columns.time') }}</TableHead>
                <TableHead>{{ t('settings.dian.simulator.columns.operation') }}</TableHead>
                <TableHead>{{ t('settings.dian.simulator.columns.document') }}</TableHead>
                <TableHead>{{ t('settings.dian.simulator.columns.result') }}</TableHead>
                <TableHead class="pr-6 text-right">{{ t('settings.dian.simulator.columns.duration') }}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <template v-if="requests && requests.length > 0">
                <TableRow v-for="(request, index) in requests" :key="`${request.at}-${index}`">
                  <TableCell class="pl-6 whitespace-nowrap tabular-nums">{{ formatDateTime(request.at) }}</TableCell>
                  <TableCell class="whitespace-nowrap">{{ request.operation }}</TableCell>
                  <TableCell class="whitespace-nowrap tabular-nums">{{ request.document ?? '—' }}</TableCell>
                  <TableCell>
                    <span class="grid gap-0.5">
                      <span>{{ request.result }}</span>
                      <span v-if="request.mode || request.rules.length > 0" class="text-xs text-muted-foreground">
                        {{ [request.mode, request.rules.join(', ')].filter(Boolean).join(' · ') }}
                      </span>
                    </span>
                  </TableCell>
                  <TableCell class="pr-6 text-right whitespace-nowrap tabular-nums">{{ t('settings.dian.simulator.duration', { ms: request.duration_ms }) }}</TableCell>
                </TableRow>
              </template>
              <TableEmpty v-else :colspan="5">
                <p class="text-muted-foreground">{{ requests ? t('settings.dian.simulator.activityEmpty') : t('common.loading') }}</p>
              </TableEmpty>
            </TableBody>
          </Table>
        </div>
      </div>
    </div>
  </FormSection>
</template>
