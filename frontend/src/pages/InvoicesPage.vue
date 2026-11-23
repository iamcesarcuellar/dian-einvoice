<script setup lang="ts">
import type { DocumentType, Invoice } from '@/api/types'
import type { DataTableColumn, RowAction } from '@/components/data-table/types'
import type { Option } from '@/lib/options'
import { EyeIcon, PencilIcon, PlusIcon, XIcon } from '@lucide/vue'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { documentsApi } from '@/api/resources'
import DataTable from '@/components/data-table/DataTable.vue'
import RowActions from '@/components/data-table/RowActions.vue'
import SelectInput from '@/components/SelectInput.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { Button } from '@/components/ui/button'
import { useListQuery } from '@/composables/useListQuery'
import { useReference } from '@/composables/useReference'
import { useSession } from '@/composables/useSession'
import { DOCUMENT_RESOURCES, DOCUMENT_ROUTES } from '@/lib/documents'
import { formatDate, formatMoney } from '@/lib/format'
import { electronicGroup, statusKey } from '@/lib/status'

/**
 * The list of one tab of the Invoices section: invoices, credit notes or debit notes, with search
 * and filters. Invoices show what is left to collect, their payment and balance status, and can be
 * filtered by payment status, overdue, or one customer (?contact=, from the receivables); notes
 * show the invoice they correct and their concept.
 */
const props = withDefaults(
  defineProps<{
    kind?: DocumentType
  }>(),
  {
    kind: 'invoice',
  },
)

const ALL = 'all'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { can } = useSession()
const { label } = useReference()

const canManage = computed(() => can('manage-documents'))
const isNote = computed(() => props.kind !== 'invoice')
const routes = computed(() => DOCUMENT_ROUTES[props.kind])

const status = ref<string | null>(ALL)
const electronic = ref<string | null>(ALL)
const balance = ref<string | null>(ALL)
const payment = ref<string | null>(ALL)

/** One customer, from the receivables: ?contact= and its name in ?customer=. */
const contactId = computed(() => (typeof route.query.contact === 'string' ? route.query.contact : null))
const contactName = computed(() => (typeof route.query.customer === 'string' ? route.query.customer : null))

const statusOptions = computed<Option[]>(() => [
  { value: ALL, label: t('invoices.filters.all') },
  ...['draft', 'issued'].map((value) => ({ value, label: t(statusKey('document', value)) })),
])

// Processing groups pending, generated, signed and sending; the API filters by it too.
const electronicOptions = computed<Option[]>(() => [
  { value: ALL, label: t('invoices.filters.allElectronic') },
  ...['processing', 'accepted', 'rejected', 'failed'].map((value) => ({ value, label: t(statusKey('electronic', value)) })),
])

const balanceOptions = computed<Option[]>(() => [
  { value: ALL, label: t('notes.filters.allBalance') },
  ...['open', 'partially_credited', 'credited', 'voided'].map((value) => ({ value, label: t(statusKey('balance', value)) })),
])

const paymentOptions = computed<Option[]>(() => [
  { value: ALL, label: t('invoices.filters.allPayment') },
  ...['unpaid', 'partially_paid', 'paid', 'overdue'].map((value) => ({ value, label: t(statusKey('invoicePayment', value)) })),
])

function filterValue(value: string | null): string | undefined {
  return value === ALL || value === null ? undefined : value
}

const { page, search, rows, meta, isLoading, error, refetch } = useListQuery(
  DOCUMENT_RESOURCES[props.kind],
  (params) => documentsApi(props.kind).list(params),
  {
    filters: () => ({
      status: filterValue(status.value),
      electronic_status: filterValue(electronic.value),
      balance_status: isNote.value ? undefined : filterValue(balance.value),
      payment_status: isNote.value || payment.value === 'overdue' ? undefined : filterValue(payment.value),
      overdue: !isNote.value && payment.value === 'overdue' ? 1 : undefined,
      contact_id: contactId.value ?? undefined,
    }),
    // While a document on screen is still processing or being delivered, the list refreshes every 3 seconds.
    refetchInterval: (items: Invoice[]) =>
      items.some((item) => item.electronic && (!item.electronic.final || (item.electronic.status === 'accepted' && item.delivery?.final !== true)))
        ? 3000
        : false,
  },
)

// The customer takes the width the others leave: every column keeps a narrower padding, and the delivery shows as an
// icon next to the electronic status, so a name such as "Distribuidora Cordillera Plata SAS" fits at 1440 px.
const TIGHT = 'px-1.5'

const columns = computed<DataTableColumn[]>(() => [
  { key: 'number', label: t('invoices.columns.number'), class: TIGHT },
  ...(isNote.value ? [{ key: 'related', label: t('notes.columns.invoice'), class: TIGHT }] : []),
  { key: 'customer', label: t('invoices.columns.customer'), truncate: true, class: TIGHT },
  { key: 'issue_date', label: t('invoices.columns.issueDate'), class: TIGHT },
  isNote.value
    ? { key: 'concept', label: t('notes.columns.concept'), truncate: true, class: TIGHT }
    : { key: 'due_date', label: t('invoices.columns.dueDate'), class: TIGHT },
  { key: 'total', label: t('invoices.columns.total'), align: 'right', class: TIGHT },
  ...(isNote.value ? [] : [{ key: 'balance', label: t('invoices.columns.balance'), align: 'right' as const, class: TIGHT }]),
  { key: 'status', label: t('invoices.columns.status'), class: TIGHT },
  { key: 'electronic', label: t('invoices.columns.electronic'), class: TIGHT },
])

function conceptOf(document: Invoice): string {
  return document.reason_code ? label(document.type === 'credit_note' ? 'creditNoteConcepts' : 'debitNoteConcepts', document.reason_code) : '—'
}

function actions(document: Invoice): RowAction[] {
  const open = () => void router.push({ name: routes.value.detail, params: { id: document.id } })

  return document.status === 'draft' && canManage.value
    ? [{ label: t('common.edit'), icon: PencilIcon, onSelect: open }]
    : [{ label: t('common.view'), icon: EyeIcon, onSelect: open }]
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
    :search-placeholder="isNote ? t('notes.searchPlaceholder') : t('invoices.searchPlaceholder')"
    :empty-title="isNote ? t(`notes.empty.title.${kind}`) : t('invoices.empty.title')"
    :empty-description="isNote ? t('notes.empty.description') : t('invoices.empty.description')"
    actions
    @retry="refetch"
  >
    <template #toolbar>
      <!-- Each filter is as wide as its longest label needs, never narrower than before: the Spanish labels are longer. -->
      <div class="flex flex-wrap gap-2">
        <div class="min-w-44">
          <SelectInput v-model="status" :options="statusOptions" :placeholder="t('invoices.filters.status')" :label="t('invoices.filters.status')" />
        </div>
        <div class="min-w-52">
          <SelectInput
            v-model="electronic"
            :options="electronicOptions"
            :placeholder="t('invoices.filters.electronic')"
            :label="t('invoices.filters.electronic')"
          />
        </div>
        <div v-if="!isNote" class="min-w-48">
          <SelectInput v-model="balance" :options="balanceOptions" :placeholder="t('notes.filters.balance')" :label="t('notes.filters.balance')" />
        </div>
        <div v-if="!isNote" class="min-w-48">
          <SelectInput v-model="payment" :options="paymentOptions" :placeholder="t('invoices.filters.payment')" :label="t('invoices.filters.payment')" />
        </div>
        <RouterLink
          v-if="contactId"
          :to="{ name: 'invoices' }"
          class="inline-flex h-9 items-center gap-1.5 rounded-md border px-3 text-sm hover:bg-muted"
          :aria-label="t('invoices.filters.clearCustomer')"
        >
          {{ t('invoices.filters.customer', { name: contactName ?? '…' }) }}
          <XIcon class="size-3.5" />
        </RouterLink>
      </div>
    </template>
    <template #cell-number="{ row }">
      <RouterLink :to="{ name: routes.detail, params: { id: row.id } }" class="font-medium whitespace-nowrap text-foreground tabular-nums hover:underline">
        {{ row.full_number ?? t('invoices.draft') }}
      </RouterLink>
    </template>
    <template #cell-related="{ row }">
      <RouterLink
        v-if="row.related_document"
        :to="{ name: 'invoice', params: { id: row.related_document.id } }"
        class="whitespace-nowrap tabular-nums hover:underline"
      >
        {{ row.related_document.full_number ?? '—' }}
      </RouterLink>
      <span v-else class="text-muted-foreground">—</span>
    </template>
    <template #cell-customer="{ row }">
      <span class="block truncate" :title="row.customer_name ?? undefined">{{ row.customer_name ?? '—' }}</span>
    </template>
    <template #cell-issue_date="{ row }">
      <span class="whitespace-nowrap tabular-nums">{{ formatDate(row.issue_date) || '—' }}</span>
    </template>
    <template #cell-due_date="{ row }">
      <span class="whitespace-nowrap text-muted-foreground tabular-nums">{{ formatDate(row.due_date) || '—' }}</span>
    </template>
    <template #cell-concept="{ row }">
      <span class="block truncate text-muted-foreground" :title="conceptOf(row)">{{ conceptOf(row) }}</span>
    </template>
    <template #cell-total="{ row }">
      <span class="whitespace-nowrap tabular-nums">{{ formatMoney(row.totals.payable_amount || '0') }}</span>
    </template>
    <template #cell-balance="{ row }">
      <template v-if="row.receivable_amount !== null && row.electronic?.status === 'accepted'">
        <!-- A credit in favor of the customer: the amount, and what it is under it. -->
        <span v-if="row.receivable_amount.startsWith('-')" class="grid justify-items-end text-success">
          <span class="whitespace-nowrap tabular-nums">{{ formatMoney(row.receivable_amount.slice(1)) }}</span>
          <span class="text-xs">{{ t('invoices.payments.customerCredit') }}</span>
        </span>
        <span v-else class="whitespace-nowrap tabular-nums">{{ formatMoney(row.receivable_amount) }}</span>
      </template>
      <span v-else class="text-muted-foreground">—</span>
    </template>
    <template #cell-status="{ row }">
      <!-- Badges wrap past 9 rem, so a row with three of them takes two lines instead of pushing the other columns. -->
      <span class="inline-flex max-w-36 flex-wrap gap-1">
        <StatusBadge domain="document" :status="row.status" />
        <StatusBadge
          v-if="row.balance_status === 'partially_credited' || row.balance_status === 'credited' || row.balance_status === 'voided'"
          domain="balance"
          :status="row.balance_status"
        />
        <template v-if="!isNote && row.electronic?.status === 'accepted'">
          <StatusBadge v-if="row.payment_status === 'paid' || row.payment_status === 'partially_paid'" domain="invoicePayment" :status="row.payment_status" />
          <StatusBadge v-if="row.overdue" domain="invoicePayment" status="overdue" />
        </template>
      </span>
    </template>
    <template #cell-electronic="{ row }">
      <!-- The electronic status, and once accepted the delivery to the customer as an icon with its own hint. -->
      <span v-if="row.electronic" class="inline-flex items-center gap-1.5">
        <StatusBadge domain="electronic" :status="electronicGroup(row.electronic.status)" :hint="t('invoices.electronic.bySimulator')" />
        <StatusBadge
          v-if="row.delivery && row.electronic.status === 'accepted'"
          domain="delivery"
          :status="row.delivery.status ?? 'waiting'"
          :hint="t(`invoices.delivery.statusTitle.${row.delivery.status ?? 'waiting'}`)"
          compact
        />
      </span>
      <span v-else class="text-muted-foreground">—</span>
    </template>
    <template #actions="{ row }">
      <RowActions :actions="actions(row)" />
    </template>
    <template v-if="canManage" #empty-action>
      <Button variant="outline" size="sm" as-child>
        <RouterLink :to="{ name: routes.create }">
          <PlusIcon />
          {{ t(`notes.new.${kind}`) }}
        </RouterLink>
      </Button>
    </template>
  </DataTable>
</template>
