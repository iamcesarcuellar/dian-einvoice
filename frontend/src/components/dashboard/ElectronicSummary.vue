<script setup lang="ts">
import type { Dashboard } from '@/api/workspace'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatInteger } from '@/lib/format'
import { statusStyle } from '@/lib/status'
import { cn } from '@/lib/utils'

/**
 * Electronic documents issued in the last 30 days by state, as one bar split by state with a
 * legend of counts. States keep the colors, icons and words of the status map.
 */
const props = defineProps<{
  electronic: Dashboard['electronic']
}>()

const { t } = useI18n()

const STATES = ['accepted', 'processing', 'rejected', 'failed'] as const

// Fills in the tone of each state, as the badges use it.
const FILL: Record<string, string> = {
  success: 'bg-success',
  info: 'bg-info',
  danger: 'bg-destructive',
  warning: 'bg-warning',
}

const segments = computed(() =>
  STATES.map((state) => {
    const style = statusStyle('electronic', state)

    return {
      state,
      count: props.electronic[state],
      share: props.electronic.total > 0 ? (props.electronic[state] * 100) / props.electronic.total : 0,
      icon: style.icon,
      fill: FILL[style.tone] ?? 'bg-muted',
    }
  }),
)
</script>

<template>
  <div class="grid gap-3">
    <div
      class="flex h-2.5 gap-0.5 overflow-hidden rounded-full bg-muted"
      role="img"
      :aria-label="
        t('dashboard.electronic.summary', {
          documents: t('dashboard.electronic.documents', { n: formatInteger(electronic.total) }, electronic.total),
          period: t('dashboard.electronic.period', { n: formatInteger(electronic.days) }, electronic.days),
        })
      "
    >
      <span
        v-for="segment in segments.filter((entry) => entry.count > 0)"
        :key="segment.state"
        :class="cn('h-full first:rounded-l-full last:rounded-r-full', segment.fill)"
        :style="{ width: `${segment.share}%` }"
      />
    </div>
    <ul class="grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-4">
      <li v-for="segment in segments" :key="segment.state" class="grid gap-0.5">
        <span class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
          <span :class="cn('size-2 rounded-full', segment.fill)" aria-hidden="true" />
          {{ t(`status.electronic.${segment.state}`) }}
        </span>
        <span class="text-lg font-semibold tabular-nums">{{ formatInteger(segment.count) }}</span>
      </li>
    </ul>
  </div>
</template>
