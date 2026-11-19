<script setup lang="ts">
import type { DashboardKpi } from '@/api/workspace'
import { MinusIcon, TrendingDownIcon, TrendingUpIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Card, CardContent } from '@/components/ui/card'
import { formatChange, formatInteger, formatMoney } from '@/lib/format'
import { TONE_TEXT } from '@/lib/status'
import { cn } from '@/lib/utils'

/**
 * One indicator of the month to date: the amount, how many documents make it, and the change
 * against the same days of the previous month. Up is good for invoiced and collected, and bad for
 * receivables and overdue (higherIsBetter), so the arrow always carries its meaning in words too.
 */
const props = defineProps<{
  label: string
  /** What the amount includes, such as "Net of withholdings". */
  hint?: string
  kpi: DashboardKpi
  /** "12 invoices". */
  countLabel: string
  /** "vs Oct 1–19". */
  comparedTo: string
  higherIsBetter: boolean
}>()

const { t } = useI18n()

const direction = computed(() => {
  if (props.kpi.change === null) {
    return 'none'
  }

  const value = Number(props.kpi.change)

  return value > 0 ? 'up' : value < 0 ? 'down' : 'flat'
})

const tone = computed(() => {
  if (direction.value === 'none' || direction.value === 'flat') {
    return TONE_TEXT.outline
  }

  const good = (direction.value === 'up') === props.higherIsBetter

  return good ? TONE_TEXT.success : TONE_TEXT.danger
})

const icon = computed(() => (direction.value === 'up' ? TrendingUpIcon : direction.value === 'down' ? TrendingDownIcon : MinusIcon))
</script>

<template>
  <Card class="gap-0 py-0">
    <CardContent class="grid gap-2 p-5">
      <p class="flex items-baseline justify-between gap-2 text-sm font-medium text-muted-foreground">
        <span>{{ label }}</span>
        <span v-if="hint" class="text-xs font-normal">{{ hint }}</span>
      </p>
      <p class="truncate text-2xl font-semibold tracking-tight tabular-nums" :title="formatMoney(kpi.amount)">{{ formatMoney(kpi.amount) }}</p>
      <p class="text-xs text-muted-foreground">{{ countLabel }}</p>
      <p class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs">
        <span :class="cn('inline-flex items-center gap-1 font-medium tabular-nums', tone)">
          <component :is="icon" class="size-3.5" aria-hidden="true" />
          <template v-if="kpi.change !== null">{{ formatChange(kpi.change) }}</template>
          <template v-else>{{ t('dashboard.kpis.noComparison') }}</template>
        </span>
        <span class="text-muted-foreground tabular-nums" :title="formatMoney(kpi.previous_amount)">
          {{ comparedTo }} · {{ formatMoney(kpi.previous_amount) }}<template v-if="kpi.previous_count"> ({{ formatInteger(kpi.previous_count) }})</template>
        </span>
      </p>
    </CardContent>
  </Card>
</template>
