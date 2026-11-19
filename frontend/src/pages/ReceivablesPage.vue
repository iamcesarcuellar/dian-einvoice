<script setup lang="ts">
import type { AgingBucket } from '@/api/types'
import type { DataTableColumn } from '@/components/data-table/types'
import { ArrowUpRightIcon } from '@lucide/vue'
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { paymentsApi } from '@/api/resources'
import DataTable from '@/components/data-table/DataTable.vue'
import { TableCell, TableRow } from '@/components/ui/table'
import { formatInteger, formatMoney } from '@/lib/format'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * Accounts receivable by customer: what each one owes and how long past due, with the totals of
 * every column at the foot. Calculated by the server from the accepted invoices.
 */
const { t } = useI18n()
const session = useSessionStore()

const BUCKETS: AgingBucket[] = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90']

const {
  data: report,
  isPending,
  error,
  refetch,
} = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, 'receivables', 'report')),
  queryFn: () => paymentsApi.receivables(),
  enabled: computed(() => session.currentCompanyId !== null),
})

const rows = computed(() => (report.value?.customers ?? []).map((row) => ({ ...row, id: row.contact_id })))
const hasCredit = computed(() => rows.value.some((row) => !isZero(row.customer_credit)))

const columns = computed<DataTableColumn[]>(() => [
  { key: 'customer', label: t('receivables.columns.customer') },
  { key: 'total', label: t('receivables.columns.total'), align: 'right' },
  ...BUCKETS.map((bucket) => ({ key: bucket, label: t(`receivables.buckets.${bucket}`), align: 'right' as const })),
  ...(hasCredit.value ? [{ key: 'customer_credit', label: t('receivables.columns.credit'), align: 'right' as const }] : []),
  { key: 'link', label: t('receivables.columns.invoices'), align: 'right' },
])

function isZero(value: string): boolean {
  return /^0+(?:\.0+)?$/.test(value)
}

function amount(value: string): string {
  return isZero(value) ? '—' : formatMoney(value)
}
</script>

<template>
  <div class="grid gap-3">
    <DataTable
      :columns="columns"
      :rows="rows"
      :loading="isPending"
      :error="error"
      :empty-title="t('receivables.empty.title')"
      :empty-description="t('receivables.empty.description')"
      @retry="refetch()"
    >
      <template #cell-customer="{ row }">
        <span class="grid min-w-48">
          <span class="font-medium">{{ row.customer_name }}</span>
          <span class="text-xs text-muted-foreground">
            {{ t('receivables.invoices', { n: formatInteger(row.invoices) }, row.invoices)
            }}<template v-if="row.overdue_invoices > 0">
              ·
              <span class="text-destructive">{{
                t('receivables.overdue', { n: formatInteger(row.overdue_invoices), days: formatInteger(row.oldest_days_past_due) }, row.overdue_invoices)
              }}</span></template
            >
          </span>
        </span>
      </template>
      <template #cell-total="{ row }">
        <span class="font-semibold whitespace-nowrap tabular-nums">{{ amount(row.total) }}</span>
      </template>
      <template v-for="bucket in BUCKETS" :key="bucket" #[`cell-${bucket}`]="{ row }">
        <span class="whitespace-nowrap tabular-nums" :class="bucket !== 'current' && !isZero(row[bucket]) ? 'text-destructive' : ''">{{
          amount(row[bucket])
        }}</span>
      </template>
      <template #cell-customer_credit="{ row }">
        <span class="whitespace-nowrap text-success tabular-nums">{{ amount(row.customer_credit) }}</span>
      </template>
      <template #cell-link="{ row }">
        <RouterLink
          :to="{ name: 'invoices', query: { contact: row.contact_id, customer: row.customer_name } }"
          class="inline-flex items-center gap-1 text-sm font-medium whitespace-nowrap text-primary hover:underline"
        >
          {{ t('receivables.viewInvoices') }}
          <ArrowUpRightIcon class="size-3.5" aria-hidden="true" />
        </RouterLink>
      </template>
      <template v-if="report" #footer>
        <TableRow class="hover:bg-transparent">
          <TableCell class="font-semibold">{{ t('receivables.totals') }}</TableCell>
          <TableCell class="text-right font-semibold whitespace-nowrap tabular-nums">{{ amount(report.totals.total) }}</TableCell>
          <TableCell v-for="bucket in BUCKETS" :key="bucket" class="text-right font-semibold whitespace-nowrap tabular-nums">{{
            amount(report.totals[bucket])
          }}</TableCell>
          <TableCell v-if="hasCredit" class="text-right font-semibold whitespace-nowrap tabular-nums">{{ amount(report.totals.customer_credit) }}</TableCell>
          <TableCell />
        </TableRow>
      </template>
    </DataTable>
    <p class="text-xs text-muted-foreground">{{ t('receivables.hint') }}</p>
  </div>
</template>
