<script setup lang="ts">
import { InfoIcon } from '@lucide/vue'
import { useQuery } from '@tanstack/vue-query'
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { invoicesApi } from '@/api/resources'
import InvoiceEditor from '@/components/invoice/InvoiceEditor.vue'
import InvoiceView from '@/components/invoice/InvoiceView.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { useSession } from '@/composables/useSession'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * /invoices/new and /invoices/:id. Drafts open in the editor for roles that can change them;
 * issued invoices, and drafts for viewers, open read-only.
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const { can } = useSession()

const canManage = computed(() => can('manage-documents'))
const invoiceId = computed(() => (typeof route.params.id === 'string' ? route.params.id : null))

const { data: invoice } = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, 'invoices', 'detail', invoiceId.value)),
  queryFn: () => invoicesApi.get(invoiceId.value as string),
  enabled: computed(() => invoiceId.value !== null),
})

// Another company has other invoices: go back to the list.
watch(() => session.currentCompanyId, () => {
  void router.push({ name: 'invoices' })
})
</script>

<template>
  <template v-if="invoiceId === null">
    <InvoiceEditor v-if="canManage" :invoice="null" />
    <Alert v-else>
      <InfoIcon />
      <AlertDescription>{{ t('invoices.editor.readOnly') }}</AlertDescription>
    </Alert>
  </template>
  <p v-else-if="!invoice" class="text-muted-foreground">{{ t('common.loading') }}</p>
  <InvoiceEditor v-else-if="invoice.status === 'draft' && canManage" :key="invoice.id" :invoice="invoice" />
  <InvoiceView v-else :invoice="invoice" />
</template>
