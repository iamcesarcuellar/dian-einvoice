<script setup lang="ts">
import type { Contact, OpenInvoice, PaymentInput } from '@/api/types'
import { useMutation, useQuery } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { fieldErrors } from '@/api/client'
import { contactsApi, paymentsApi } from '@/api/resources'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import FormSection from '@/components/FormSection.vue'
import MoneyInput from '@/components/MoneyInput.vue'
import PageHeader from '@/components/PageHeader.vue'
import SearchSelect from '@/components/SearchSelect.vue'
import SelectInput from '@/components/SelectInput.vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { useReference } from '@/composables/useReference'
import { ALL_DOCUMENT_RESOURCES } from '@/lib/documents'
import { formatDate, formatMoney, todayInColombia } from '@/lib/format'
import { fromCents, toCents } from '@/lib/money'
import { invalidateTenant, tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * A new payment: the customer, then its open invoices, each with the amount to apply and Pay in
 * full. The total of the payment is the sum applied, live and exact (integer cents); nothing can be
 * applied above the balance of an invoice. ?contact= and ?invoice= start with that customer and
 * that invoice paid in full. The API checks every amount again with the invoices locked.
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const { paymentMeans } = useReference()

const companyId = computed(() => session.currentCompanyId)

const contact = ref<Contact | null>(null)
const paymentDate = ref(todayInColombia())
const means = ref<string | null>('47')
const reference = ref('')
const notes = ref('')
const amounts = ref<Record<string, string>>({})

const contactsKey = computed(() => tenantKey(companyId.value, 'contacts', 'search'))

async function searchContacts(term: string): Promise<Contact[]> {
  return (await contactsApi.list({ search: term || undefined, per_page: 20 })).data
}

// ?contact= starts with that customer.
const presetContactId = typeof route.query.contact === 'string' ? route.query.contact : null
const presetInvoiceId = typeof route.query.invoice === 'string' ? route.query.invoice : null

if (presetContactId) {
  void contactsApi.get(presetContactId).then((found) => {
    if (contact.value === null) {
      contact.value = found
    }
  })
}

const { data: open, isFetching } = useQuery({
  queryKey: computed(() => tenantKey(companyId.value, 'open-invoices', contact.value?.id ?? null)),
  queryFn: () => paymentsApi.openInvoices(contact.value?.id as string),
  enabled: computed(() => contact.value !== null),
  staleTime: 0,
})

const invoices = computed<OpenInvoice[]>(() => open.value?.data ?? [])
const credits = computed(() => open.value?.meta.credits ?? [])

// Another customer starts again; ?invoice= is paid in full once its invoices arrive.
watch(() => contact.value?.id, () => {
  amounts.value = {}
})

watch(invoices, (list) => {
  const preset = list.find((invoice) => invoice.id === presetInvoiceId)

  if (preset && amounts.value[preset.id] === undefined) {
    amounts.value = { ...amounts.value, [preset.id]: preset.balance }
  }
})

function payInFull(invoice: OpenInvoice): void {
  amounts.value = { ...amounts.value, [invoice.id]: invoice.balance }
}

/** The problem with the amount of a row, checked before the API checks it again. */
function rowError(invoice: OpenInvoice): string | null {
  const value = (amounts.value[invoice.id] ?? '').trim()

  if (value === '') {
    return null
  }

  const cents = toCents(value)

  if (cents === null || cents < 0n) {
    return t('payments.editor.invalidAmount')
  }

  const balance = toCents(invoice.balance) ?? 0n

  return cents > balance ? t('payments.editor.aboveBalance', { balance: formatMoney(invoice.balance) }) : null
}

const applied = computed(() => invoices.value
  .map((invoice) => ({ invoice, cents: toCents((amounts.value[invoice.id] ?? '').trim()) }))
  .filter((row): row is { invoice: OpenInvoice, cents: bigint } => row.cents !== null && row.cents > 0n))

const total = computed(() => applied.value.reduce((sum, row) => sum + row.cents, 0n))
const hasErrors = computed(() => invoices.value.some((invoice) => rowError(invoice) !== null))
const ready = computed(() => contact.value !== null && total.value > 0n && !hasErrors.value && means.value !== null && paymentDate.value !== '')

const recording = useMutation({
  mutationFn: () => {
    const input: PaymentInput = {
      contact_id: contact.value?.id as string,
      payment_date: paymentDate.value,
      payment_means_code: means.value as string,
      reference: reference.value.trim() || null,
      notes: notes.value.trim() || null,
      amount: fromCents(total.value),
      allocations: applied.value.map((row) => ({ document_id: row.invoice.id, amount: fromCents(row.cents) })),
    }

    return paymentsApi.create(input)
  },
  onSuccess: async (payment) => {
    await invalidateTenant('payments', 'receivables', 'open-invoices', ...ALL_DOCUMENT_RESOURCES)
    await router.replace({ name: 'payment', params: { id: payment.id } })
  },
})

const errors = computed(() => fieldErrors(recording.error.value))

/** The API answers line by line in the order the allocations were sent. */
function apiRowError(invoice: OpenInvoice): string | undefined {
  const index = applied.value.findIndex((row) => row.invoice.id === invoice.id)

  return index === -1 ? undefined : (errors.value[`allocations.${index}.amount`]?.[0] ?? errors.value[`allocations.${index}.document_id`]?.[0])
}
</script>

<template>
  <div class="grid gap-6">
    <PageHeader :title="t('payments.editor.title')" :description="t('payments.editor.description')" :back="{ name: 'payments' }">
      <Button :disabled="!ready || recording.isPending.value" @click="recording.mutate()">
        {{ recording.isPending.value ? t('payments.editor.recording') : t('payments.editor.record', { amount: formatMoney(fromCents(total)) }) }}
      </Button>
    </PageHeader>

    <FormError :error="recording.error.value" />

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
      <div class="grid min-w-0 gap-6">
        <FormSection :title="t('payments.editor.customer')" :description="t('payments.editor.customerDescription')">
          <FormField id="payment-customer" :label="t('payments.editor.customer')" :error="errors.contact_id?.[0]">
            <SearchSelect
              id="payment-customer"
              v-model="contact"
              :query-key="contactsKey"
              :search="searchContacts"
              by="id"
              :label="(option) => option.legal_name"
              :placeholder="t('payments.editor.customerPlaceholder')"
              :empty-text="t('payments.editor.noCustomers')"
              :invalid="!!errors.contact_id"
              list-class="min-w-80"
            >
              <template #option="{ option }">
                <span class="grid min-w-0">
                  <span class="truncate">{{ option.legal_name }}</span>
                  <span class="text-xs text-muted-foreground tabular-nums">{{ option.identification_number }}</span>
                </span>
              </template>
            </SearchSelect>
          </FormField>
        </FormSection>

        <FormSection :title="t('payments.editor.invoices')" :description="t('payments.editor.invoicesDescription')">
          <p v-if="contact === null" class="text-sm text-muted-foreground">{{ t('payments.editor.chooseCustomer') }}</p>
          <p v-else-if="isFetching && invoices.length === 0" class="text-sm text-muted-foreground">{{ t('common.loading') }}</p>
          <p v-else-if="invoices.length === 0" class="text-sm text-muted-foreground">{{ t('payments.editor.noInvoices') }}</p>
          <div v-else class="grid gap-3">
            <p v-if="errors.allocations" class="text-xs text-destructive">{{ errors.allocations[0] }}</p>
            <div class="-mx-6 overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow class="hover:bg-transparent">
                    <TableHead class="pl-6">{{ t('payments.editor.columns.invoice') }}</TableHead>
                    <TableHead>{{ t('payments.editor.columns.date') }}</TableHead>
                    <TableHead>{{ t('payments.editor.columns.due') }}</TableHead>
                    <TableHead class="text-right">{{ t('payments.editor.columns.balance') }}</TableHead>
                    <TableHead class="w-44">{{ t('payments.editor.columns.apply') }}</TableHead>
                    <TableHead class="pr-6" />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  <TableRow v-for="invoice in invoices" :key="invoice.id">
                    <TableCell class="pl-6 whitespace-nowrap">
                      <RouterLink :to="{ name: 'invoice', params: { id: invoice.id } }" class="font-medium tabular-nums hover:underline">{{ invoice.number }}</RouterLink>
                      <Badge v-if="invoice.overdue" variant="destructive" class="ml-2">{{ t('invoices.paymentStatus.overdue') }}</Badge>
                    </TableCell>
                    <TableCell class="whitespace-nowrap tabular-nums">{{ formatDate(invoice.issue_date) }}</TableCell>
                    <TableCell class="whitespace-nowrap tabular-nums" :class="invoice.overdue ? 'text-destructive' : ''">{{ formatDate(invoice.due_date ?? invoice.issue_date) }}</TableCell>
                    <TableCell class="text-right whitespace-nowrap tabular-nums">{{ formatMoney(invoice.balance) }}</TableCell>
                    <TableCell>
                      <div class="grid gap-1">
                        <MoneyInput
                          :id="`apply-${invoice.id}`"
                          :model-value="amounts[invoice.id] ?? ''"
                          :scale="2"
                          :invalid="!!rowError(invoice) || !!apiRowError(invoice)"
                          :aria-label="t('payments.editor.amountFor', { number: invoice.number })"
                          @update:model-value="(value: string) => amounts = { ...amounts, [invoice.id]: value }"
                        />
                        <p v-if="rowError(invoice) ?? apiRowError(invoice)" class="text-xs text-destructive">{{ rowError(invoice) ?? apiRowError(invoice) }}</p>
                      </div>
                    </TableCell>
                    <TableCell class="pr-6">
                      <Button variant="outline" size="sm" @click="payInFull(invoice)">{{ t('payments.editor.payInFull') }}</Button>
                    </TableCell>
                  </TableRow>
                </TableBody>
              </Table>
            </div>
            <p v-if="credits.length > 0" class="text-xs text-muted-foreground">
              {{ t('payments.editor.credits', { invoices: credits.map((credit) => credit.number).join(', ') }) }}
            </p>
          </div>
        </FormSection>

        <FormSection :title="t('payments.editor.details')" :description="t('payments.editor.detailsDescription')">
          <div class="grid gap-4">
            <div class="grid gap-4 sm:grid-cols-2">
              <FormField id="payment-date" :label="t('payments.editor.date')" :error="errors.payment_date?.[0]">
                <Input id="payment-date" v-model="paymentDate" type="date" :max="todayInColombia()" :aria-invalid="!!errors.payment_date" />
              </FormField>
              <FormField id="payment-means" :label="t('payments.editor.means')" :error="errors.payment_means_code?.[0]">
                <SelectInput id="payment-means" v-model="means" :options="paymentMeans" :invalid="!!errors.payment_means_code" />
              </FormField>
            </div>
            <FormField id="payment-reference" :label="t('payments.editor.reference')" :hint="t('payments.editor.referenceHint')" :error="errors.reference?.[0]" optional>
              <Input id="payment-reference" v-model="reference" maxlength="100" autocomplete="off" />
            </FormField>
            <FormField id="payment-notes" :label="t('payments.editor.notes')" :error="errors.notes?.[0]" optional>
              <Textarea id="payment-notes" v-model="notes" rows="2" maxlength="2000" />
            </FormField>
          </div>
        </FormSection>
      </div>

      <div class="lg:sticky lg:top-20">
        <div class="grid gap-3 rounded-xl border p-5">
          <p class="text-sm font-medium">{{ t('payments.editor.total') }}</p>
          <p class="text-2xl font-semibold tabular-nums">{{ formatMoney(fromCents(total)) }}</p>
          <p class="text-xs text-muted-foreground">{{ t('payments.editor.totalHint', { count: applied.length }) }}</p>
          <p v-if="errors.amount" class="text-xs text-destructive">{{ errors.amount[0] }}</p>
        </div>
      </div>
    </div>
  </div>
</template>
