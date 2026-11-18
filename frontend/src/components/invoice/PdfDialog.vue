<script setup lang="ts">
import type { Invoice } from '@/api/types'
import { DownloadIcon } from '@lucide/vue'
import { useQuery } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { documentsApi } from '@/api/resources'
import FormError from '@/components/FormError.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { DOCUMENT_RESOURCES } from '@/lib/documents'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * The PDF of an invoice in the viewer of the browser. The API needs the session and the company
 * header, so the PDF is fetched as a Blob and shown from an object URL, released when it changes.
 */
const props = defineProps<{
  invoice: Invoice
}>()

const emit = defineEmits<{
  download: []
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
const session = useSessionStore()

const sha256 = computed(() => props.invoice.delivery?.files?.pdf?.sha256 ?? null)

const { data: pdf, error, isLoading } = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, DOCUMENT_RESOURCES[props.invoice.type], 'pdf', props.invoice.id, sha256.value)),
  queryFn: () => documentsApi(props.invoice.type).pdf(props.invoice.id),
  enabled: computed(() => open.value && sha256.value !== null),
  staleTime: Number.POSITIVE_INFINITY,
})

const url = computed(() => (pdf.value ? URL.createObjectURL(pdf.value) : null))

watch(url, (_, previous) => {
  if (previous) {
    URL.revokeObjectURL(previous)
  }
})

onBeforeUnmount(() => {
  if (url.value) {
    URL.revokeObjectURL(url.value)
  }
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="grid max-h-[94vh] grid-rows-[auto_minmax(0,1fr)_auto] sm:max-w-5xl">
      <DialogHeader>
        <DialogTitle>{{ t('invoices.delivery.pdfTitle', { number: invoice.full_number }) }}</DialogTitle>
        <DialogDescription>{{ t('invoices.delivery.pdfDescription') }}</DialogDescription>
      </DialogHeader>

      <div class="min-h-0">
        <FormError :error="error" />
        <p v-if="isLoading" class="text-muted-foreground">{{ t('common.loading') }}</p>
        <iframe
          v-else-if="url"
          :src="url"
          :title="t('invoices.delivery.pdfFrame', { number: invoice.full_number })"
          class="h-[72vh] w-full rounded-lg border bg-muted/40"
        />
      </div>

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">{{ t('common.close') }}</Button>
        <Button type="button" @click="emit('download')">
          <DownloadIcon />
          {{ t('invoices.delivery.downloadPdf') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
