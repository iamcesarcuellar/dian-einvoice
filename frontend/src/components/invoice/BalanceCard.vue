<script setup lang="ts">
import type { Invoice, RelatedNote } from '@/api/types'
import { BanIcon, FileMinusIcon, FilePlusIcon } from '@lucide/vue'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import FormSection from '@/components/FormSection.vue'
import DocumentStatusBadge from '@/components/invoice/DocumentStatusBadge.vue'
import ElectronicStatusBadge from '@/components/invoice/ElectronicStatusBadge.vue'
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
import { DOCUMENT_ROUTES } from '@/lib/documents'
import { formatMoney } from '@/lib/format'

/**
 * Balance and related documents of an accepted invoice: total, credited, debited and balance, all
 * calculated by the API from the notes the local DIAN simulator accepted; its notes; and the
 * actions that create them. Void opens a credit note for all that is left to credit, with the void
 * concept chosen, after a confirmation. A voided invoice takes no more notes, so it has no actions.
 */
const props = defineProps<{
  invoice: Invoice
}>()

const { t } = useI18n()
const router = useRouter()
const { can } = useSession()
const { label } = useReference()

const balance = computed(() => props.invoice.balance ?? null)
const notes = computed(() => props.invoice.related_notes ?? [])
const voided = computed(() => props.invoice.balance_status === 'voided')
const canCorrect = computed(() => can('manage-documents') && !voided.value)
const canCredit = computed(() => canCorrect.value && Number(balance.value?.remaining_to_credit ?? '0') > 0)
const hasPending = computed(() => Number(balance.value?.pending_credits ?? '0') > 0)

const voidOpen = ref(false)

function conceptOf(note: RelatedNote): string {
  if (!note.reason_code) {
    return '—'
  }

  return label(note.type === 'credit_note' ? 'creditNoteConcepts' : 'debitNoteConcepts', note.reason_code)
}

function create(type: 'credit_note' | 'debit_note', voidInvoice = false): void {
  void router.push({
    name: DOCUMENT_ROUTES[type].create,
    query: { invoice: props.invoice.id, ...(voidInvoice ? { void: '1' } : {}) },
  })
}
</script>

<template>
  <FormSection :title="t('notes.balance.title')" :description="t('notes.balance.description')">
    <div class="grid gap-5">
      <dl v-if="balance" class="grid gap-3 sm:grid-cols-4">
        <div class="grid gap-0.5 rounded-lg bg-muted/50 p-3">
          <dt class="text-xs text-muted-foreground">{{ t('notes.balance.total') }}</dt>
          <dd class="font-medium tabular-nums">{{ formatMoney(balance.total) }}</dd>
        </div>
        <div class="grid gap-0.5 rounded-lg bg-muted/50 p-3">
          <dt class="text-xs text-muted-foreground">{{ t('notes.balance.credited') }}</dt>
          <dd class="font-medium tabular-nums">{{ formatMoney(balance.credited) }}</dd>
        </div>
        <div class="grid gap-0.5 rounded-lg bg-muted/50 p-3">
          <dt class="text-xs text-muted-foreground">{{ t('notes.balance.debited') }}</dt>
          <dd class="font-medium tabular-nums">{{ formatMoney(balance.debited) }}</dd>
        </div>
        <div class="grid gap-0.5 rounded-lg bg-muted/50 p-3">
          <dt class="text-xs text-muted-foreground">{{ t('notes.balance.balance') }}</dt>
          <dd class="font-semibold tabular-nums">{{ formatMoney(balance.balance) }}</dd>
        </div>
      </dl>

      <p v-if="balance && !voided" class="-mt-2 text-xs text-muted-foreground">
        {{ t('notes.balance.remaining', { amount: formatMoney(balance.remaining_to_credit) }) }}
        <template v-if="hasPending">· {{ t('notes.balance.pending', { amount: formatMoney(balance.pending_credits) }) }}</template>
      </p>
      <p v-if="voided" class="-mt-2 text-sm text-muted-foreground">{{ t('notes.balance.voided') }}</p>

      <div class="grid gap-2">
        <p class="text-sm font-medium">{{ t('notes.balance.notes') }}</p>
        <p v-if="notes.length === 0" class="text-sm text-muted-foreground">{{ t('notes.balance.noNotes') }}</p>
        <div v-else class="-mx-6 overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow class="hover:bg-transparent">
                <TableHead class="pl-6">{{ t('notes.balance.columns.number') }}</TableHead>
                <TableHead>{{ t('notes.balance.columns.concept') }}</TableHead>
                <TableHead class="text-right">{{ t('notes.balance.columns.total') }}</TableHead>
                <TableHead class="pr-6">{{ t('notes.balance.columns.status') }}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableRow v-for="note in notes" :key="note.id">
                <TableCell class="pl-6 whitespace-nowrap">
                  <RouterLink :to="{ name: DOCUMENT_ROUTES[note.type].detail, params: { id: note.id } }" class="font-medium tabular-nums hover:underline">
                    {{ note.full_number ?? t('invoices.draft') }}
                  </RouterLink>
                  <span class="block text-xs text-muted-foreground">{{ t(`notes.one.${note.type}`) }}</span>
                </TableCell>
                <TableCell>
                  <span class="block max-w-64 truncate">{{ conceptOf(note) }}</span>
                </TableCell>
                <TableCell class="text-right whitespace-nowrap tabular-nums">
                  {{ note.type === 'credit_note' ? '−' : '+' }} {{ formatMoney(note.payable_amount) }}
                </TableCell>
                <TableCell class="pr-6">
                  <ElectronicStatusBadge v-if="note.electronic_status" :status="note.electronic_status" />
                  <DocumentStatusBadge v-else :status="note.status" />
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>
      </div>

      <div v-if="canCorrect" class="flex flex-wrap gap-2">
        <Button variant="outline" :disabled="!canCredit" @click="create('credit_note')">
          <FileMinusIcon />
          {{ t('notes.balance.createCredit') }}
        </Button>
        <Button variant="outline" :disabled="!canCredit" @click="voidOpen = true">
          <BanIcon />
          {{ t('notes.balance.void') }}
        </Button>
        <Button variant="outline" @click="create('debit_note')">
          <FilePlusIcon />
          {{ t('notes.balance.createDebit') }}
        </Button>
      </div>
    </div>

    <ConfirmDialog
      v-model:open="voidOpen"
      :title="t('notes.balance.voidTitle', { number: invoice.full_number })"
      :description="t('notes.balance.voidDescription', { amount: formatMoney(balance?.remaining_to_credit ?? '0') })"
      :confirm-label="t('notes.balance.voidConfirm')"
      @confirm="voidOpen = false; create('credit_note', true)"
    />
  </FormSection>
</template>
