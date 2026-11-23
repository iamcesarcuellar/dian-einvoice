<script setup lang="ts">
import type { Dashboard } from '@/api/workspace'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatInteger, formatMoney, formatWholeMoney } from '@/lib/format'

/**
 * The five customers with the most invoiced in the last 90 days, each with a bar against the first.
 * Amounts in whole pesos, the exact one in its tooltip.
 */
const props = defineProps<{
  customers: Dashboard['top_customers']
}>()

const { t } = useI18n()

const top = computed(() => Math.max(...props.customers.map((customer) => Number(customer.amount)), 1))
</script>

<template>
  <p v-if="customers.length === 0" class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">{{ t('dashboard.topCustomers.empty') }}</p>
  <ol v-else class="grid gap-3">
    <li v-for="customer in customers" :key="customer.contact_id" class="grid gap-1.5">
      <div class="flex items-baseline justify-between gap-3 text-sm">
        <RouterLink
          :to="{ name: 'invoices', query: { contact: customer.contact_id, customer: customer.name } }"
          class="min-w-0 truncate font-medium hover:underline"
        >
          {{ customer.name }}
        </RouterLink>
        <span class="shrink-0 font-medium tabular-nums" :title="formatMoney(customer.amount)">{{ formatWholeMoney(customer.amount) }}</span>
      </div>
      <div class="flex items-center gap-3">
        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted" aria-hidden="true">
          <div class="h-full rounded-full bg-chart-1" :style="{ width: `${(Number(customer.amount) * 100) / top}%` }" />
        </div>
        <span class="w-20 shrink-0 text-right text-xs text-muted-foreground tabular-nums">{{
          t('dashboard.topCustomers.invoices', { n: formatInteger(customer.invoices) }, customer.invoices)
        }}</span>
      </div>
    </li>
  </ol>
</template>
