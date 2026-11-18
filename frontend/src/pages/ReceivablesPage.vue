<script setup lang="ts">
import type { AgingBucket } from '@/api/types'
import { ArrowUpRightIcon } from '@lucide/vue'
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { paymentsApi } from '@/api/resources'
import {
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { formatMoney } from '@/lib/format'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * Accounts receivable by customer: what each one owes and how long past due, with the totals of
 * every column at the foot. Calculated by the server from the accepted invoices.
 */
const { t } = useI18n()
const session = useSessionStore()

const BUCKETS: AgingBucket[] = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90']

const { data: report, isPending } = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, 'receivables', 'report')),
  queryFn: () => paymentsApi.receivables(),
  enabled: computed(() => session.currentCompanyId !== null),
})

const hasCredit = computed(() => (report.value?.customers ?? []).some((row) => !/^0+(?:\.0+)?$/.test(row.customer_credit)))

function amount(value: string): string {
  return /^0+(?:\.0+)?$/.test(value) ? '—' : formatMoney(value)
}
</script>

<template>
  <div class="grid gap-3">
    <p v-if="isPending" class="text-muted-foreground">{{ t('common.loading') }}</p>
    <div v-else-if="(report?.customers ?? []).length === 0" class="grid gap-1 rounded-lg border p-10 text-center">
      <p class="font-medium">{{ t('receivables.empty.title') }}</p>
      <p class="text-muted-foreground">{{ t('receivables.empty.description') }}</p>
    </div>
    <div v-else class="overflow-x-auto rounded-lg border">
      <Table>
        <TableHeader>
          <TableRow class="hover:bg-transparent">
            <TableHead class="pl-4">{{ t('receivables.columns.customer') }}</TableHead>
            <TableHead class="text-right">{{ t('receivables.columns.total') }}</TableHead>
            <TableHead v-for="bucket in BUCKETS" :key="bucket" class="text-right whitespace-nowrap">{{ t(`receivables.buckets.${bucket}`) }}</TableHead>
            <TableHead v-if="hasCredit" class="text-right whitespace-nowrap">{{ t('receivables.columns.credit') }}</TableHead>
            <TableHead class="pr-4" />
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableRow v-for="row in report?.customers ?? []" :key="row.contact_id">
            <TableCell class="pl-4">
              <span class="grid min-w-48">
                <span class="font-medium">{{ row.customer_name }}</span>
                <span class="text-xs text-muted-foreground">
                  {{ t('receivables.invoices', { count: row.invoices }) }}<template v-if="row.overdue_invoices > 0"> · <span class="text-destructive">{{ t('receivables.overdue', { count: row.overdue_invoices, days: row.oldest_days_past_due }) }}</span></template>
                </span>
              </span>
            </TableCell>
            <TableCell class="text-right font-semibold whitespace-nowrap tabular-nums">{{ amount(row.total) }}</TableCell>
            <TableCell v-for="bucket in BUCKETS" :key="bucket" class="text-right whitespace-nowrap tabular-nums" :class="bucket !== 'current' && amount(row[bucket]) !== '—' ? 'text-destructive' : ''">{{ amount(row[bucket]) }}</TableCell>
            <TableCell v-if="hasCredit" class="text-right whitespace-nowrap text-emerald-700 tabular-nums dark:text-emerald-400">{{ amount(row.customer_credit) }}</TableCell>
            <TableCell class="pr-4 text-right whitespace-nowrap">
              <RouterLink :to="{ name: 'invoices', query: { contact: row.contact_id, customer: row.customer_name } }" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline">
                {{ t('receivables.viewInvoices') }}
                <ArrowUpRightIcon class="size-3.5" />
              </RouterLink>
            </TableCell>
          </TableRow>
        </TableBody>
        <TableFooter v-if="report">
          <TableRow class="hover:bg-transparent">
            <TableCell class="pl-4 font-semibold">{{ t('receivables.totals') }}</TableCell>
            <TableCell class="text-right font-semibold whitespace-nowrap tabular-nums">{{ amount(report.totals.total) }}</TableCell>
            <TableCell v-for="bucket in BUCKETS" :key="bucket" class="text-right font-semibold whitespace-nowrap tabular-nums">{{ amount(report.totals[bucket]) }}</TableCell>
            <TableCell v-if="hasCredit" class="text-right font-semibold whitespace-nowrap tabular-nums">{{ amount(report.totals.customer_credit) }}</TableCell>
            <TableCell class="pr-4" />
          </TableRow>
        </TableFooter>
      </Table>
    </div>
    <p class="text-xs text-muted-foreground">{{ t('receivables.hint') }}</p>
  </div>
</template>
