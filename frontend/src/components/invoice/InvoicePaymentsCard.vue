<script setup lang="ts">
import type { Invoice } from '@/api/types'
import { WalletIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import FormSection from '@/components/FormSection.vue'
import InvoicePaymentBadges from '@/components/invoice/InvoicePaymentBadges.vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useReference } from '@/composables/useReference'
import { useSession } from '@/composables/useSession'
import { formatDate, formatMoney } from '@/lib/format'

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
const payments = computed(() => props.invoice.payments ?? [])
const canRecord = computed(() => can('record-payments') && owes.value && props.invoice.contact_id !== null)
</script>

<template>
  <FormSection :title="t('invoices.payments.title')" :description="t('invoices.payments.description')">
    <div class="grid gap-5">
      <div class="flex flex-wrap items-center gap-2">
        <InvoicePaymentBadges :invoice="invoice" />
        <Badge v-if="invoice.payment_status === 'unpaid' && owes" variant="outline">{{ t('invoices.paymentStatus.unpaid') }}</Badge>
        <span v-if="invoice.overdue" class="text-sm text-destructive">{{ t('invoices.payments.daysPastDue', { days: invoice.days_past_due ?? 0 }) }}</span>
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
        <div class="grid gap-0.5 rounded-lg p-3" :class="credit ? 'bg-emerald-50 dark:bg-emerald-950/40' : 'bg-muted/50'">
          <dt class="text-xs text-muted-foreground">{{ credit ? t('invoices.payments.customerCredit') : t('invoices.payments.balance') }}</dt>
          <dd class="font-semibold tabular-nums">{{ formatMoney(credit ? balance.slice(1) : balance) }}</dd>
        </div>
      </dl>
      <p class="-mt-2 text-xs text-muted-foreground">{{ credit ? t('invoices.payments.creditHint') : t('invoices.payments.balanceHint') }}</p>

      <div class="grid gap-2">
        <p class="text-sm font-medium">{{ t('invoices.payments.applied') }}</p>
        <p v-if="payments.length === 0" class="text-sm text-muted-foreground">{{ t('invoices.payments.none') }}</p>
        <div v-else class="-mx-6 overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow class="hover:bg-transparent">
                <TableHead class="pl-6">{{ t('payments.columns.number') }}</TableHead>
                <TableHead>{{ t('payments.columns.date') }}</TableHead>
                <TableHead>{{ t('payments.columns.means') }}</TableHead>
                <TableHead class="text-right">{{ t('payments.columns.amount') }}</TableHead>
                <TableHead class="pr-6">{{ t('payments.columns.status') }}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="payment in payments" :key="payment.payment_id">
                <TableCell class="pl-6 whitespace-nowrap">
                  <RouterLink :to="{ name: 'payment', params: { id: payment.payment_id } }" class="font-medium tabular-nums hover:underline">{{ payment.number }}</RouterLink>
                </TableCell>
                <TableCell class="whitespace-nowrap tabular-nums">{{ formatDate(payment.payment_date) }}</TableCell>
                <TableCell>{{ label('paymentMeans', payment.payment_means_code) }}</TableCell>
                <TableCell class="text-right whitespace-nowrap tabular-nums" :class="payment.status === 'released' ? 'text-muted-foreground line-through' : ''">{{ formatMoney(payment.amount) }}</TableCell>
                <TableCell class="pr-6">
                  <Badge :variant="payment.status === 'released' ? 'outline' : 'secondary'">{{ t(`invoices.payments.status.${payment.status}`) }}</Badge>
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>
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
