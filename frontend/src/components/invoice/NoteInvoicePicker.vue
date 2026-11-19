<script setup lang="ts">
import type { Invoice, NoteType } from '@/api/types'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { invoicesApi } from '@/api/resources'
import FormField from '@/components/FormField.vue'
import FormSection from '@/components/FormSection.vue'
import PageHeader from '@/components/PageHeader.vue'
import SearchSelect from '@/components/SearchSelect.vue'
import { documentCrumbs, DOCUMENT_ROUTES } from '@/lib/documents'
import { formatDate, formatMoney } from '@/lib/format'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * A new note started from its list: first the accepted invoice it corrects. Choosing one loads the
 * note prefilled from it (?invoice=).
 */
const props = defineProps<{
  kind: NoteType
}>()

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()

const chosen = ref<Invoice | null>(null)
const queryKey = computed(() => tenantKey(session.currentCompanyId, 'invoices', 'accepted-search'))

/** Accepted invoices that still take notes: a voided one does not. */
async function search(term: string): Promise<Invoice[]> {
  const page = await invoicesApi.list({ search: term || undefined, status: 'issued', electronic_status: 'accepted', per_page: 20 })

  return page.data.filter((invoice) => invoice.balance_status !== 'voided')
}

const crumbs = computed(() => documentCrumbs(props.kind, t(`notes.editor.newTitle.${props.kind}`), t))

watch(chosen, (invoice) => {
  if (invoice) {
    void router.replace({ name: DOCUMENT_ROUTES[props.kind].create, query: { invoice: invoice.id } })
  }
})
</script>

<template>
  <div class="grid gap-6">
    <PageHeader :title="t(`notes.editor.newTitle.${kind}`)" :description="t(`notes.editor.description.${kind}`)" :breadcrumbs="crumbs" />

    <FormSection :title="t('notes.pick.title')" :description="t('notes.pick.description')">
      <FormField id="note-invoice" :label="t('notes.pick.label')">
        <SearchSelect
          id="note-invoice"
          v-model="chosen"
          :query-key="queryKey"
          :search="search"
          by="id"
          :label="(invoice) => invoice.full_number ?? ''"
          :placeholder="t('notes.pick.placeholder')"
          :empty-text="t('notes.pick.empty')"
          list-class="min-w-96"
        >
          <template #option="{ option }">
            <span class="grid min-w-0">
              <span class="truncate tabular-nums">{{ option.full_number }} · {{ formatMoney(option.totals.payable_amount) }}</span>
              <span class="truncate text-xs text-muted-foreground">{{ option.customer_name }} · {{ formatDate(option.issue_date) }}</span>
            </span>
          </template>
        </SearchSelect>
      </FormField>
    </FormSection>
  </div>
</template>
