<script setup lang="ts">
import type { Payment, PaymentEvent } from '@/api/types'
import type { DataTableColumn } from '@/components/data-table/types'
import { BanIcon } from '@lucide/vue'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from '@/components/data-table/DataTable.vue'
import FormSection from '@/components/FormSection.vue'
import PageHeader from '@/components/PageHeader.vue'
import VoidPaymentDialog from '@/components/payment/VoidPaymentDialog.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { useReference } from '@/composables/useReference'
import { useSession } from '@/composables/useSession'
import { formatDate, formatDateTime, formatMoney, payloadText } from '@/lib/format'

/**
 * A payment: its data, the invoices it was applied to, its timeline, and Void payment for owners
 * and admins while it is recorded.
 */
const props = defineProps<{
  payment: Payment
}>()

const { t } = useI18n()
const { can } = useSession()
const { label } = useReference()

const voidOpen = ref(false)

const crumbs = computed(() => [{ label: t('nav.sales') }, { label: t('nav.payments'), to: { name: 'payments' } }, { label: props.payment.full_number }])

const allocationColumns = computed<DataTableColumn[]>(() => [
  { key: 'invoice', label: t('payments.editor.columns.invoice') },
  { key: 'date', label: t('payments.editor.columns.date') },
  { key: 'due', label: t('payments.editor.columns.due') },
  { key: 'amount', label: t('payments.view.applied'), align: 'right' },
  { key: 'balance_after', label: t('payments.view.balanceAfter'), align: 'right' },
])
const canVoid = computed(() => can('void-payments') && props.payment.status === 'recorded')

function eventLabel(event: PaymentEvent): string {
  const payload = event.payload ?? {}
  const invoices = Array.isArray(payload.invoices) ? payload.invoices.length : 0

  return event.type === 'voided'
    ? t('payments.view.events.voided', { count: invoices }, invoices)
    : t('payments.view.events.recorded', { amount: formatMoney(payloadText(payload.amount, '0')), count: invoices }, invoices)
}

function eventDetail(event: PaymentEvent): string | null {
  return event.type === 'voided' && typeof event.payload?.reason === 'string' ? event.payload.reason : null
}
</script>

<template>
  <div class="grid gap-6">
    <PageHeader
      :title="payment.full_number"
      :description="t('payments.view.subtitle', { date: formatDate(payment.payment_date), customer: payment.customer_name })"
      :breadcrumbs="crumbs"
    >
      <template #badge>
        <StatusBadge domain="payment" :status="payment.status" />
      </template>

      <Button v-if="canVoid" variant="outline" @click="voidOpen = true">
        <BanIcon />
        {{ t('payments.void.open') }}
      </Button>
    </PageHeader>

    <Alert v-if="payment.status === 'voided'" variant="destructive">
      <BanIcon />
      <AlertDescription>
        {{ t('payments.view.voided', { date: formatDateTime(payment.voided_at), name: payment.voided_by?.name ?? '—' }) }}
        <span class="block font-medium">{{ payment.void_reason }}</span>
      </AlertDescription>
    </Alert>

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
      <div class="grid min-w-0 gap-6">
        <FormSection :title="t('payments.view.details')">
          <dl class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-0.5 sm:col-span-2">
              <dt class="text-xs text-muted-foreground">{{ t('payments.columns.customer') }}</dt>
              <dd>
                <RouterLink :to="{ name: 'contact-edit', params: { id: payment.contact_id } }" class="font-medium hover:underline">{{
                  payment.customer_name
                }}</RouterLink>
                <span v-if="payment.customer_identification" class="ml-2 text-muted-foreground tabular-nums">{{ payment.customer_identification }}</span>
              </dd>
            </div>
            <div class="grid gap-0.5">
              <dt class="text-xs text-muted-foreground">{{ t('payments.columns.date') }}</dt>
              <dd class="tabular-nums">{{ formatDate(payment.payment_date) }}</dd>
            </div>
            <div class="grid gap-0.5">
              <dt class="text-xs text-muted-foreground">{{ t('payments.columns.means') }}</dt>
              <dd>{{ label('paymentMeans', payment.payment_means_code) }}</dd>
            </div>
            <div class="grid gap-0.5">
              <dt class="text-xs text-muted-foreground">{{ t('payments.editor.reference') }}</dt>
              <dd class="tabular-nums">{{ payment.reference ?? '—' }}</dd>
            </div>
            <div class="grid gap-0.5">
              <dt class="text-xs text-muted-foreground">{{ t('payments.view.recordedBy') }}</dt>
              <dd>{{ payment.created_by?.name ?? '—' }}</dd>
            </div>
            <div v-if="payment.notes" class="grid gap-0.5 sm:col-span-3">
              <dt class="text-xs text-muted-foreground">{{ t('payments.editor.notes') }}</dt>
              <dd class="whitespace-pre-line">{{ payment.notes }}</dd>
            </div>
          </dl>
        </FormSection>

        <FormSection
          :title="t('payments.view.invoices')"
          :description="payment.status === 'voided' ? t('payments.view.invoicesVoided') : t('payments.view.invoicesDescription')"
        >
          <DataTable :columns="allocationColumns" :rows="payment.allocations ?? []" :empty-title="t('payments.view.noInvoices')">
            <template #cell-invoice="{ row }">
              <RouterLink :to="{ name: 'invoice', params: { id: row.document_id } }" class="font-medium whitespace-nowrap tabular-nums hover:underline">{{
                row.document_number
              }}</RouterLink>
            </template>
            <template #cell-date="{ row }">
              <span class="whitespace-nowrap tabular-nums">{{ formatDate(row.document_issue_date) || '—' }}</span>
            </template>
            <template #cell-due="{ row }">
              <span class="whitespace-nowrap tabular-nums">{{ formatDate(row.document_due_date ?? row.document_issue_date) || '—' }}</span>
            </template>
            <template #cell-amount="{ row }">
              <span class="whitespace-nowrap tabular-nums" :class="payment.status === 'voided' ? 'text-muted-foreground line-through' : ''">{{
                formatMoney(row.amount)
              }}</span>
            </template>
            <template #cell-balance_after="{ row }">
              <span class="whitespace-nowrap text-muted-foreground tabular-nums">{{ formatMoney(row.balance_after) }}</span>
            </template>
          </DataTable>
        </FormSection>

        <FormSection :title="t('invoices.view.timeline')">
          <ol class="grid gap-4">
            <li v-for="event in payment.events ?? []" :key="event.id" class="flex gap-3">
              <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="event.type === 'voided' ? 'bg-destructive' : 'bg-success'" aria-hidden="true" />
              <div class="grid min-w-0 gap-0.5">
                <p class="font-medium">{{ eventLabel(event) }}</p>
                <p v-if="eventDetail(event)" class="text-xs break-words text-muted-foreground">{{ eventDetail(event) }}</p>
                <p class="text-xs text-muted-foreground">
                  {{ formatDateTime(event.occurred_at) }}
                  <template v-if="event.actor"
                    >· {{ t(event.actor.type === 'api_key' ? 'invoices.view.byApiKey' : 'invoices.view.by', { name: event.actor.name }) }}</template
                  >
                </p>
              </div>
            </li>
          </ol>
        </FormSection>
      </div>

      <div class="lg:sticky lg:top-20">
        <div class="grid gap-2 rounded-xl border p-5">
          <p class="text-sm font-medium">{{ t('payments.view.amount') }}</p>
          <p class="text-2xl font-semibold tabular-nums" :class="payment.status === 'voided' ? 'text-muted-foreground line-through' : ''">
            {{ formatMoney(payment.amount) }}
          </p>
          <p class="text-xs text-muted-foreground">
            {{ t('payments.editor.totalHint', { count: payment.allocations?.length ?? 0 }, payment.allocations?.length ?? 0) }}
          </p>
        </div>
      </div>
    </div>

    <VoidPaymentDialog v-model:open="voidOpen" :payment="payment" />
  </div>
</template>
