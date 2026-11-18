<script setup lang="ts">
import type { Invoice } from '@/api/types'
import type { DataTableColumn, RowAction } from '@/components/data-table/types'
import type { Option } from '@/lib/options'
import { EyeIcon, PencilIcon, PlusIcon } from '@lucide/vue'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { invoicesApi } from '@/api/resources'
import DataTable from '@/components/data-table/DataTable.vue'
import RowActions from '@/components/data-table/RowActions.vue'
import DeliveryStatusBadge from '@/components/invoice/DeliveryStatusBadge.vue'
import DocumentStatusBadge from '@/components/invoice/DocumentStatusBadge.vue'
import ElectronicStatusBadge from '@/components/invoice/ElectronicStatusBadge.vue'
import PageHeader from '@/components/PageHeader.vue'
import SelectInput from '@/components/SelectInput.vue'
import { Button } from '@/components/ui/button'
import { useListQuery } from '@/composables/useListQuery'
import { useSession } from '@/composables/useSession'
import { formatDate, formatMoney } from '@/lib/format'

const ALL = 'all'

const { t } = useI18n()
const router = useRouter()
const { can } = useSession()

const canManage = computed(() => can('manage-documents'))

const status = ref<string | null>(ALL)
const electronic = ref<string | null>(ALL)

const statusOptions = computed<Option[]>(() => [
  { value: ALL, label: t('invoices.filters.all') },
  { value: 'draft', label: t('invoices.status.draft') },
  { value: 'issued', label: t('invoices.status.issued') },
])

// Processing groups pending, generated, signed and sending; the API filters by it too.
const electronicOptions = computed<Option[]>(() => [
  { value: ALL, label: t('invoices.filters.allElectronic') },
  ...['processing', 'accepted', 'rejected', 'failed'].map((value) => ({ value, label: t(`invoices.electronic.${value}`) })),
])

function filterValue(value: string | null): string | undefined {
  return value === ALL || value === null ? undefined : value
}

const { page, search, rows, meta, isLoading } = useListQuery('invoices', invoicesApi.list, {
  filters: () => ({ status: filterValue(status.value), electronic_status: filterValue(electronic.value) }),
  // While an invoice on screen is still processing or being delivered, the list refreshes every 3 seconds.
  refetchInterval: (items: Invoice[]) => (items.some((item) => item.electronic && (!item.electronic.final
    || (item.electronic.status === 'accepted' && item.delivery?.final !== true))) ? 3000 : false),
})

const columns = computed<DataTableColumn[]>(() => [
  { key: 'number', label: t('invoices.columns.number') },
  { key: 'customer', label: t('invoices.columns.customer') },
  { key: 'issue_date', label: t('invoices.columns.issueDate') },
  { key: 'due_date', label: t('invoices.columns.dueDate') },
  { key: 'total', label: t('invoices.columns.total'), align: 'right' },
  { key: 'status', label: t('invoices.columns.status') },
  { key: 'electronic', label: t('invoices.columns.electronic') },
  { key: 'delivery', label: t('invoices.columns.delivery') },
])

function actions(invoice: Invoice): RowAction[] {
  const open = () => router.push({ name: 'invoice', params: { id: invoice.id } })

  return invoice.status === 'draft' && canManage.value
    ? [{ label: t('common.edit'), icon: PencilIcon, onSelect: open }]
    : [{ label: t('common.view'), icon: EyeIcon, onSelect: open }]
}
</script>

<template>
  <div class="grid gap-6">
    <PageHeader :title="t('invoices.title')" :description="t('invoices.description')">
      <Button v-if="canManage" as-child>
        <RouterLink :to="{ name: 'invoice-new' }">
          <PlusIcon />
          {{ t('invoices.new') }}
        </RouterLink>
      </Button>
    </PageHeader>

    <DataTable
      v-model:search="search"
      v-model:page="page"
      :columns="columns"
      :rows="rows"
      :meta="meta"
      :loading="isLoading"
      :search-placeholder="t('invoices.searchPlaceholder')"
      :empty-title="t('invoices.empty.title')"
      :empty-description="t('invoices.empty.description')"
      actions
    >
      <template #toolbar>
        <div class="flex flex-wrap gap-2">
          <div class="w-44">
            <SelectInput v-model="status" :options="statusOptions" :placeholder="t('invoices.filters.status')" />
          </div>
          <div class="w-52">
            <SelectInput v-model="electronic" :options="electronicOptions" :placeholder="t('invoices.filters.electronic')" />
          </div>
        </div>
      </template>
      <template #cell-number="{ row }">
        <RouterLink :to="{ name: 'invoice', params: { id: row.id } }" class="font-medium whitespace-nowrap text-foreground tabular-nums hover:underline">
          {{ row.full_number ?? t('invoices.draft') }}
        </RouterLink>
      </template>
      <template #cell-customer="{ row }">
        <span class="block max-w-64 truncate">{{ row.customer_name ?? '—' }}</span>
      </template>
      <template #cell-issue_date="{ row }">
        <span class="whitespace-nowrap tabular-nums">{{ formatDate(row.issue_date) || '—' }}</span>
      </template>
      <template #cell-due_date="{ row }">
        <span class="whitespace-nowrap text-muted-foreground tabular-nums">{{ formatDate(row.due_date) || '—' }}</span>
      </template>
      <template #cell-total="{ row }">
        <span class="whitespace-nowrap tabular-nums">{{ formatMoney(row.totals.payable_amount) || '$ 0' }}</span>
      </template>
      <template #cell-status="{ row }">
        <DocumentStatusBadge :status="row.status" />
      </template>
      <template #cell-electronic="{ row }">
        <ElectronicStatusBadge v-if="row.electronic" :status="row.electronic.status" />
        <span v-else class="text-muted-foreground">—</span>
      </template>
      <template #cell-delivery="{ row }">
        <DeliveryStatusBadge v-if="row.delivery && row.electronic?.status === 'accepted'" :status="row.delivery.status" compact />
        <span v-else class="text-muted-foreground">—</span>
      </template>
      <template #actions="{ row }">
        <RowActions :actions="actions(row)" />
      </template>
      <template v-if="canManage" #empty-action>
        <Button variant="outline" size="sm" as-child>
          <RouterLink :to="{ name: 'invoice-new' }">
            <PlusIcon />
            {{ t('invoices.new') }}
          </RouterLink>
        </Button>
      </template>
    </DataTable>
  </div>
</template>
