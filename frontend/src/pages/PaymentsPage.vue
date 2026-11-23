<script setup lang="ts">
import type { Payment } from '@/api/types'
import type { DataTableColumn, RowAction } from '@/components/data-table/types'
import type { Option } from '@/lib/options'
import { EyeIcon, PlusIcon } from '@lucide/vue'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { paymentsApi } from '@/api/resources'
import DataTable from '@/components/data-table/DataTable.vue'
import RowActions from '@/components/data-table/RowActions.vue'
import SelectInput from '@/components/SelectInput.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { Button } from '@/components/ui/button'
import { useListQuery } from '@/composables/useListQuery'
import { useReference } from '@/composables/useReference'
import { useSession } from '@/composables/useSession'
import { formatDate, formatMoney } from '@/lib/format'
import { statusKey } from '@/lib/status'

/** Payments received, newest first, with search, status and payment means filters. */
const ALL = 'all'

const { t } = useI18n()
const router = useRouter()
const { can } = useSession()
const { label, paymentMeans } = useReference()

const status = ref<string | null>(ALL)
const means = ref<string | null>(ALL)

const statusOptions = computed<Option[]>(() => [
  { value: ALL, label: t('payments.filters.allStatuses') },
  ...['recorded', 'voided'].map((value) => ({ value, label: t(statusKey('payment', value)) })),
])

const meansOptions = computed<Option[]>(() => [{ value: ALL, label: t('payments.filters.allMeans') }, ...paymentMeans.value])

function filterValue(value: string | null): string | undefined {
  return value === ALL || value === null ? undefined : value
}

const { page, search, rows, meta, isLoading, error, refetch } = useListQuery('payments', (params) => paymentsApi.list(params), {
  filters: () => ({ status: filterValue(status.value), payment_means_code: filterValue(means.value) }),
})

const columns = computed<DataTableColumn[]>(() => [
  { key: 'number', label: t('payments.columns.number') },
  { key: 'date', label: t('payments.columns.date') },
  { key: 'customer', label: t('payments.columns.customer') },
  { key: 'means', label: t('payments.columns.means') },
  { key: 'amount', label: t('payments.columns.amount'), align: 'right' },
  { key: 'status', label: t('payments.columns.status') },
])

function actions(payment: Payment): RowAction[] {
  return [{ label: t('common.view'), icon: EyeIcon, onSelect: () => void router.push({ name: 'payment', params: { id: payment.id } }) }]
}
</script>

<template>
  <DataTable
    v-model:search="search"
    v-model:page="page"
    :columns="columns"
    :rows="rows"
    :meta="meta"
    :loading="isLoading"
    :error="error"
    :search-placeholder="t('payments.searchPlaceholder')"
    :empty-title="t('payments.empty.title')"
    :empty-description="t('payments.empty.description')"
    actions
    @retry="refetch"
  >
    <template #toolbar>
      <!-- Each filter is as wide as its longest label needs, never narrower than before: the Spanish labels are longer. -->
      <div class="flex flex-wrap gap-2">
        <div class="min-w-44">
          <SelectInput v-model="status" :options="statusOptions" :placeholder="t('payments.filters.status')" :label="t('payments.filters.status')" />
        </div>
        <div class="min-w-52">
          <SelectInput v-model="means" :options="meansOptions" :placeholder="t('payments.filters.means')" :label="t('payments.filters.means')" />
        </div>
      </div>
    </template>
    <template #cell-number="{ row }">
      <RouterLink :to="{ name: 'payment', params: { id: row.id } }" class="font-medium whitespace-nowrap text-foreground tabular-nums hover:underline">
        {{ row.full_number }}
      </RouterLink>
    </template>
    <template #cell-date="{ row }">
      <span class="whitespace-nowrap tabular-nums">{{ formatDate(row.payment_date) }}</span>
    </template>
    <template #cell-customer="{ row }">
      <span class="block max-w-64 truncate">{{ row.customer_name }}</span>
    </template>
    <template #cell-means="{ row }">
      <span class="whitespace-nowrap text-muted-foreground">{{ label('paymentMeans', row.payment_means_code) }}</span>
    </template>
    <template #cell-amount="{ row }">
      <span class="whitespace-nowrap tabular-nums" :class="row.status === 'voided' ? 'text-muted-foreground line-through' : ''">{{
        formatMoney(row.amount)
      }}</span>
    </template>
    <template #cell-status="{ row }">
      <StatusBadge domain="payment" :status="row.status" />
    </template>
    <template #actions="{ row }">
      <RowActions :actions="actions(row)" />
    </template>
    <template v-if="can('record-payments')" #empty-action>
      <Button variant="outline" size="sm" as-child>
        <RouterLink :to="{ name: 'payment-new' }">
          <PlusIcon />
          {{ t('payments.new') }}
        </RouterLink>
      </Button>
    </template>
  </DataTable>
</template>
