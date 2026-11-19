<script setup lang="ts">
import type { DocumentType, Invoice } from '@/api/types'
import { CircleAlertIcon, InfoIcon } from '@lucide/vue'
import { useQuery } from '@tanstack/vue-query'
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { creditNotesApi, debitNotesApi, documentsApi } from '@/api/resources'
import InvoiceEditor from '@/components/invoice/InvoiceEditor.vue'
import InvoiceView from '@/components/invoice/InvoiceView.vue'
import NoteInvoicePicker from '@/components/invoice/NoteInvoicePicker.vue'
import PageSkeleton from '@/components/PageSkeleton.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { useSession } from '@/composables/useSession'
import { ALL_DOCUMENT_RESOURCES, DOCUMENT_RESOURCES, DOCUMENT_ROUTES } from '@/lib/documents'
import { invalidateTenant, tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * The page of one invoice, credit note or debit note, and the editor of a new one. Drafts open in
 * the editor for roles that can change them; issued documents, and drafts for viewers, open
 * read-only. A new note comes from its invoice (?invoice=, and ?void=1 for a void): the API
 * prefills it; without an invoice the page first asks for one. While the electronic document chain
 * of an issued document runs, and then its delivery to the customer, the page asks for it every 2
 * seconds, and stops once both end.
 */
const props = withDefaults(defineProps<{
  kind?: DocumentType
}>(), {
  kind: 'invoice',
})

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const { can } = useSession()

const canManage = computed(() => can('manage-documents'))
const documentId = computed(() => (typeof route.params.id === 'string' ? route.params.id : null))
const isNote = computed(() => props.kind !== 'invoice')
const sourceInvoiceId = computed(() => (typeof route.query.invoice === 'string' ? route.query.invoice : null))
const voidInvoice = computed(() => props.kind === 'credit_note' && route.query.void === '1')

const POLL_MS = 2000

function running(value: Invoice | undefined): boolean {
  const electronic = value?.electronic

  if (!electronic) {
    return false
  }

  if (!electronic.final) {
    return true
  }

  // An accepted document goes on with its delivery: AttachedDocument, PDF and email.
  return electronic.status === 'accepted' && value?.delivery?.final !== true
}

const { data: invoice } = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, DOCUMENT_RESOURCES[props.kind], 'detail', documentId.value)),
  queryFn: () => documentsApi(props.kind).get(documentId.value as string),
  enabled: computed(() => documentId.value !== null),
  refetchInterval: (query) => (running(query.state.data) ? POLL_MS : false),
})

// A new note: its draft prefilled from the invoice, read again every time the editor opens.
const prefill = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, DOCUMENT_RESOURCES[props.kind], 'prefill', sourceInvoiceId.value, voidInvoice.value)),
  queryFn: () => (props.kind === 'credit_note' ? creditNotesApi : debitNotesApi).prefill(sourceInvoiceId.value as string, voidInvoice.value),
  enabled: computed(() => isNote.value && documentId.value === null && sourceInvoiceId.value !== null),
  staleTime: 0,
  gcTime: 0,
  retry: false,
})

const prefillKey = computed(() => `${props.kind}-${sourceInvoiceId.value}-${voidInvoice.value ? 'void' : 'note'}`)
const prefillError = computed(() => (prefill.error.value instanceof Error ? prefill.error.value.message : t('notes.pick.failed')))

// When the chain or the delivery ends, lists that show this document refresh too, invoices
// included: an accepted note changes the balance of its invoice.
watch(() => running(invoice.value), (isRunning, wasRunning) => {
  if (!isRunning && wasRunning) {
    void invalidateTenant(...ALL_DOCUMENT_RESOURCES)
  }
})

// Another company has other documents: go back to the list.
watch(() => session.currentCompanyId, () => {
  void router.push({ name: DOCUMENT_ROUTES[props.kind].list })
})
</script>

<template>
  <template v-if="documentId === null">
    <Alert v-if="!canManage">
      <InfoIcon />
      <AlertDescription>{{ t('invoices.editor.readOnly') }}</AlertDescription>
    </Alert>
    <template v-else-if="isNote && kind !== 'invoice'">
      <NoteInvoicePicker v-if="sourceInvoiceId === null" :kind="kind" />
      <PageSkeleton v-else-if="prefill.isPending.value" />
      <div v-else-if="prefill.isError.value" class="grid gap-4">
        <Alert variant="destructive">
          <CircleAlertIcon />
          <AlertDescription>{{ prefillError }}</AlertDescription>
        </Alert>
        <div>
          <Button variant="outline" as-child>
            <RouterLink :to="{ name: 'invoice', params: { id: sourceInvoiceId } }">{{ t('notes.pick.backToInvoice') }}</RouterLink>
          </Button>
        </div>
      </div>
      <InvoiceEditor v-else-if="prefill.data.value" :key="prefillKey" :invoice="null" :kind="kind" :prefill="prefill.data.value" />
    </template>
    <InvoiceEditor v-else :invoice="null" />
  </template>
  <PageSkeleton v-else-if="!invoice" />
  <InvoiceEditor v-else-if="invoice.status === 'draft' && canManage" :key="invoice.id" :invoice="invoice" />
  <InvoiceView v-else :invoice="invoice" />
</template>
