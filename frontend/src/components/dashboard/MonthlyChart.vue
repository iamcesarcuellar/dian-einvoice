<script setup lang="ts">
import type { DashboardMonth } from '@/api/workspace'
import type { ChartConfig } from '@/components/ui/chart'
import { VisAxis, VisGroupedBar, VisXYContainer } from '@unovis/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChartContainer, ChartCrosshair, ChartTooltip } from '@/components/ui/chart'
import { formatCompactMoney, formatInteger, formatMonth, formatMoney } from '@/lib/format'
import { tooltipHtml } from './tooltip'

interface Datum {
  index: number
  source: DashboardMonth
  invoiced: number
  collected: number
}

/**
 * Invoiced and collected by month, for the last six months: two bars per month on one axis of
 * pesos, with a hover card per month and a legend. Numbers only feed the drawing; every figure on
 * screen comes from the decimal strings of the API.
 */
const props = defineProps<{
  months: DashboardMonth[]
}>()

const { t } = useI18n()

const SERIES = {
  invoiced: 'var(--chart-1)',
  collected: 'var(--chart-2)',
} as const

const data = computed<Datum[]>(() => props.months.map((month, index) => ({
  index,
  source: month,
  invoiced: Number(month.invoiced),
  collected: Number(month.collected),
})))

const config = computed<ChartConfig>(() => ({
  invoiced: { label: t('dashboard.monthly.invoiced'), color: SERIES.invoiced },
  collected: { label: t('dashboard.monthly.collected'), color: SERIES.collected },
}))

// Unovis hands the hovered datum without its type.
function template(hovered: unknown): string {
  const month = (hovered as Datum).source

  return tooltipHtml(`monthly:${month.month}:${month.invoiced}:${month.collected}`, formatMonth(month.month, 'long'), [
    { label: t('dashboard.monthly.invoiced'), value: formatMoney(month.invoiced), color: SERIES.invoiced, hint: t('dashboard.monthly.invoices', { n: formatInteger(month.invoices) }, month.invoices) },
    { label: t('dashboard.monthly.collected'), value: formatMoney(month.collected), color: SERIES.collected, hint: t('dashboard.monthly.payments', { n: formatInteger(month.payments) }, month.payments) },
  ])
}

const tickValues = computed(() => data.value.map((datum) => datum.index))

function monthTick(tick: number | Date): string {
  const month = data.value[Number(tick)]?.source.month

  return month ? formatMonth(month) : ''
}

function amountTick(tick: number | Date): string {
  return formatCompactMoney(Number(tick))
}
</script>

<template>
  <div class="grid gap-3">
    <ChartContainer :config="config" class="aspect-auto h-64 w-full" cursor>
      <VisXYContainer :data="data" :margin="{ left: 8, right: 8 }" :padding="{ top: 12 }" :y-domain="[0, undefined]">
        <VisGroupedBar
          :x="(datum: Datum) => datum.index"
          :y="[(datum: Datum) => datum.invoiced, (datum: Datum) => datum.collected]"
          :color="[SERIES.invoiced, SERIES.collected]"
          :rounded-corners="4"
          :bar-padding="0.12"
          :group-padding="0.35"
          :group-max-width="72"
        />
        <VisAxis type="x" :x="(datum: Datum) => datum.index" :tick-values="tickValues" :tick-format="monthTick" :grid-line="false" :tick-line="false" :domain-line="false" />
        <VisAxis type="y" :num-ticks="4" :tick-format="amountTick" :tick-line="false" :domain-line="false" />
        <ChartTooltip />
        <ChartCrosshair :template="template" color="#0000" />
      </VisXYContainer>
    </ChartContainer>

    <ul class="flex flex-wrap items-center justify-center gap-x-5 gap-y-1 text-xs text-muted-foreground" :aria-label="t('dashboard.monthly.legend')">
      <li v-for="(color, key) in SERIES" :key="key" class="inline-flex items-center gap-1.5">
        <span class="size-2.5 rounded-xs" :style="{ backgroundColor: color }" aria-hidden="true" />
        {{ t(`dashboard.monthly.${key}`) }}
      </li>
    </ul>

    <!-- The same figures as a table, for screen readers. -->
    <table class="sr-only">
      <caption>{{ t('dashboard.monthly.title') }}</caption>
      <thead>
        <tr>
          <th scope="col">{{ t('dashboard.monthly.month') }}</th>
          <th scope="col">{{ t('dashboard.monthly.invoiced') }}</th>
          <th scope="col">{{ t('dashboard.monthly.collected') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="month in months" :key="month.month">
          <th scope="row">{{ formatMonth(month.month, 'long') }}</th>
          <td>{{ formatMoney(month.invoiced) }}</td>
          <td>{{ formatMoney(month.collected) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
