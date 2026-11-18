<script setup lang="ts">
import type { DeliveredFile, Invoice } from '@/api/types'
import { DownloadIcon, EyeIcon, FileTextIcon, InboxIcon, RefreshCwIcon, SendIcon, TriangleAlertIcon } from '@lucide/vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { saveBlob } from '@/api/client'
import { invoicesApi } from '@/api/resources'
import FormError from '@/components/FormError.vue'
import FormSection from '@/components/FormSection.vue'
import DeliveryStatusBadge from '@/components/invoice/DeliveryStatusBadge.vue'
import PdfDialog from '@/components/invoice/PdfDialog.vue'
import SendAgainDialog from '@/components/invoice/SendAgainDialog.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useSession } from '@/composables/useSession'
import { formatDateTime, formatInteger } from '@/lib/format'
import { invalidateTenant, tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * Customer delivery of an accepted invoice: its status apart from the electronic one, the
 * recipient and the time, the files the customer gets, and View PDF, Download PDF, Download package
 * and Send again (Regenerate PDF after the PDF failed). Every email of this project lands in the
 * local development mailbox; nothing leaves the machine.
 */
const props = defineProps<{
  invoice: Invoice
}>()

const { t } = useI18n()
const queryClient = useQueryClient()
const session = useSessionStore()
const { can } = useSession()

const delivery = computed(() => props.invoice.delivery)
const files = computed(() => delivery.value?.files ?? null)
const canManage = computed(() => can('manage-documents'))

/** What the delivery is doing, or why it has not started. */
const processing = computed(() => {
  const value = delivery.value

  if (!value || value.status === null) {
    return t('invoices.delivery.waiting')
  }

  if (value.status !== 'pending') {
    return null
  }

  if (value.retrying && value.next_attempt_at) {
    return t('invoices.delivery.retryingAt', { attempt: value.attempt, max: value.max_attempts, time: formatDateTime(value.next_attempt_at) })
  }

  return t(`invoices.delivery.processing.${value.step ?? 'attached_document'}`, { attempt: Math.max(1, value.attempt), max: value.max_attempts })
})

const time = computed(() => {
  const value = delivery.value

  if (!value) {
    return null
  }

  if (value.status === 'delivered' && value.delivered_at) {
    return { label: t('invoices.delivery.deliveredAt'), value: formatDateTime(value.delivered_at) }
  }

  return value.status_changed_at ? { label: t('invoices.delivery.updatedAt'), value: formatDateTime(value.status_changed_at) } : null
})

const fileRows = computed(() => {
  const value = files.value

  if (!value) {
    return []
  }

  const describe = (file: DeliveredFile | null): string => {
    if (!file) {
      return t('invoices.delivery.fileMissing')
    }

    return file.pages
      ? t('invoices.delivery.filePages', { pages: file.pages, size: formatInteger(file.size) })
      : t('invoices.delivery.fileSize', { size: formatInteger(file.size) })
  }

  return [
    { key: 'attached', label: t('invoices.delivery.fileAttached'), name: value.attached_document?.package_name ?? null, detail: describe(value.attached_document) },
    { key: 'pdf', label: t('invoices.delivery.filePdf'), name: value.pdf?.package_name ?? null, detail: describe(value.pdf) },
    { key: 'package', label: t('invoices.delivery.filePackage'), name: value.package?.package_name ?? null, detail: describe(value.package) },
  ]
})

// Dialogs.
const pdfOpen = ref(false)
const sendOpen = ref(false)

// Downloads.
const downloadPdf = useMutation({
  mutationFn: async () => {
    saveBlob(await invoicesApi.pdf(props.invoice.id), files.value?.pdf?.file_name ?? `${props.invoice.full_number}.pdf`)
  },
})

const downloadPackage = useMutation({
  mutationFn: async () => {
    saveBlob(await invoicesApi.package(props.invoice.id), files.value?.package?.file_name ?? `${props.invoice.full_number}.zip`)
  },
})

// Acting on the delivery. The page polls again until the new run ends.
async function store(updated: Invoice): Promise<void> {
  queryClient.setQueryData(tenantKey(session.currentCompanyId, 'invoices', 'detail', updated.id), updated)
  await invalidateTenant('invoices')
}

const regenerate = useMutation({
  mutationFn: () => invoicesApi.regeneratePdf(props.invoice.id),
  onSuccess: store,
})

const error = computed(() => downloadPdf.error.value ?? downloadPackage.error.value ?? regenerate.error.value)
</script>

<template>
  <FormSection :title="t('invoices.delivery.title')" :description="t('invoices.delivery.description')">
    <div class="grid gap-5">
      <div class="flex flex-wrap items-center gap-2">
        <DeliveryStatusBadge :status="delivery?.status ?? null" />
        <Badge variant="secondary">
          <InboxIcon />
          {{ t('invoices.delivery.localMailbox') }}
        </Badge>
      </div>

      <div class="grid gap-3" aria-live="polite">
        <p v-if="processing" class="text-sm text-muted-foreground">{{ processing }}</p>

        <Alert v-if="delivery?.status === 'failed'" variant="destructive">
          <TriangleAlertIcon />
          <AlertDescription>
            <p>{{ t('invoices.delivery.failedAt', { step: t(`invoices.view.events.steps.${delivery.step ?? 'email'}`) }) }}</p>
            <p v-if="delivery.error" class="font-mono text-xs break-words">{{ delivery.error }}</p>
          </AlertDescription>
        </Alert>

        <p v-if="delivery?.status === 'not_applicable'" class="text-sm text-muted-foreground">{{ t('invoices.delivery.notApplicable') }}</p>
      </div>

      <dl class="grid gap-4 sm:grid-cols-2">
        <div class="grid gap-0.5">
          <dt class="text-xs text-muted-foreground">{{ t('invoices.delivery.recipient') }}</dt>
          <dd class="break-all">{{ delivery?.recipient ?? t('invoices.delivery.noRecipient') }}</dd>
        </div>
        <div v-if="time" class="grid gap-0.5">
          <dt class="text-xs text-muted-foreground">{{ time.label }}</dt>
          <dd class="tabular-nums">{{ time.value }}</dd>
        </div>
      </dl>

      <div v-if="fileRows.length > 0" class="grid gap-1.5">
        <p class="text-xs text-muted-foreground">{{ t('invoices.delivery.files') }}</p>
        <ul class="grid gap-1.5 text-sm">
          <li v-for="row in fileRows" :key="row.key" class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
            <FileTextIcon class="size-3.5 shrink-0 self-center text-muted-foreground" aria-hidden="true" />
            <span class="font-medium">{{ row.label }}</span>
            <span v-if="row.name" class="font-mono text-xs break-all text-muted-foreground">{{ row.name }}</span>
            <span class="text-xs text-muted-foreground tabular-nums">{{ row.detail }}</span>
          </li>
        </ul>
      </div>

      <div class="flex flex-wrap gap-2">
        <Button variant="outline" size="sm" :disabled="!files?.pdf" @click="pdfOpen = true">
          <EyeIcon />
          {{ t('invoices.delivery.viewPdf') }}
        </Button>
        <Button variant="outline" size="sm" :disabled="!files?.pdf || downloadPdf.isPending.value" @click="downloadPdf.mutate()">
          <DownloadIcon />
          {{ t('invoices.delivery.downloadPdf') }}
        </Button>
        <Button variant="outline" size="sm" :disabled="!files?.package || downloadPackage.isPending.value" @click="downloadPackage.mutate()">
          <DownloadIcon />
          {{ t('invoices.delivery.downloadPackage') }}
        </Button>
        <Button v-if="canManage" size="sm" :disabled="!delivery?.can_send" @click="sendOpen = true">
          <SendIcon />
          {{ t('invoices.delivery.sendAgain') }}
        </Button>
        <Button v-if="canManage && delivery?.can_regenerate_pdf" size="sm" variant="secondary" :disabled="regenerate.isPending.value" @click="regenerate.mutate()">
          <RefreshCwIcon />
          {{ regenerate.isPending.value ? t('invoices.delivery.regenerating') : t('invoices.delivery.regeneratePdf') }}
        </Button>
      </div>

      <FormError :error="error" />
    </div>

    <PdfDialog v-model:open="pdfOpen" :invoice="invoice" @download="downloadPdf.mutate()" />
    <SendAgainDialog v-model:open="sendOpen" :invoice="invoice" @sent="store" />
  </FormSection>
</template>
