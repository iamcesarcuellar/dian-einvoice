<script setup lang="ts">
import type { Contact, DocumentType, Invoice, InvoiceInput, Item, ItemOption, NotePrefill } from '@/api/types'
import type { LineForm } from '@/components/invoice/invoiceForm'
import type { Option } from '@/lib/options'
import { ArrowUpRightIcon, CircleAlertIcon, PlusIcon, Trash2Icon } from '@lucide/vue'
import { keepPreviousData, useMutation, useQuery } from '@tanstack/vue-query'
import { refDebounced } from '@vueuse/core'
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { fieldErrors } from '@/api/client'
import { contactsApi, documentsApi, itemsApi, numberTemplatesApi, taxesApi, withholdingsApi } from '@/api/resources'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import DecimalInput from '@/components/DecimalInput.vue'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import FormSection from '@/components/FormSection.vue'
import IssueDialog from '@/components/invoice/IssueDialog.vue'
import { CREDIT, emptyInvoiceForm, emptyLine, EXCLUDED, invoiceFormFrom, isBlankLine, noteFormFrom, toInvoiceInput } from '@/components/invoice/invoiceForm'
import TotalsPanel from '@/components/invoice/TotalsPanel.vue'
import MoneyInput from '@/components/MoneyInput.vue'
import PageHeader from '@/components/PageHeader.vue'
import SearchSelect from '@/components/SearchSelect.vue'
import SelectInput from '@/components/SelectInput.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { useReference } from '@/composables/useReference'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { ALL_DOCUMENT_RESOURCES, documentCrumbs, DOCUMENT_RESOURCES, DOCUMENT_ROUTES, VOID_CONCEPT } from '@/lib/documents'
import { addDays, formatDate, formatInteger, formatMoney, todayInColombia } from '@/lib/format'
import { notify } from '@/lib/notify'
import { invalidateTenant, tenantKey } from '@/lib/query'
import { formatNit } from '@/lib/utils'
import { useSessionStore } from '@/stores/session'

/**
 * Editor of a draft invoice, credit note or debit note, or of a new one when invoice is null.
 * Every amount on screen comes from the API: the editor sends the draft to the preview of its type
 * as the user types. A note starts from the lines of its invoice (prefill), shows the invoice it
 * corrects, takes a concept and a description, and keeps the customer and the payment terms of the
 * invoice. A credit note cannot be issued above what is left to credit on its invoice.
 */
const props = withDefaults(
  defineProps<{
    invoice: Invoice | null
    /** Type of a new document; a saved draft has its own. */
    kind?: DocumentType
    /** A new note: what the API prefilled from its invoice. */
    prefill?: NotePrefill | null
  }>(),
  {
    kind: 'invoice',
    prefill: null,
  },
)

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const { label, paymentForms, paymentMeans, units, creditNoteConcepts, debitNoteConcepts } = useReference()

const type = computed<DocumentType>(() => props.invoice?.type ?? props.kind)
const isNote = computed(() => type.value !== 'invoice')
const api = computed(() => documentsApi(type.value))
const routes = computed(() => DOCUMENT_ROUTES[type.value])

const companyId = computed(() => session.currentCompanyId)
const today = todayInColombia()

// Options of the selects.
const { data: templates } = useQuery({
  queryKey: computed(() => tenantKey(companyId.value, 'number-templates', 'options')),
  queryFn: () => numberTemplatesApi.list({ per_page: 100 }),
})

const { data: taxes } = useQuery({
  queryKey: computed(() => tenantKey(companyId.value, 'taxes', 'options')),
  queryFn: () => taxesApi.list({ per_page: 100 }),
})

const { data: withholdingPage } = useQuery({
  queryKey: computed(() => tenantKey(companyId.value, 'withholdings', 'options')),
  queryFn: () => withholdingsApi.list({ per_page: 100 }),
})

const contactsKey = computed(() => tenantKey(companyId.value, 'contacts', 'search'))
const itemsKey = computed(() => tenantKey(companyId.value, 'items', 'search'))

async function searchContacts(term: string): Promise<Contact[]> {
  return (await contactsApi.list({ search: term || undefined, active: 1, per_page: 20 })).data
}

async function searchItems(term: string): Promise<Item[]> {
  return (await itemsApi.list({ search: term || undefined, active: 1, per_page: 20 })).data
}

const form = ref(props.invoice ? invoiceFormFrom(props.invoice) : props.prefill ? noteFormFrom(props.prefill) : emptyInvoiceForm())

/** New lines take the highest active IVA rate (taxes come sorted by rate), or no tax. */
const defaultTaxId = computed(() => (taxes.value ? (taxes.value.data.find((tax) => tax.is_active && tax.dian_code === '01')?.id ?? EXCLUDED) : null))

if (form.value.lines.length === 0) {
  form.value.lines.push(emptyLine(defaultTaxId.value))
}

const numberingOptions = computed<Option[]>(() =>
  (templates.value?.data ?? [])
    .filter((template) => template.document_type === type.value && (template.is_active || template.id === form.value.number_template_id))
    .map((template) => ({
      value: template.id,
      label: `${template.prefix ?? ''} ${formatInteger(template.from_number)} – ${formatInteger(template.to_number)}`.trim(),
    })),
)

const taxOptions = computed<Option[]>(() => {
  const used = new Set(form.value.lines.map((line) => line.tax_id))

  return [
    { value: EXCLUDED, label: t('items.excluded') },
    ...(taxes.value?.data ?? []).filter((tax) => tax.is_active || used.has(tax.id)).map((tax) => ({ value: tax.id, label: tax.name })),
  ]
})

const withholdingOptions = computed(() =>
  (withholdingPage.value?.data ?? []).filter((withholding) => withholding.is_active || form.value.withholding_ids.includes(withholding.id)),
)

// A new document takes the default numbering of its type.
watch(
  templates,
  (page) => {
    if (form.value.number_template_id === null && page) {
      form.value.number_template_id =
        page.data.find((template) => template.document_type === type.value && template.is_default && template.is_active)?.id ?? null
    }
  },
  { immediate: true },
)

// Lines added before the taxes loaded take the default tax once it is known.
watch(
  defaultTaxId,
  (taxId) => {
    for (const line of form.value.lines) {
      if (line.tax_id === null) {
        line.tax_id = taxId
      }
    }
  },
  { immediate: true },
)

// A credit invoice needs a due date: suggest 30 days from today.
watch(
  () => form.value.payment_form,
  (paymentForm) => {
    if (paymentForm === CREDIT && form.value.due_date === '') {
      form.value.due_date = addDays(today, 30)
    }
  },
)

// Live totals: the draft goes to the API 350 ms after the last change.
const snapshot = computed(() => JSON.stringify(toInvoiceInput(form.value, type.value)))
const debouncedSnapshot = refDebounced(snapshot, 350)

const preview = useQuery({
  queryKey: computed(() => tenantKey(companyId.value, DOCUMENT_RESOURCES[type.value], 'preview', debouncedSnapshot.value)),
  queryFn: async () => {
    const { input, keys } = JSON.parse(debouncedSnapshot.value) as { input: InvoiceInput; keys: string[] }

    return { keys, calculation: await api.value.preview(input) }
  },
  placeholderData: keepPreviousData,
  staleTime: Number.POSITIVE_INFINITY,
  retry: false,
})

const calculation = computed(() => preview.data.value?.calculation ?? null)
const previewPending = computed(() => snapshot.value !== debouncedSnapshot.value || preview.isFetching.value)
const previewNotice = computed(() => (preview.isError.value ? t('invoices.totals.unavailable') : null))

/** Line value calculated by the API, by line key. */
const lineTotals = computed(() => {
  const totals = new Map<string, string>()
  const result = preview.data.value

  result?.keys.forEach((key, index) => {
    const line = result.calculation.lines[index]

    if (line) {
      totals.set(key, line.line_extension_amount)
    }
  })

  return totals
})

function lineTotal(line: LineForm): string {
  return isBlankLine(line) ? '—' : formatMoney(lineTotals.value.get(line.key)) || '—'
}

function identificationOf(contact: Contact): string {
  const number = contact.check_digit === null ? contact.identification_number : formatNit(contact.identification_number, contact.check_digit)

  return `${label('identificationShort', contact.identification_type)} ${number}`
}

// Saving, issuing and deleting.
const savedId = ref<string | null>(props.invoice?.id ?? null)
const submittedKeys = ref<string[]>([])

// Leaving with changes asks first. The defaults a new draft takes (numbering, tax) are not changes:
// the baseline is taken once they are in.
const unsaved = useUnsavedChanges(form)
let baselineTaken = false

watch(
  [templates, taxes],
  ([loadedTemplates, loadedTaxes]) => {
    if (!baselineTaken && loadedTemplates && loadedTaxes) {
      baselineTaken = true
      void nextTick(() => unsaved.reset())
    }
  },
  { immediate: true },
)

async function persist(): Promise<Invoice> {
  const { input, keys } = toInvoiceInput(form.value, type.value)
  submittedKeys.value = keys

  const invoice = savedId.value ? await api.value.update(savedId.value, input) : await api.value.create(input)

  savedId.value = invoice.id

  return invoice
}

const saving = useMutation({
  mutationFn: persist,
  onSuccess: async (invoice) => {
    unsaved.reset()
    await invalidateTenant(...ALL_DOCUMENT_RESOURCES)
    notify.success(t('invoices.editor.draftSaved'))

    // A new draft gets its own address.
    if (props.invoice === null) {
      await router.replace({ name: routes.value.detail, params: { id: invoice.id } })
    }
  },
})

const issueOpen = ref(false)

const issuing = useMutation({
  // The draft is saved first, so the API issues exactly what is on screen.
  mutationFn: async () => api.value.issue((await persist()).id),
  onSuccess: async (invoice) => {
    unsaved.reset()
    await invalidateTenant(...ALL_DOCUMENT_RESOURCES, 'number-templates', 'dashboard')
    issueOpen.value = false
    notify.success(t('invoices.editor.issued', { number: invoice.full_number ?? '' }), t('invoices.editor.issuedHint'))
    await router.replace({ name: routes.value.detail, params: { id: invoice.id } })
  },
})

const deleteOpen = ref(false)

const removing = useMutation({
  mutationFn: () => api.value.remove(savedId.value as string),
  onSuccess: async () => {
    unsaved.reset()
    await invalidateTenant(...ALL_DOCUMENT_RESOURCES)
    notify.success(t('invoices.editor.draftDeleted'))
    await router.replace(related.value ? { name: 'invoice', params: { id: related.value.id } } : { name: routes.value.list })
  },
})

// A note: the invoice it corrects, what is left to credit on it, and the concept of the correction.
const related = computed(() => props.invoice?.related_document ?? props.prefill?.related_document ?? null)
const invoiceBalance = computed(() => props.invoice?.invoice_balance ?? props.prefill?.balance ?? null)

/** Cents of a decimal string, to compare amounts without floats. */
function cents(value: string | null | undefined): bigint {
  const match = /^(-?)(\d+)(?:\.(\d{0,2})\d*)?$/.exec((value ?? '0').trim())

  if (!match) {
    return 0n
  }

  const amount = BigInt(match[2] ?? '0') * 100n + BigInt((match[3] ?? '').padEnd(2, '0') || '0')

  return match[1] === '-' ? -amount : amount
}

const remaining = computed(() => (type.value === 'credit_note' ? (invoiceBalance.value?.remaining_to_credit ?? null) : null))
const payable = computed(() => calculation.value?.totals.payable_amount ?? null)

/** The credit note is above what is left to credit: it cannot be issued. */
const exceeds = computed(() => remaining.value !== null && payable.value !== null && cents(payable.value) > cents(remaining.value))

const voiding = computed(() => type.value === 'credit_note' && form.value.reason_code === VOID_CONCEPT)

/** A void takes all that is left to credit; less than one peso may stay, and then counts as credited. */
const voidMismatch = computed(
  () => voiding.value && remaining.value !== null && payable.value !== null && cents(remaining.value) - cents(payable.value) >= 100n,
)

const conceptOptions = computed<Option[]>(() => (type.value === 'credit_note' ? creditNoteConcepts.value : debitNoteConcepts.value))
const conceptGroup = computed(() => (type.value === 'credit_note' ? 'creditNoteConceptHints' : 'debitNoteConceptHints'))
const reasonLength = computed(() => form.value.reason_text.trim().length)

const titleKey = computed(() =>
  isNote.value ? `notes.editor.${props.invoice ? 'draftTitle' : 'newTitle'}.${type.value}` : `invoices.editor.${props.invoice ? 'draftTitle' : 'newTitle'}`,
)

function save(): void {
  issuing.reset()
  saving.mutate()
}

function openIssue(): void {
  saving.reset()
  issuing.reset()
  issueOpen.value = true
}

const blocked = computed(() => exceeds.value || voidMismatch.value)

const error = computed(() => issuing.error.value ?? saving.error.value)
const errors = computed(() => fieldErrors(error.value))

/** Validation message of a line field; the API numbers lines in the order they were sent. */
function lineError(key: string, field: string): string | undefined {
  const index = submittedKeys.value.indexOf(key)

  return index === -1 ? undefined : errors.value[`lines.${index}.${field}`]?.[0]
}

const numberingName = computed(() => numberingOptions.value.find((option) => option.value === form.value.number_template_id)?.label ?? '')

// Lines: Enter in a line adds another one below it; the remove button deletes it.
function addLine(after?: number): void {
  const line = emptyLine(defaultTaxId.value)

  form.value.lines.splice(after === undefined ? form.value.lines.length : after + 1, 0, line)

  void nextTick(() => document.getElementById(`${line.key}-description`)?.focus())
}

function removeLine(index: number): void {
  form.value.lines.splice(index, 1)

  if (form.value.lines.length === 0) {
    addLine()
  }
}

// Taking an item fills the line; every field stays editable.
function chooseItem(line: LineForm, chosen: Item | ItemOption | null): void {
  if (chosen === null) {
    line.item = null

    return
  }

  line.item = { id: chosen.id, code: chosen.code, name: chosen.name }

  if ('unit_price' in chosen) {
    line.code = chosen.code
    line.description = chosen.name
    line.unit_code = chosen.unit_code
    line.unit_price = chosen.unit_price
    line.tax_id = chosen.tax_id ?? EXCLUDED
  }
}

function toggleWithholding(id: string, checked: boolean): void {
  form.value.withholding_ids = checked ? [...form.value.withholding_ids, id] : form.value.withholding_ids.filter((value) => value !== id)
}

const crumbs = computed(() => documentCrumbs(type.value, props.invoice?.full_number ?? t(titleKey.value), t))
</script>

<template>
  <div class="grid gap-6">
    <PageHeader :title="t(titleKey)" :description="isNote ? t(`notes.editor.description.${type}`) : t('invoices.editor.description')" :breadcrumbs="crumbs">
      <template #badge>
        <StatusBadge domain="document" status="draft" />
      </template>

      <Button v-if="savedId" variant="ghost" @click="deleteOpen = true">
        <Trash2Icon />
        {{ t('invoices.editor.deleteDraft') }}
      </Button>
      <Button variant="outline" :disabled="saving.isPending.value || issuing.isPending.value" @click="save">
        {{ saving.isPending.value ? t('invoices.editor.savingDraft') : t('invoices.editor.saveDraft') }}
      </Button>
      <Button :disabled="saving.isPending.value || issuing.isPending.value || blocked" @click="openIssue">
        {{ voiding ? t('notes.editor.issueVoid') : type === 'invoice' ? t('invoices.editor.issue') : t(`notes.editor.issue.${type}`) }}
      </Button>
    </PageHeader>

    <FormError :error="error" />

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
      <div class="grid min-w-0 gap-6">
        <FormSection v-if="isNote && related" :title="t('notes.reference.title')" :description="t('notes.reference.description')">
          <div class="grid gap-4">
            <dl class="grid gap-3 rounded-lg bg-muted/50 p-3 sm:grid-cols-4">
              <div class="grid min-w-0 gap-0.5">
                <dt class="text-xs text-muted-foreground">{{ t('notes.reference.number') }}</dt>
                <dd class="truncate font-medium tabular-nums">
                  <RouterLink :to="{ name: 'invoice', params: { id: related.id } }" class="inline-flex items-center gap-1 hover:underline">
                    {{ related.full_number }}
                    <ArrowUpRightIcon class="size-3.5" />
                  </RouterLink>
                </dd>
              </div>
              <div class="grid min-w-0 gap-0.5">
                <dt class="text-xs text-muted-foreground">{{ t('notes.reference.date') }}</dt>
                <dd class="truncate tabular-nums">{{ formatDate(related.issue_date) || '—' }}</dd>
              </div>
              <div class="grid min-w-0 gap-0.5 sm:col-span-2">
                <dt class="text-xs text-muted-foreground">{{ t('notes.reference.customer') }}</dt>
                <dd class="truncate">{{ related.customer_name ?? '—' }}</dd>
              </div>
              <div class="grid min-w-0 gap-0.5">
                <dt class="text-xs text-muted-foreground">{{ t('notes.reference.total') }}</dt>
                <dd class="truncate tabular-nums">{{ formatMoney(related.payable_amount) || '—' }}</dd>
              </div>
              <div v-if="remaining !== null" class="grid min-w-0 gap-0.5 sm:col-span-3">
                <dt class="text-xs text-muted-foreground">{{ t('notes.reference.remaining') }}</dt>
                <dd class="truncate font-medium tabular-nums">{{ formatMoney(remaining) }}</dd>
              </div>
            </dl>
            <p v-if="remaining !== null" class="text-xs text-muted-foreground">{{ t('notes.reference.remainingHint') }}</p>

            <Alert v-if="exceeds" variant="destructive">
              <CircleAlertIcon />
              <AlertDescription>{{ t('notes.reference.exceeds', { amount: formatMoney(payable), remaining: formatMoney(remaining) }) }}</AlertDescription>
            </Alert>
            <Alert v-else-if="voidMismatch" variant="destructive">
              <CircleAlertIcon />
              <AlertDescription>{{ t('notes.reference.voidMismatch', { amount: formatMoney(payable), remaining: formatMoney(remaining) }) }}</AlertDescription>
            </Alert>
          </div>
        </FormSection>

        <FormSection v-if="isNote" :title="t('notes.correction.title')" :description="t('notes.correction.description')">
          <div class="grid gap-4">
            <FormField id="note-concept" :label="t('notes.correction.concept')" :error="errors.reason_code?.[0]">
              <SelectInput
                id="note-concept"
                v-model="form.reason_code"
                :options="conceptOptions"
                :placeholder="t('notes.correction.conceptPlaceholder')"
                :invalid="!!errors.reason_code"
              />
            </FormField>
            <p v-if="form.reason_code" class="-mt-2 text-xs text-muted-foreground">{{ t(`reference.${conceptGroup}.${form.reason_code}`) }}</p>

            <FormField
              id="note-reason"
              :label="t('notes.correction.reason')"
              :hint="t('notes.correction.reasonHint', { count: reasonLength })"
              :error="errors.reason_text?.[0]"
            >
              <Textarea id="note-reason" v-model="form.reason_text" rows="2" maxlength="5000" :aria-invalid="!!errors.reason_text" />
            </FormField>
          </div>
        </FormSection>

        <FormSection v-if="!isNote" :title="t('invoices.editor.customer')" :description="t('invoices.editor.customerDescription')">
          <div class="grid gap-4">
            <FormField id="invoice-customer" :label="t('invoices.editor.customer')" :error="errors.contact_id?.[0]">
              <SearchSelect
                id="invoice-customer"
                v-model="form.contact"
                :query-key="contactsKey"
                :search="searchContacts"
                by="id"
                :label="(contact) => contact.legal_name"
                :placeholder="t('invoices.editor.customerPlaceholder')"
                :empty-text="t('invoices.editor.noCustomers')"
                :invalid="!!errors.contact_id"
                list-class="min-w-80"
              >
                <template #option="{ option }">
                  <span class="grid min-w-0">
                    <span class="truncate">{{ option.legal_name }}</span>
                    <span class="text-xs text-muted-foreground tabular-nums">{{ identificationOf(option) }}</span>
                  </span>
                </template>
              </SearchSelect>
            </FormField>

            <dl v-if="form.contact" class="grid gap-3 rounded-lg bg-muted/50 p-3 sm:grid-cols-3">
              <div class="grid min-w-0 gap-0.5">
                <dt class="text-xs text-muted-foreground">{{ t('invoices.editor.identification') }}</dt>
                <dd class="truncate tabular-nums">{{ identificationOf(form.contact) }}</dd>
              </div>
              <div class="grid min-w-0 gap-0.5">
                <dt class="text-xs text-muted-foreground">{{ t('invoices.editor.city') }}</dt>
                <dd class="truncate">{{ form.contact.municipality?.name ?? form.contact.country_code }}</dd>
              </div>
              <div class="grid min-w-0 gap-0.5">
                <dt class="text-xs text-muted-foreground">{{ t('invoices.editor.email') }}</dt>
                <dd class="truncate">{{ form.contact.email ?? '—' }}</dd>
              </div>
            </dl>
          </div>
        </FormSection>

        <FormSection
          :title="t('invoices.editor.conditions')"
          :description="isNote ? t('notes.editor.conditionsDescription') : t('invoices.editor.conditionsDescription')"
        >
          <div class="grid gap-4">
            <div class="grid gap-4 sm:grid-cols-2">
              <FormField v-if="isNote" id="note-payment" :label="t('invoices.editor.paymentForm')">
                <Input
                  id="note-payment"
                  :model-value="[label('paymentForms', form.payment_form), label('paymentMeans', form.payment_means_code)].filter(Boolean).join(' · ')"
                  disabled
                />
              </FormField>
              <FormField v-if="!isNote" id="invoice-payment-form" :label="t('invoices.editor.paymentForm')" :error="errors.payment_form?.[0]">
                <SelectInput id="invoice-payment-form" v-model="form.payment_form" :options="paymentForms" :invalid="!!errors.payment_form" />
              </FormField>
              <FormField v-if="!isNote" id="invoice-payment-means" :label="t('invoices.editor.paymentMeans')" :error="errors.payment_means_code?.[0]">
                <SelectInput id="invoice-payment-means" v-model="form.payment_means_code" :options="paymentMeans" :invalid="!!errors.payment_means_code" />
              </FormField>
              <FormField
                v-if="!isNote"
                id="invoice-due-date"
                :label="t('invoices.editor.dueDate')"
                :hint="t('invoices.editor.dueDateHint')"
                :error="errors.due_date?.[0]"
                :optional="form.payment_form !== CREDIT"
              >
                <Input id="invoice-due-date" v-model="form.due_date" type="date" :min="today" :aria-invalid="!!errors.due_date" />
              </FormField>
              <FormField
                id="invoice-numbering"
                :label="t('invoices.editor.numbering')"
                :hint="t('invoices.editor.numberingHint')"
                :error="errors.number_template_id?.[0]"
              >
                <SelectInput id="invoice-numbering" v-model="form.number_template_id" :options="numberingOptions" :invalid="!!errors.number_template_id" />
              </FormField>
            </div>

            <FormField id="invoice-notes" :label="t('invoices.editor.notes')" :hint="t('invoices.editor.notesHint')" :error="errors.notes?.[0]" optional>
              <Textarea id="invoice-notes" v-model="form.notes" rows="2" />
            </FormField>
          </div>
        </FormSection>

        <FormSection :title="t('invoices.editor.lines')" :description="t('invoices.editor.linesDescription')">
          <div class="grid gap-3">
            <p v-if="errors.lines" class="text-xs text-destructive">{{ errors.lines[0] }}</p>

            <div v-for="(line, index) in form.lines" :key="line.key" class="grid gap-3 rounded-lg border p-3">
              <div class="grid items-start gap-3 sm:grid-cols-[16rem_minmax(0,1fr)_auto]">
                <FormField :id="`${line.key}-item`" :label="t('invoices.editor.item')" :error="lineError(line.key, 'item_id')">
                  <SearchSelect
                    :id="`${line.key}-item`"
                    :model-value="line.item"
                    :query-key="itemsKey"
                    :search="searchItems"
                    by="id"
                    :label="(option) => option.name"
                    :placeholder="t('invoices.editor.itemPlaceholder')"
                    :empty-text="t('invoices.editor.noItems')"
                    list-class="min-w-80"
                    @update:model-value="(option) => chooseItem(line, option)"
                  >
                    <template #option="{ option }">
                      <span class="grid min-w-0">
                        <span class="truncate">{{ option.name }}</span>
                        <span class="text-xs text-muted-foreground">{{ option.code }}</span>
                      </span>
                    </template>
                  </SearchSelect>
                </FormField>

                <FormField :id="`${line.key}-description`" :label="t('invoices.editor.lineDescription')" :error="lineError(line.key, 'description')">
                  <Input
                    :id="`${line.key}-description`"
                    v-model="line.description"
                    autocomplete="off"
                    :aria-invalid="!!lineError(line.key, 'description')"
                    @keydown.enter.prevent="addLine(index)"
                  />
                </FormField>

                <Button variant="ghost" size="icon" class="sm:mt-6" :aria-label="t('invoices.editor.removeLine')" @click="removeLine(index)">
                  <Trash2Icon />
                </Button>
              </div>

              <div class="grid grid-cols-2 items-start gap-3 sm:grid-cols-[6rem_8rem_minmax(0,1fr)_6.5rem_10rem]">
                <FormField :id="`${line.key}-quantity`" :label="t('invoices.editor.quantity')" :error="lineError(line.key, 'quantity')">
                  <DecimalInput
                    :id="`${line.key}-quantity`"
                    v-model="line.quantity"
                    :scale="6"
                    :invalid="!!lineError(line.key, 'quantity')"
                    @keydown.enter.prevent="addLine(index)"
                  />
                </FormField>
                <FormField :id="`${line.key}-unit`" :label="t('invoices.editor.unit')" :error="lineError(line.key, 'unit_code')">
                  <SelectInput :id="`${line.key}-unit`" v-model="line.unit_code" :options="units" />
                </FormField>
                <FormField :id="`${line.key}-price`" :label="t('invoices.editor.unitPrice')" :error="lineError(line.key, 'unit_price')">
                  <MoneyInput
                    :id="`${line.key}-price`"
                    v-model="line.unit_price"
                    :invalid="!!lineError(line.key, 'unit_price')"
                    @keydown.enter.prevent="addLine(index)"
                  />
                </FormField>
                <FormField :id="`${line.key}-discount`" :label="t('invoices.editor.discount')" :error="lineError(line.key, 'discount_rate')">
                  <DecimalInput
                    :id="`${line.key}-discount`"
                    v-model="line.discount_rate"
                    :scale="4"
                    suffix="%"
                    :invalid="!!lineError(line.key, 'discount_rate')"
                    @keydown.enter.prevent="addLine(index)"
                  />
                </FormField>
                <FormField :id="`${line.key}-tax`" :label="t('invoices.editor.tax')" :error="lineError(line.key, 'tax_id')">
                  <SelectInput :id="`${line.key}-tax`" v-model="line.tax_id" :options="taxOptions" />
                </FormField>
              </div>

              <div class="flex items-center justify-end gap-3 text-sm">
                <span class="text-muted-foreground">{{ t('invoices.editor.lineTotal') }}</span>
                <span class="min-w-28 text-right font-medium tabular-nums">{{ lineTotal(line) }}</span>
              </div>
            </div>

            <div>
              <Button variant="outline" size="sm" @click="addLine()">
                <PlusIcon />
                {{ t('invoices.editor.addLine') }}
              </Button>
            </div>
          </div>
        </FormSection>

        <FormSection :title="t('invoices.editor.withholdings')" :description="t('invoices.editor.withholdingsDescription')">
          <p v-if="withholdingOptions.length === 0" class="text-sm text-muted-foreground">{{ t('invoices.editor.noWithholdings') }}</p>
          <div v-else class="grid gap-2.5 sm:grid-cols-2">
            <label
              v-for="withholding in withholdingOptions"
              :key="withholding.id"
              :for="`withholding-${withholding.id}`"
              class="flex items-center gap-2.5 text-sm"
            >
              <Checkbox
                :id="`withholding-${withholding.id}`"
                :model-value="form.withholding_ids.includes(withholding.id)"
                @update:model-value="(checked) => toggleWithholding(withholding.id, checked === true)"
              />
              {{ withholding.name }}
            </label>
          </div>
        </FormSection>
      </div>

      <div class="lg:sticky lg:top-20">
        <TotalsPanel
          :totals="calculation?.totals ?? null"
          :taxes="calculation?.taxes ?? []"
          :withholdings="calculation?.withholdings ?? []"
          :pending="previewPending"
          :notice="previewNotice"
        />
        <div
          v-if="remaining !== null"
          class="mt-3 flex items-center justify-between rounded-lg border px-4 py-3 text-sm"
          :class="exceeds ? 'border-destructive/50 text-destructive' : ''"
        >
          <span :class="exceeds ? '' : 'text-muted-foreground'">{{ t('notes.reference.remaining') }}</span>
          <span class="font-medium tabular-nums">{{ formatMoney(remaining) }}</span>
        </div>
      </div>
    </div>

    <IssueDialog
      v-model:open="issueOpen"
      :numbering="numberingName"
      :customer-id="form.contact?.id ?? null"
      :pending="issuing.isPending.value"
      :error="issuing.error.value"
      :kind="type"
      :voids="voiding ? (related?.full_number ?? null) : null"
      @confirm="issuing.mutate()"
    />

    <ConfirmDialog
      v-model:open="deleteOpen"
      :title="isNote ? t(`notes.editor.deleteTitle.${type}`) : t('invoices.editor.deleteTitle')"
      :description="isNote ? t('notes.editor.deleteDescription') : t('invoices.editor.deleteDescription')"
      :confirm-label="t('common.delete')"
      :pending="removing.isPending.value"
      :error="removing.error.value"
      @confirm="removing.mutate()"
    />
  </div>
</template>
