<script setup lang="ts">
import type { Invoice } from '@/api/types'
import type { DataTableColumn } from '@/components/data-table/types'
import { WalletIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from '@/components/data-table/DataTable.vue'
import FormSection from '@/components/FormSection.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { Button } from '@/components/ui/button'
import { useReference } from '@/composables/useReference'
import { useSession } from '@/composables/useSession'
import { formatDate, formatInteger, formatMoney } from '@/lib/format'

/**
 * Payments of an accepted invoice: its payment status, what was paid, what is left to collect
 * after its accepted notes and its payments (a negative balance is a credit in favor of the
 * customer), and the payments applied to it, voided ones included. Record payment opens a new
 * payment with this customer and this invoice chosen.
 */
const props = defineProps<{
  invoice: Invoice
}>()

const { t } = useI18n()
const { can } = useSession()
const { label } = useReference()

const balance = computed(() => props.invoice.receivable_amount ?? '0')
const credit = computed(() => balance.value.startsWith('-'))
const owes = computed(() => !credit.value && !/^0+(?:\.0+)?$/.test(balance.value))
const payments = computed(() => (props.invoice.payments ?? []).map((payment) => ({ ...payment, id: payment.payment_id })))

const columns = computed<DataTableColumn[]>(() => [
  { key: 'number', label: t('payments.columns.number') },
  { key: 'date', label: t('payments.columns.date') },
  { key: 'means', label: t('payments.columns.means') },
  { key: 'amount', label: t('payments.columns.amount'), align: 'right' },
  { key: 'status', label: t('payments.columns.status') },
])
const canRecord = computed(() => can('record-payments') && owes.value && props.invoice.contact_id !== null)
</script>

<template>
  <FormSection :title="t('invoices.payments.title')" :description="t('invoices.payments.description')">
    <div class="grid gap-5">
      <div class="flex flex-wrap items-center gap-2">
        <StatusBadge v-if="invoice.payment_status && (invoice.payment_status !== 'unpaid' || owes)" domain="invoicePayment" :status="invoice.payment_status" />
        <StatusBadge v-if="invoice.overdue" domain="invoicePayment" status="overdue" />
        <span v-if="invoice.overdue" class="text-sm text-destructive">{{
          t('invoices.payments.daysPastDue', { days: formatInteger(invoice.days_past_due ?? 0) }, invoice.days_past_due ?? 0)
        }}</span>
      </div>

      <dl class="grid gap-3 sm:grid-cols-3">
        <div class="grid gap-0.5 rounded-lg bg-muted/50 p-3">
          <dt class="text-xs text-muted-foreground">{{ t('invoices.payments.amountDue') }}</dt>
          <dd class="font-medium tabular-nums">{{ formatMoney(invoice.totals.amount_due) }}</dd>
        </div>
        <div class="grid gap-0.5 rounded-lg bg-muted/50 p-3">
          <dt class="text-xs text-muted-foreground">{{ t('invoices.payments.paid') }}</dt>
          <dd class="font-medium tabular-nums">{{ formatMoney(invoice.paid_amount ?? '0') }}</dd>
        </div>
        <div class="grid gap-0.5 rounded-lg p-3" :class="credit ? 'bg-success/10' : 'bg-muted/50'">
          <dt class="text-xs text-muted-foreground">{{ credit ? t('invoices.payments.customerCredit') : t('invoices.payments.balance') }}</dt>
          <dd class="font-semibold tabular-nums">{{ formatMoney(credit ? balance.slice(1) : balance) }}</dd>
        </div>
      </dl>
      <p class="-mt-2 text-xs text-muted-foreground">{{ credit ? t('invoices.payments.creditHint') : t('invoices.payments.balanceHint') }}</p>

      <div class="grid gap-2">
        <p class="text-sm font-medium">{{ t('invoices.payments.applied') }}</p>
        <DataTable :columns="columns" :rows="payments" :empty-title="t('invoices.payments.none')">
          <template #cell-number="{ row }">
            <RouterLink :to="{ name: 'payment', params: { id: row.payment_id } }" class="font-medium whitespace-nowrap tabular-nums hover:underline">{{
              row.number
            }}</RouterLink>
          </template>
          <template #cell-date="{ row }">
            <span class="whitespace-nowrap tabular-nums">{{ formatDate(row.payment_date) }}</span>
          </template>
          <template #cell-means="{ row }">
            {{ label('paymentMeans', row.payment_means_code) }}
          </template>
          <template #cell-amount="{ row }">
            <span class="whitespace-nowrap tabular-nums" :class="row.status === 'released' ? 'text-muted-foreground line-through' : ''">{{
              formatMoney(row.amount)
            }}</span>
          </template>
          <template #cell-status="{ row }">
            <StatusBadge domain="allocation" :status="row.status" />
          </template>
        </DataTable>
      </div>

      <div v-if="canRecord">
        <Button as-child>
          <RouterLink :to="{ name: 'payment-new', query: { contact: invoice.contact_id, invoice: invoice.id } }">
            <WalletIcon />
            {{ t('invoices.payments.record') }}
          </RouterLink>
        </Button>
      </div>
    </div>
  </FormSection>
</template>
