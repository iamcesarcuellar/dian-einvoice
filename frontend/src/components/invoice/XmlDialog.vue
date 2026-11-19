<script setup lang="ts">
import type { Invoice } from '@/api/types'
import { DownloadIcon } from '@lucide/vue'
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { documentsApi } from '@/api/resources'
import FormError from '@/components/FormError.vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { DOCUMENT_RESOURCES } from '@/lib/documents'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/** Shows the stored XML of an issued invoice, as the API serves it: indented by the server. */
const props = defineProps<{
  invoice: Invoice
}>()

const emit = defineEmits<{
  download: []
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
const session = useSessionStore()

const {
  data: xml,
  error,
  isLoading,
} = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, DOCUMENT_RESOURCES[props.invoice.type], 'xml', props.invoice.id, props.invoice.xml.sha256)),
  queryFn: () => documentsApi(props.invoice.type).xml(props.invoice.id),
  enabled: open,
  staleTime: Number.POSITIVE_INFINITY,
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="grid max-h-[90vh] grid-rows-[auto_minmax(0,1fr)_auto] sm:max-w-4xl">
      <DialogHeader>
        <DialogTitle>{{ t('invoices.dian.xmlTitle', { number: invoice.full_number }) }}</DialogTitle>
        <DialogDescription>{{ t('invoices.dian.xmlDescription') }}</DialogDescription>
      </DialogHeader>

      <div class="min-h-0">
        <FormError :error="error" />
        <p v-if="isLoading" class="text-muted-foreground">{{ t('common.loading') }}</p>
        <pre
          v-else-if="xml"
          class="h-full max-h-[60vh] overflow-auto rounded-lg border bg-muted/40 p-4 font-mono text-xs leading-relaxed whitespace-pre"
          tabindex="0"
          :aria-label="t('invoices.dian.xmlTitle', { number: invoice.full_number })"
          >{{ xml }}</pre>
      </div>

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">{{ t('common.close') }}</Button>
        <Button type="button" @click="emit('download')">
          <DownloadIcon />
          {{ t('invoices.dian.downloadXml') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
