<script setup lang="ts">
import type { AgingBucket } from '@/api/types'
import type { Dashboard } from '@/api/workspace'
import type { ChartConfig } from '@/components/ui/chart'
import { VisAxis, VisGroupedBar, VisXYContainer } from '@unovis/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChartContainer, ChartCrosshair, ChartTooltip } from '@/components/ui/chart'
import { formatCompactMoney, formatMoney } from '@/lib/format'
import { tooltipHtml } from './tooltip'

interface Datum {
  index: number
  bucket: AgingBucket
  amount: number
}

/**
 * What is left to collect by age, in the five buckets of the receivables: one hue that gets
 * stronger with age (not due yet is the lightest), so older debt stands out in both themes.
 */
const props = defineProps<{
  aging: Dashboard['aging']
}>()

const { t } = useI18n()

const BUCKETS: AgingBucket[] = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90']

// Steps of --chart-5 mixed with the card: further from the surface means older.
const COLORS: Record<AgingBucket, string> = {
  current: 'color-mix(in oklab, var(--chart-5) 28%, var(--card))',
  days_1_30: 'color-mix(in oklab, var(--chart-5) 46%, var(--card))',
  days_31_60: 'color-mix(in oklab, var(--chart-5) 64%, var(--card))',
  days_61_90: 'color-mix(in oklab, var(--chart-5) 82%, var(--card))',
  over_90: 'var(--chart-5)',
}

const data = computed<Datum[]>(() => BUCKETS.map((bucket, index) => ({ index, bucket, amount: Number(props.aging[bucket]) })))

const config = computed<ChartConfig>(() => ({
  amount: { label: t('dashboard.aging.amount'), color: 'var(--chart-5)' },
}))

// Unovis hands the hovered datum without its type.
function template(hovered: unknown): string {
  const { bucket } = hovered as Datum
  const amount = props.aging[bucket]

  return tooltipHtml(`aging:${bucket}:${amount}`, t(`receivables.buckets.${bucket}`), [
    { label: t('dashboard.aging.amount'), value: formatMoney(amount), color: COLORS[bucket] },
  ])
}

function bucketTick(tick: number | Date): string {
  const bucket = BUCKETS[Number(tick)]

  return bucket ? t(`dashboard.aging.short.${bucket}`) : ''
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
          :y="(datum: Datum) => datum.amount"
          :color="(datum: Datum) => COLORS[datum.bucket]"
          :rounded-corners="4"
          :group-padding="0.3"
          :group-max-width="56"
        />
        <VisAxis type="x" :x="(datum: Datum) => datum.index" :tick-values="data.map((datum) => datum.index)" :tick-format="bucketTick" :grid-line="false" :tick-line="false" :domain-line="false" />
        <VisAxis type="y" :num-ticks="4" :tick-format="amountTick" :tick-line="false" :domain-line="false" />
        <ChartTooltip />
        <ChartCrosshair :template="template" color="#0000" />
      </VisXYContainer>
    </ChartContainer>

    <table class="sr-only">
      <caption>{{ t('dashboard.aging.title') }}</caption>
      <tbody>
        <tr v-for="bucket in BUCKETS" :key="bucket">
          <th scope="row">{{ t(`receivables.buckets.${bucket}`) }}</th>
          <td>{{ formatMoney(aging[bucket]) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
