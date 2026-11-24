<script setup lang="ts">
import type { AttentionItem } from '@/api/workspace'
import { CircleCheckIcon } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import StatusBadge from '@/components/StatusBadge.vue'
import { DOCUMENT_ROUTES } from '@/lib/documents'
import { formatDateTime } from '@/lib/format'

/** Documents rejected or failed by the chain, and deliveries that failed, each with a link to act on it. */
defineProps<{
  items: AttentionItem[]
  total: number
}>()

const i18n = useI18n()
const { t } = i18n

/**
 * The customer and what went wrong: the DIAN's first rejection, the step of the chain that failed ("The transmission
 * failed."), or why the delivery failed.
 */
function summary(item: AttentionItem): string {
  const step = `invoices.view.events.steps.${item.detail ?? ''}`
  const detail = item.problem === 'failed' && item.detail !== null && i18n.te(step) ? t('invoices.dian.failedAt', { step: t(step) }) : item.detail

  return [item.customer_name, detail].filter(Boolean).join(' · ')
}
</script>

<template>
  <div v-if="items.length === 0" class="flex items-center gap-2 rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
    <CircleCheckIcon class="size-4 text-success" aria-hidden="true" />
    {{ t('dashboard.attention.none') }}
  </div>
  <ul v-else class="divide-y rounded-lg border">
    <li v-for="item in items" :key="`${item.problem}-${item.id}`" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5">
      <RouterLink :to="{ name: DOCUMENT_ROUTES[item.type].detail, params: { id: item.id } }" class="font-medium tabular-nums hover:underline">
        {{ item.full_number ?? t('invoices.draft') }}
      </RouterLink>
      <StatusBadge v-if="item.problem === 'delivery_failed'" domain="delivery" status="failed" />
      <StatusBadge v-else domain="electronic" :status="item.problem" />
      <span class="min-w-0 flex-1 truncate text-sm text-muted-foreground" :title="summary(item)">
        {{ summary(item) }}
      </span>
      <span v-if="item.since" class="text-xs text-muted-foreground tabular-nums">{{ formatDateTime(item.since) }}</span>
    </li>
  </ul>
  <p v-if="total > items.length" class="text-xs text-muted-foreground">{{ t('dashboard.attention.more', { count: total - items.length }) }}</p>
</template>
