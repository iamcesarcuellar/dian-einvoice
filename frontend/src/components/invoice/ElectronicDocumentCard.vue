<script setup lang="ts">
import type { Invoice, XmlStatus } from '@/api/types'
import { CheckIcon, CopyIcon, DownloadIcon, EyeIcon, FlaskConicalIcon, InfoIcon, RefreshCwIcon, TriangleAlertIcon } from '@lucide/vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { saveTextFile } from '@/api/client'
import { invoicesApi } from '@/api/resources'
import FormError from '@/components/FormError.vue'
import FormSection from '@/components/FormSection.vue'
import QrCode from '@/components/invoice/QrCode.vue'
import XmlDialog from '@/components/invoice/XmlDialog.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useSession } from '@/composables/useSession'
import { formatDateTime, formatInteger } from '@/lib/format'
import { invalidateTenant, tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * The electronic side of an issued invoice: its CUFE, the QR code of its DIAN verification URL,
 * the environment and its unsigned UBL XML, which can be viewed, downloaded or regenerated.
 */
const props = defineProps<{
  invoice: Invoice
}>()

const { t } = useI18n()
const queryClient = useQueryClient()
const session = useSessionStore()
const { can } = useSession()

const status = computed<XmlStatus>(() => props.invoice.xml.status ?? 'pending')
const canRegenerate = computed(() => can('manage-documents') && status.value !== 'generated')

const statusVariant: Record<XmlStatus, 'secondary' | 'outline' | 'destructive'> = {
  generated: 'secondary',
  pending: 'outline',
  failed: 'destructive',
}

/** Why the last generation failed, from the timeline. */
const failure = computed(() => {
  if (status.value !== 'failed') {
    return null
  }

  const event = [...(props.invoice.events ?? [])].reverse().find((item) => item.type === 'xml_failed')
  const reason = event?.payload?.reason

  return typeof reason === 'string' ? reason : null
})

const xmlDetails = computed(() => {
  const xml = props.invoice.xml

  if (status.value !== 'generated' || xml.size === null) {
    return null
  }

  return t('invoices.dian.xmlDetails', { date: formatDateTime(xml.generated_at), size: formatInteger(xml.size) })
})

// Copying the CUFE.
const copied = ref(false)
let copiedTimer: ReturnType<typeof setTimeout> | undefined

async function copyCufe(): Promise<void> {
  if (!props.invoice.cufe) {
    return
  }

  await navigator.clipboard.writeText(props.invoice.cufe)
  copied.value = true
  clearTimeout(copiedTimer)
  copiedTimer = setTimeout(() => (copied.value = false), 2000)
}

// Viewing, downloading and regenerating the XML.
const viewOpen = ref(false)

const download = useMutation({
  mutationFn: async () => {
    saveTextFile(await invoicesApi.xml(props.invoice.id), props.invoice.xml.file_name ?? 'invoice.xml', 'application/xml')
  },
})

const regenerate = useMutation({
  mutationFn: () => invoicesApi.regenerateXml(props.invoice.id),
  onSuccess: async (updated) => {
    queryClient.setQueryData(tenantKey(session.currentCompanyId, 'invoices', 'detail', updated.id), updated)
    await invalidateTenant('invoices')
  },
})

const error = computed(() => regenerate.error.value ?? download.error.value)

onBeforeUnmount(() => clearTimeout(copiedTimer))
</script>

<template>
  <FormSection :title="t('invoices.dian.title')" :description="t('invoices.dian.description')">
    <div class="grid gap-5">
      <div class="grid items-start gap-5 sm:grid-cols-[minmax(0,1fr)_auto]">
        <div class="grid min-w-0 gap-4">
          <div class="flex flex-wrap items-center gap-2">
            <Badge variant="secondary">
              <FlaskConicalIcon />
              {{ t('invoices.dian.testEnvironment') }}
            </Badge>
            <Badge :variant="statusVariant[status]">
              {{ t(`invoices.dian.xmlStatus.${status}`) }}
            </Badge>
          </div>

          <div class="grid gap-1.5">
            <p class="text-xs text-muted-foreground">{{ t('invoices.dian.cufe') }}</p>
            <div class="flex items-start gap-2">
              <code class="min-w-0 flex-1 rounded-md bg-muted/50 px-2.5 py-2 font-mono text-xs leading-relaxed break-all">{{ invoice.cufe ?? '—' }}</code>
              <Button
                v-if="invoice.cufe"
                variant="outline"
                size="icon-sm"
                :aria-label="copied ? t('invoices.dian.copied') : t('invoices.dian.copyCufe')"
                :title="copied ? t('invoices.dian.copied') : t('invoices.dian.copyCufe')"
                @click="copyCufe"
              >
                <CheckIcon v-if="copied" />
                <CopyIcon v-else />
              </Button>
            </div>
            <p class="text-xs text-muted-foreground" role="status">{{ copied ? t('invoices.dian.copied') : '' }}</p>
          </div>

          <div class="grid gap-1.5">
            <p class="text-xs text-muted-foreground">{{ t('invoices.dian.xml') }}</p>
            <p v-if="xmlDetails" class="text-sm">{{ xmlDetails }}</p>
            <p v-else-if="status === 'pending'" class="text-sm text-muted-foreground">{{ t('invoices.dian.xmlPending') }}</p>
            <Alert v-if="status === 'failed'" variant="destructive">
              <TriangleAlertIcon />
              <AlertDescription>
                <p>{{ t('invoices.dian.xmlFailed') }}</p>
                <p v-if="failure" class="font-mono text-xs break-words">{{ failure }}</p>
              </AlertDescription>
            </Alert>
          </div>

          <div class="flex flex-wrap gap-2">
            <Button variant="outline" size="sm" :disabled="status !== 'generated'" @click="viewOpen = true">
              <EyeIcon />
              {{ t('invoices.dian.viewXml') }}
            </Button>
            <Button variant="outline" size="sm" :disabled="status !== 'generated' || download.isPending.value" @click="download.mutate()">
              <DownloadIcon />
              {{ t('invoices.dian.downloadXml') }}
            </Button>
            <Button v-if="canRegenerate" size="sm" :disabled="regenerate.isPending.value" @click="regenerate.mutate()">
              <RefreshCwIcon />
              {{ regenerate.isPending.value ? t('invoices.dian.regenerating') : t('invoices.dian.regenerateXml') }}
            </Button>
          </div>

          <FormError :error="error" />
        </div>

        <figure v-if="invoice.qr_url" class="grid justify-items-center gap-1.5">
          <QrCode :value="invoice.qr_url" :label="t('invoices.dian.qrLabel', { number: invoice.full_number })" class="size-36 border" />
          <figcaption class="text-xs text-muted-foreground">{{ t('invoices.dian.qrCaption') }}</figcaption>
        </figure>
      </div>

      <Alert>
        <InfoIcon />
        <AlertDescription>{{ t('invoices.dian.simulatorNote') }}</AlertDescription>
      </Alert>
    </div>

    <XmlDialog v-model:open="viewOpen" :invoice="invoice" @download="download.mutate()" />
  </FormSection>
</template>
