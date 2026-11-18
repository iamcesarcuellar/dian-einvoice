<script setup lang="ts">
import type { Contact, DocumentEvent, Invoice, PartySnapshot } from '@/api/types'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import FormSection from '@/components/FormSection.vue'
import DocumentStatusBadge from '@/components/invoice/DocumentStatusBadge.vue'
import ElectronicDocumentCard from '@/components/invoice/ElectronicDocumentCard.vue'
import TotalsPanel from '@/components/invoice/TotalsPanel.vue'
import PageHeader from '@/components/PageHeader.vue'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useReference } from '@/composables/useReference'
import { formatDate, formatDateTime, formatDecimal, formatMoney, formatPercent, formatTime } from '@/lib/format'
import { formatNit } from '@/lib/utils'

/** Read-only view of an invoice: always for issued invoices, and for drafts when the role cannot edit. */
const props = defineProps<{
  invoice: Invoice
}>()

const { t } = useI18n()
const { label } = useReference()

function partyOf(contact: Contact): PartySnapshot {
  return {
    legal_name: contact.legal_name,
    trade_name: contact.trade_name,
    identification_type: contact.identification_type,
    identification_number: contact.identification_number,
    check_digit: contact.check_digit,
    email: contact.email,
    phone: contact.phone,
    address: contact.address,
    country_code: contact.country_code,
    municipality: contact.municipality ?? null,
    postal_code: contact.postal_code,
  }
}

// An issued invoice shows the customer exactly as it was when issued; a draft shows it as it is now.
const customer = computed<PartySnapshot | null>(() => props.invoice.customer_snapshot
  ?? (props.invoice.contact ? partyOf(props.invoice.contact) : null))

const identification = computed(() => {
  const party = customer.value

  if (!party) {
    return ''
  }

  const number = party.check_digit === null
    ? party.identification_number
    : formatNit(party.identification_number, party.check_digit)

  return `${label('identificationShort', party.identification_type)} ${number}`
})

const location = computed(() => {
  const party = customer.value

  if (!party) {
    return ''
  }

  const place = party.municipality
    ? [party.municipality.name, party.municipality.department_name].filter(Boolean).join(', ')
    : party.country_code

  return [party.address, place].filter(Boolean).join(' · ')
})

const subtitle = computed(() => (props.invoice.issue_date
  ? t('invoices.view.issued', { date: formatDate(props.invoice.issue_date), time: formatTime(props.invoice.issue_time) })
  : undefined))

const numbering = computed(() => {
  const snapshot = props.invoice.numbering_snapshot

  if (!snapshot) {
    return '—'
  }

  return t('invoices.view.resolution', {
    prefix: snapshot.prefix ?? '',
    number: snapshot.resolution_number ?? '—',
    from: formatDate(snapshot.valid_from) || '—',
    to: formatDate(snapshot.valid_to) || '—',
  })
})

function eventLabel(event: DocumentEvent): string {
  switch (event.type) {
    case 'issued':
      return t('invoices.view.events.issued', { number: String(event.payload?.full_number ?? '') })
    case 'xml_generated':
      return t('invoices.view.events.xmlGenerated')
    case 'xml_failed':
      return t('invoices.view.events.xmlFailed')
    default:
      return t('invoices.view.events.created')
  }
}

/** The reason of a failed XML generation, shown under its timeline entry. */
function eventDetail(event: DocumentEvent): string | null {
  const reason = event.payload?.reason

  return event.type === 'xml_failed' && typeof reason === 'string' ? reason : null
}
</script>

<template>
  <div class="grid gap-6">
    <PageHeader :title="invoice.full_number ?? t('invoices.draft')" :description="subtitle" :back="{ name: 'invoices' }">
      <template #badge>
        <DocumentStatusBadge :status="invoice.status" />
      </template>
    </PageHeader>

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
      <div class="grid min-w-0 gap-6">
        <FormSection :title="t('invoices.view.customer')">
          <div v-if="customer" class="grid gap-1">
            <p class="font-medium">{{ customer.legal_name }}</p>
            <p class="text-muted-foreground tabular-nums">{{ identification }}</p>
            <p v-if="location" class="text-muted-foreground">{{ location }}</p>
            <p v-if="customer.email || customer.phone" class="text-muted-foreground">
              {{ [customer.email, customer.phone].filter(Boolean).join(' · ') }}
            </p>
          </div>
          <p v-else class="text-muted-foreground">—</p>
        </FormSection>

        <FormSection :title="t('invoices.view.details')">
          <dl class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-0.5">
              <dt class="text-xs text-muted-foreground">{{ t('invoices.view.issueDate') }}</dt>
              <dd class="tabular-nums">{{ formatDate(invoice.issue_date) || '—' }}</dd>
            </div>
            <div class="grid gap-0.5">
              <dt class="text-xs text-muted-foreground">{{ t('invoices.view.issueTime') }}</dt>
              <dd class="tabular-nums">{{ formatTime(invoice.issue_time) || '—' }}</dd>
            </div>
            <div class="grid gap-0.5">
              <dt class="text-xs text-muted-foreground">{{ t('invoices.view.dueDate') }}</dt>
              <dd class="tabular-nums">{{ formatDate(invoice.due_date) || t('invoices.view.noDueDate') }}</dd>
            </div>
            <div class="grid gap-0.5">
              <dt class="text-xs text-muted-foreground">{{ t('invoices.view.paymentForm') }}</dt>
              <dd>{{ label('paymentForms', invoice.payment_form) || '—' }}</dd>
            </div>
            <div class="grid gap-0.5">
              <dt class="text-xs text-muted-foreground">{{ t('invoices.view.paymentMeans') }}</dt>
              <dd>{{ label('paymentMeans', invoice.payment_means_code) || '—' }}</dd>
            </div>
            <div class="grid gap-0.5">
              <dt class="text-xs text-muted-foreground">{{ t('invoices.view.numbering') }}</dt>
              <dd>{{ numbering }}</dd>
            </div>
          </dl>
        </FormSection>

        <ElectronicDocumentCard v-if="invoice.status === 'issued'" :invoice="invoice" />

        <FormSection :title="t('invoices.view.lines')">
          <div class="-mx-6 overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow class="hover:bg-transparent">
                  <TableHead class="pl-6">{{ t('invoices.editor.lineDescription') }}</TableHead>
                  <TableHead class="text-right">{{ t('invoices.editor.quantity') }}</TableHead>
                  <TableHead>{{ t('invoices.editor.unit') }}</TableHead>
                  <TableHead class="text-right">{{ t('invoices.editor.unitPrice') }}</TableHead>
                  <TableHead class="text-right">{{ t('invoices.editor.discount') }}</TableHead>
                  <TableHead>{{ t('invoices.editor.tax') }}</TableHead>
                  <TableHead class="pr-6 text-right">{{ t('invoices.editor.lineTotal') }}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                <TableRow v-for="line in invoice.lines ?? []" :key="line.id">
                  <TableCell class="pl-6">
                    <span class="grid min-w-40">
                      <span>{{ line.description }}</span>
                      <span v-if="line.code" class="text-xs text-muted-foreground">{{ line.code }}</span>
                    </span>
                  </TableCell>
                  <TableCell class="text-right tabular-nums">{{ formatDecimal(line.quantity) }}</TableCell>
                  <TableCell class="text-muted-foreground">{{ label('units', line.unit_code) }}</TableCell>
                  <TableCell class="text-right whitespace-nowrap tabular-nums">{{ formatMoney(line.unit_price) }}</TableCell>
                  <TableCell class="text-right tabular-nums">{{ formatDecimal(line.discount_rate) === '0' ? '—' : formatPercent(line.discount_rate) }}</TableCell>
                  <TableCell class="whitespace-nowrap">{{ line.tax_name ?? t('items.excluded') }}</TableCell>
                  <TableCell class="pr-6 text-right whitespace-nowrap tabular-nums">{{ formatMoney(line.line_extension_amount) }}</TableCell>
                </TableRow>
              </TableBody>
            </Table>
          </div>
        </FormSection>

        <FormSection v-if="invoice.notes" :title="t('invoices.view.notes')">
          <p class="whitespace-pre-line">{{ invoice.notes }}</p>
        </FormSection>

        <FormSection :title="t('invoices.view.timeline')">
          <ol class="grid gap-4">
            <li v-for="event in invoice.events ?? []" :key="event.id" class="flex gap-3">
              <span
                class="mt-1.5 size-2 shrink-0 rounded-full"
                :class="event.type === 'xml_failed' ? 'bg-destructive' : 'bg-primary'"
                aria-hidden="true"
              />
              <div class="grid gap-0.5">
                <p class="font-medium">{{ eventLabel(event) }}</p>
                <p v-if="eventDetail(event)" class="font-mono text-xs break-words text-destructive">{{ eventDetail(event) }}</p>
                <p class="text-xs text-muted-foreground">
                  {{ formatDateTime(event.occurred_at) }}
                  <template v-if="event.actor">· {{ t('invoices.view.by', { name: event.actor.name }) }}</template>
                </p>
              </div>
            </li>
          </ol>
        </FormSection>
      </div>

      <div class="lg:sticky lg:top-20">
        <TotalsPanel :totals="invoice.totals" :taxes="invoice.taxes ?? []" :withholdings="invoice.withholdings ?? []" />
      </div>
    </div>
  </div>
</template>
