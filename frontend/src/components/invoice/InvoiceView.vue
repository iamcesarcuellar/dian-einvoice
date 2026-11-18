<script setup lang="ts">
import type { Contact, DocumentEvent, Invoice, PartySnapshot } from '@/api/types'
import { ArrowUpRightIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import FormSection from '@/components/FormSection.vue'
import BalanceCard from '@/components/invoice/BalanceCard.vue'
import BalanceStatusBadge from '@/components/invoice/BalanceStatusBadge.vue'
import DeliveryCard from '@/components/invoice/DeliveryCard.vue'
import DocumentStatusBadge from '@/components/invoice/DocumentStatusBadge.vue'
import ElectronicDocumentCard from '@/components/invoice/ElectronicDocumentCard.vue'
import ElectronicProgress from '@/components/invoice/ElectronicProgress.vue'
import InvoicePaymentBadges from '@/components/invoice/InvoicePaymentBadges.vue'
import InvoicePaymentsCard from '@/components/invoice/InvoicePaymentsCard.vue'
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
import { DOCUMENT_ROUTES } from '@/lib/documents'
import { formatDate, formatDateTime, formatDecimal, formatInteger, formatMoney, formatPercent, formatTime } from '@/lib/format'
import { formatNit } from '@/lib/utils'

/**
 * Read-only view of an invoice, credit note or debit note: always for issued documents, and for
 * drafts when the role cannot edit. An accepted invoice shows its balance and notes; a note shows
 * the invoice it corrects, with a link to it.
 */
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

const isNote = computed(() => props.invoice.type !== 'invoice')
const related = computed(() => props.invoice.related_document)

const subtitle = computed(() => {
  const issued = props.invoice.issue_date
    ? t('invoices.view.issued', { date: formatDate(props.invoice.issue_date), time: formatTime(props.invoice.issue_time) })
    : undefined

  return isNote.value ? [t(`notes.one.${props.invoice.type}`), issued].filter(Boolean).join(' · ') : issued
})

const concept = computed(() => (props.invoice.reason_code
  ? `${props.invoice.reason_code} · ${label(props.invoice.type === 'credit_note' ? 'creditNoteConcepts' : 'debitNoteConcepts', props.invoice.reason_code)}`
  : '—'))

const numbering = computed(() => {
  const snapshot = props.invoice.numbering_snapshot

  if (!snapshot) {
    return '—'
  }

  // Notes need no DIAN resolution.
  if (isNote.value) {
    return t('notes.view.numbering', {
      prefix: snapshot.prefix ?? '',
      from: formatInteger(snapshot.from_number),
      to: formatInteger(snapshot.to_number),
    })
  }

  return t('invoices.view.resolution', {
    prefix: snapshot.prefix ?? '',
    number: snapshot.resolution_number ?? '—',
    date: formatDate(snapshot.resolution_date) || '—',
    from: formatDate(snapshot.valid_from) || '—',
    to: formatDate(snapshot.valid_to) || '—',
  })
})

/** Why a call to the local simulator failed, in a few words. */
function attemptReason(payload: Record<string, unknown>): string {
  const outcome = String(payload.outcome ?? 'no_response')
  const key = `invoices.view.events.reasons.${outcome}`

  return t(key, { status: String(payload.http_status ?? '') })
}

function attemptLabel(payload: Record<string, unknown>): string {
  const attempt = Number(payload.attempt ?? 1)

  switch (payload.outcome) {
    case 'responded':
      return t('invoices.view.events.sent', { attempt })
    case 'found':
      return t('invoices.view.events.found', { attempt })
    case 'not_found':
      return t('invoices.view.events.notFound', { attempt })
    case 'abandoned':
      return t('invoices.view.events.interrupted', { attempt })
    default:
      return typeof payload.retry_in === 'number'
        ? t('invoices.view.events.attemptFailed', { attempt, reason: attemptReason(payload), seconds: payload.retry_in })
        : t('invoices.view.events.attemptFailedLast', { attempt, reason: attemptReason(payload) })
  }
}

/** One email attempt to the customer, always caught by the local mailbox. */
function emailLabel(payload: Record<string, unknown>): string {
  const attempt = Number(payload.attempt ?? 1)
  const recipient = String(payload.recipient ?? '')

  switch (payload.outcome) {
    case 'sent':
      return t('invoices.view.events.emailSent', { recipient })
    case 'rejected':
      return t('invoices.view.events.emailRejected', { attempt, recipient })
    case 'abandoned':
      return t('invoices.view.events.emailInterrupted', { attempt })
    default:
      return typeof payload.retry_in === 'number'
        ? t('invoices.view.events.emailFailed', { attempt, recipient, seconds: payload.retry_in })
        : t('invoices.view.events.emailFailedLast', { attempt, recipient })
  }
}

function rulesOf(payload: Record<string, unknown> | null): string {
  return Array.isArray(payload?.rules) ? payload.rules.join(', ') : ''
}

function eventLabel(event: DocumentEvent): string {
  const payload = event.payload ?? {}

  switch (event.type) {
    case 'issued':
      return t('invoices.view.events.issued', { number: String(payload.full_number ?? '') })
    case 'xml_generated':
      return t('invoices.view.events.xmlGenerated')
    case 'xml_signed':
      return t('invoices.view.events.xmlSigned')
    case 'xml_failed':
      return t('invoices.view.events.xmlFailed')
    case 'transmission_attempt':
      return attemptLabel(payload)
    case 'accepted':
      return t('invoices.view.events.accepted')
    case 'rejected':
      return t('invoices.view.events.rejected')
    case 'transmission_failed':
      return t('invoices.view.events.transmissionFailed', { attempts: Number(payload.attempts ?? 0) })
    case 'retry_requested':
      return t('invoices.view.events.retryRequested')
    case 'resend_requested':
      return t('invoices.view.events.resendRequested')
    case 'attached_document_built':
      return t('invoices.view.events.attachedDocumentBuilt')
    case 'pdf_generated':
      return t('invoices.view.events.pdfGenerated')
    case 'email_attempt':
      return emailLabel(payload)
    case 'delivery_not_applicable':
      return t('invoices.view.events.deliveryNotApplicable')
    case 'delivery_failed':
      return t('invoices.view.events.deliveryFailed', { step: t(`invoices.view.events.steps.${String(payload.step ?? 'email')}`) })
    case 'delivery_requested':
      return t('invoices.view.events.deliveryRequested')
    case 'pdf_regeneration_requested':
      return t('invoices.view.events.pdfRegenerationRequested')
    case 'note_accepted':
      return t(payload.voided === true ? 'notes.view.events.voided' : `notes.view.events.accepted.${String(payload.note_type ?? 'credit_note')}`, { number: String(payload.number ?? '') })
    case 'payment_applied':
      return t('invoices.payments.events.applied', { number: String(payload.number ?? ''), amount: formatMoney(String(payload.amount ?? '0')) })
    case 'payment_released':
      return t('invoices.payments.events.released', { number: String(payload.number ?? ''), amount: formatMoney(String(payload.amount ?? '0')) })
    default:
      return t('invoices.view.events.created')
  }
}

/** Shown under a timeline entry: the certificate that signed, the call, the rules, or a reason. */
function eventDetail(event: DocumentEvent): string | null {
  const payload = event.payload ?? {}
  const reason = payload.reason

  switch (event.type) {
    case 'xml_signed':
      return typeof payload.certificate_serial === 'string'
        ? t(payload.test_certificate === true ? 'invoices.view.events.signedWithTest' : 'invoices.view.events.signedWith', { serial: payload.certificate_serial })
        : null
    case 'transmission_attempt':
      return payload.status_code
        ? t('invoices.view.events.callDetailStatus', { operation: String(payload.operation ?? ''), ms: Number(payload.duration_ms ?? 0), code: String(payload.status_code) })
        : t('invoices.view.events.callDetail', { operation: String(payload.operation ?? ''), ms: Number(payload.duration_ms ?? 0) })
    case 'accepted':
      return Number(payload.notifications ?? 0) > 0
        ? t('invoices.view.events.acceptedNotifications', { count: Number(payload.notifications), rules: rulesOf(payload) })
        : null
    case 'rejected':
      return rulesOf(payload) ? t('invoices.view.events.rejectedRules', { rules: rulesOf(payload) }) : null
    case 'retry_requested':
      return payload.waiting === true
        ? t('invoices.view.events.retryWaiting')
        : t('invoices.view.events.retryFrom', { step: t(`invoices.view.events.steps.${String(payload.step ?? 'transmit')}`) })
    case 'resend_requested':
      return payload.cufe_changed === true ? t('invoices.view.events.cufeChanged') : null
    case 'note_accepted':
      return t('notes.view.events.balance', { status: t(`notes.balanceStatus.${String(payload.balance_status ?? 'open')}`) })
    case 'payment_applied':
    case 'payment_released':
      return t('invoices.payments.events.balance', { amount: formatMoney(String(payload.balance ?? '0')) })
    case 'attached_document_built':
      return t('invoices.view.events.attachedDocumentDetail', { file: String(payload.file_name ?? ''), size: formatInteger(Number(payload.size ?? 0)) })
    case 'pdf_generated':
      return t('invoices.view.events.pdfDetail', { file: String(payload.file_name ?? ''), pages: Number(payload.pages ?? 0), size: formatInteger(Number(payload.size ?? 0)) })
    case 'email_attempt':
      return payload.outcome === 'sent'
        ? t('invoices.view.events.emailSentDetail', { attempt: Number(payload.attempt ?? 1), max: Number(payload.max_attempts ?? 1), ms: Number(payload.duration_ms ?? 0) })
        : (typeof payload.error === 'string' ? payload.error : null)
    case 'delivery_requested':
      return t('invoices.view.events.deliveryRequestedTo', { recipient: String(payload.recipient ?? '') })
    case 'xml_failed':
    case 'transmission_failed':
    case 'delivery_failed':
      return typeof reason === 'string' ? reason : null
    default:
      return null
  }
}

/** Color of the dot of a timeline entry. */
function eventTone(event: DocumentEvent): 'error' | 'warning' | 'success' | 'default' {
  switch (event.type) {
    case 'xml_failed':
    case 'rejected':
    case 'transmission_failed':
    case 'delivery_failed':
      return 'error'
    case 'accepted':
    case 'note_accepted':
    case 'payment_applied':
      return 'success'
    case 'payment_released':
      return 'warning'
    case 'email_attempt':
      return event.payload?.outcome === 'sent' ? 'success' : (event.payload?.outcome === 'rejected' ? 'error' : 'warning')
    case 'transmission_attempt':
      return ['responded', 'found', 'not_found'].includes(String(event.payload?.outcome)) ? 'default' : 'warning'
    default:
      return 'default'
  }
}

const toneClass = {
  error: 'bg-destructive',
  warning: 'bg-amber-500',
  success: 'bg-emerald-600',
  default: 'bg-primary',
}
</script>

<template>
  <div class="grid gap-6">
    <PageHeader :title="invoice.full_number ?? t('invoices.draft')" :description="subtitle" :back="{ name: DOCUMENT_ROUTES[invoice.type].list }">
      <template #badge>
        <DocumentStatusBadge :status="invoice.status" />
        <BalanceStatusBadge v-if="invoice.balance_status && invoice.balance_status !== 'open'" :status="invoice.balance_status" />
        <InvoicePaymentBadges v-if="invoice.type === 'invoice' && invoice.electronic?.status === 'accepted'" :invoice="invoice" />
      </template>
    </PageHeader>

    <ElectronicProgress v-if="invoice.electronic" :electronic="invoice.electronic" :delivery="invoice.delivery" />

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
      <div class="grid min-w-0 gap-6">
        <FormSection v-if="isNote" :title="t('notes.view.corrects')">
          <div class="grid gap-4">
            <dl class="grid gap-4 sm:grid-cols-3">
              <div class="grid gap-0.5">
                <dt class="text-xs text-muted-foreground">{{ t('notes.reference.number') }}</dt>
                <dd class="font-medium tabular-nums">
                  <RouterLink v-if="related" :to="{ name: 'invoice', params: { id: related.id } }" class="inline-flex items-center gap-1 hover:underline">
                    {{ related.full_number ?? '—' }}
                    <ArrowUpRightIcon class="size-3.5" />
                  </RouterLink>
                  <template v-else>—</template>
                </dd>
              </div>
              <div class="grid gap-0.5">
                <dt class="text-xs text-muted-foreground">{{ t('notes.reference.date') }}</dt>
                <dd class="tabular-nums">{{ formatDate(related?.issue_date) || '—' }}</dd>
              </div>
              <div class="grid gap-0.5">
                <dt class="text-xs text-muted-foreground">{{ t('notes.reference.total') }}</dt>
                <dd class="tabular-nums">{{ formatMoney(related?.payable_amount) || '—' }}</dd>
              </div>
              <div class="grid gap-0.5 sm:col-span-3">
                <dt class="text-xs text-muted-foreground">{{ t('notes.view.invoiceCufe') }}</dt>
                <dd class="font-mono text-xs break-all">{{ related?.cufe ?? '—' }}</dd>
              </div>
              <div class="grid gap-0.5 sm:col-span-3">
                <dt class="text-xs text-muted-foreground">{{ t('notes.correction.concept') }}</dt>
                <dd>{{ concept }}</dd>
              </div>
              <div class="grid gap-0.5 sm:col-span-3">
                <dt class="text-xs text-muted-foreground">{{ t('notes.correction.reason') }}</dt>
                <dd class="whitespace-pre-line">{{ invoice.reason_text || '—' }}</dd>
              </div>
            </dl>
          </div>
        </FormSection>

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
            <div v-if="!isNote" class="grid gap-0.5">
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

        <InvoicePaymentsCard v-if="invoice.type === 'invoice' && invoice.electronic?.status === 'accepted'" :invoice="invoice" />

        <BalanceCard v-if="invoice.type === 'invoice' && invoice.electronic?.status === 'accepted'" :invoice="invoice" />

        <ElectronicDocumentCard v-if="invoice.status === 'issued'" :invoice="invoice" />

        <DeliveryCard v-if="invoice.status === 'issued'" :invoice="invoice" />

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
                :class="toneClass[eventTone(event)]"
                aria-hidden="true"
              />
              <div class="grid min-w-0 gap-0.5">
                <p class="font-medium">{{ eventLabel(event) }}</p>
                <p
                  v-if="eventDetail(event)"
                  class="text-xs break-words"
                  :class="['xml_failed', 'transmission_failed', 'delivery_failed'].includes(event.type) ? 'font-mono text-destructive' : 'text-muted-foreground'"
                >
                  {{ eventDetail(event) }}
                </p>
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
