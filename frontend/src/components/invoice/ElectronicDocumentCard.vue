<script setup lang="ts">
import type { DianRuleMessage, Invoice } from '@/api/types'
import { CheckIcon, CopyIcon, DownloadIcon, EyeIcon, FlaskConicalIcon, InfoIcon, RefreshCwIcon, SendIcon, TriangleAlertIcon } from '@lucide/vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { saveTextFile } from '@/api/client'
import { invoicesApi } from '@/api/resources'
import FormError from '@/components/FormError.vue'
import FormSection from '@/components/FormSection.vue'
import DianResponseDialog from '@/components/invoice/DianResponseDialog.vue'
import ElectronicStatusBadge from '@/components/invoice/ElectronicStatusBadge.vue'
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
 * The electronic side of an issued invoice: the state of its chain with the answer of the local
 * DIAN simulator (rules when rejected), Retry now and Resend, the CUFE, the QR code of the DIAN
 * verification URL and the signed UBL XML. Accepted and rejected always mean by the local
 * simulator: no document of this project is registered with the DIAN.
 */
const props = defineProps<{
  invoice: Invoice
}>()

const { t } = useI18n()
const queryClient = useQueryClient()
const session = useSessionStore()
const { can } = useSession()

const electronic = computed(() => props.invoice.electronic)
const result = computed(() => electronic.value?.result ?? null)
const canManage = computed(() => can('manage-documents'))

/** What the chain is doing while it runs. */
const processing = computed(() => {
  const value = electronic.value

  if (!value || value.final) {
    return null
  }

  if (value.retrying && value.next_attempt_at) {
    return t('invoices.dian.retryingAt', { attempt: value.attempt, max: value.max_attempts, time: formatDateTime(value.next_attempt_at) })
  }

  return t(`invoices.dian.processing.${value.status}`, { attempt: Math.max(1, value.attempt), max: value.max_attempts })
})

/** Why the chain failed, from the timeline. */
const failure = computed(() => {
  if (electronic.value?.status !== 'failed') {
    return null
  }

  const event = [...(props.invoice.events ?? [])].reverse().find((item) => item.type === 'xml_failed' || item.type === 'transmission_failed')
  const reason = event?.payload?.reason

  return typeof reason === 'string' ? reason : null
})

const xmlDetails = computed(() => {
  const xml = props.invoice.xml

  return xml.available && xml.size !== null
    ? t('invoices.dian.signedDetails', { date: formatDateTime(xml.signed_at), size: formatInteger(xml.size) })
    : null
})

function ruleLabel(message: DianRuleMessage): string {
  return message.rule ? `${message.rule} · ${message.message}` : message.message
}

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

// The XML and the response of the simulator.
const xmlOpen = ref(false)
const responseOpen = ref(false)

const downloadXml = useMutation({
  mutationFn: async () => {
    saveTextFile(await invoicesApi.xml(props.invoice.id), props.invoice.xml.file_name ?? 'invoice.xml', 'application/xml')
  },
})

const downloadResponse = useMutation({
  mutationFn: async () => {
    saveTextFile(await invoicesApi.dianResponse(props.invoice.id), result.value?.response_file_name ?? 'response.xml', 'application/xml')
  },
})

// Acting on the chain. The page polls again until the new run ends.
async function store(updated: Invoice): Promise<void> {
  queryClient.setQueryData(tenantKey(session.currentCompanyId, 'invoices', 'detail', updated.id), updated)
  await invalidateTenant('invoices')
}

const retry = useMutation({
  mutationFn: () => invoicesApi.retry(props.invoice.id),
  onSuccess: store,
})

const resend = useMutation({
  mutationFn: () => invoicesApi.resend(props.invoice.id),
  onSuccess: store,
})

const error = computed(() => retry.error.value ?? resend.error.value ?? downloadXml.error.value ?? downloadResponse.error.value)

onBeforeUnmount(() => clearTimeout(copiedTimer))
</script>

<template>
  <FormSection :title="t('invoices.dian.title')" :description="t('invoices.dian.description')">
    <div class="grid gap-5">
      <div class="grid items-start gap-5 sm:grid-cols-[minmax(0,1fr)_auto]">
        <div class="grid min-w-0 gap-4">
          <div class="flex flex-wrap items-center gap-2">
            <ElectronicStatusBadge v-if="electronic" :status="electronic.status" />
            <span class="text-xs text-muted-foreground">{{ t('invoices.dian.bySimulator') }}</span>
            <Badge variant="secondary">
              <FlaskConicalIcon />
              {{ t('invoices.dian.testEnvironment') }}
            </Badge>
          </div>

          <div v-if="electronic" class="grid gap-3" aria-live="polite">
            <p v-if="processing" class="text-sm text-muted-foreground">{{ processing }}</p>

            <template v-if="result && electronic.status === 'accepted'">
              <p class="text-sm">
                {{ t('invoices.dian.acceptedOn', { date: formatDateTime(result.responded_at), code: result.status_code }) }}
              </p>
              <div v-if="result.notifications.length > 0" class="grid gap-1.5">
                <p class="text-xs text-muted-foreground">{{ t('invoices.dian.notifications') }}</p>
                <ul class="grid gap-1 text-sm">
                  <li v-for="(message, index) in result.notifications" :key="index" class="break-words">
                    <span class="font-mono text-xs">{{ ruleLabel(message) }}</span>
                  </li>
                </ul>
              </div>
            </template>

            <Alert v-if="result && electronic.status === 'rejected'" variant="destructive">
              <TriangleAlertIcon />
              <AlertDescription>
                <p>{{ t('invoices.dian.rejectedOn', { date: formatDateTime(result.responded_at), code: result.status_code }) }}</p>
                <p class="mt-1 text-xs">{{ t('invoices.dian.rules') }}</p>
                <ul class="grid gap-1">
                  <li v-for="(message, index) in result.rules" :key="index" class="font-mono text-xs break-words">{{ ruleLabel(message) }}</li>
                </ul>
              </AlertDescription>
            </Alert>

            <Alert v-if="electronic.status === 'failed'" variant="destructive">
              <TriangleAlertIcon />
              <AlertDescription>
                <p>{{ t('invoices.dian.failedAt', { step: t(`invoices.view.events.steps.${electronic.failed_step ?? 'transmit'}`) }) }}</p>
                <p v-if="failure" class="font-mono text-xs break-words">{{ failure }}</p>
              </AlertDescription>
            </Alert>
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
            <p v-if="invoice.xml.available && invoice.xml.certificate_serial" class="text-sm text-muted-foreground">
              {{ t('invoices.dian.certificateSerial') }}
              <span class="font-mono text-xs break-all text-foreground">{{ invoice.xml.certificate_serial }}</span>
            </p>
            <p v-else-if="!invoice.xml.available" class="text-sm text-muted-foreground">{{ t('invoices.dian.xmlPending') }}</p>
          </div>

          <div class="flex flex-wrap gap-2">
            <Button v-if="canManage && electronic?.can_retry" size="sm" :disabled="retry.isPending.value" @click="retry.mutate()">
              <RefreshCwIcon />
              {{ retry.isPending.value ? t('invoices.dian.retrying') : t('invoices.dian.retryNow') }}
            </Button>
            <Button v-if="canManage && electronic?.can_resend" size="sm" :disabled="resend.isPending.value" @click="resend.mutate()">
              <SendIcon />
              {{ resend.isPending.value ? t('invoices.dian.resending') : t('invoices.dian.resend') }}
            </Button>
            <Button variant="outline" size="sm" :disabled="!result?.response_available" @click="responseOpen = true">
              <EyeIcon />
              {{ t('invoices.dian.viewResponse') }}
            </Button>
            <Button variant="outline" size="sm" :disabled="!result?.response_available || downloadResponse.isPending.value" @click="downloadResponse.mutate()">
              <DownloadIcon />
              {{ t('invoices.dian.downloadResponse') }}
            </Button>
            <Button variant="outline" size="sm" :disabled="!invoice.xml.available" @click="xmlOpen = true">
              <EyeIcon />
              {{ t('invoices.dian.viewXml') }}
            </Button>
            <Button variant="outline" size="sm" :disabled="!invoice.xml.available || downloadXml.isPending.value" @click="downloadXml.mutate()">
              <DownloadIcon />
              {{ t('invoices.dian.downloadXml') }}
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

    <XmlDialog v-model:open="xmlOpen" :invoice="invoice" @download="downloadXml.mutate()" />
    <DianResponseDialog v-model:open="responseOpen" :invoice="invoice" @download="downloadResponse.mutate()" />
  </FormSection>
</template>
