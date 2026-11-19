<script setup lang="ts">
import type { SigningCertificate } from '@/api/types'
import { FlaskConicalIcon, KeyRoundIcon, Trash2Icon, UploadIcon } from '@lucide/vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { signingCertificateApi } from '@/api/dian'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import UploadCertificateDialog from '@/components/dian/UploadCertificateDialog.vue'
import FormError from '@/components/FormError.vue'
import FormSection from '@/components/FormSection.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDateTime } from '@/lib/format'
import { notify } from '@/lib/notify'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * The certificate the company signs its documents with: status, subject, issuer, serial and
 * validity, and the actions to upload one, generate a synthetic test one, or remove it.
 */
const { t } = useI18n()
const queryClient = useQueryClient()
const session = useSessionStore()

const queryKey = computed(() => tenantKey(session.currentCompanyId, 'dian-certificate'))

const { data: certificate, isLoading } = useQuery({
  queryKey,
  queryFn: () => signingCertificateApi.get(),
})

function store(value: SigningCertificate | null): void {
  queryClient.setQueryData(queryKey.value, value)
}

const uploadOpen = ref(false)
const generateOpen = ref(false)
const removeOpen = ref(false)

const generate = useMutation({
  mutationFn: () => signingCertificateApi.generateTest(),
  onSuccess: (value) => {
    store(value)
    generateOpen.value = false
    notify.success(t('settings.dian.certificate.generated'))
  },
})

const remove = useMutation({
  mutationFn: () => signingCertificateApi.remove(),
  onSuccess: () => {
    store(null)
    removeOpen.value = false
    notify.success(t('settings.dian.certificate.removed'))
  },
})

/** Generating a test certificate replaces the current one, so it asks first when there is one. */
function askGenerate(): void {
  generate.reset()

  if (certificate.value) {
    generateOpen.value = true
  } else {
    generate.mutate()
  }
}

function askRemove(): void {
  remove.reset()
  removeOpen.value = true
}
</script>

<template>
  <FormSection :title="t('settings.dian.certificate.title')" :description="t('settings.dian.certificate.description')">
    <div class="grid gap-4">
      <div v-if="isLoading" class="grid gap-2" aria-busy="true">
        <Skeleton class="h-5 w-32" />
        <Skeleton class="h-4 w-full" />
        <Skeleton class="h-4 w-2/3" />
      </div>

      <template v-else-if="certificate">
        <div class="flex flex-wrap items-center gap-2">
          <StatusBadge domain="certificate" :status="certificate.status" />
          <Badge v-if="certificate.is_test" variant="outline">
            <FlaskConicalIcon />
            {{ t('settings.dian.certificate.test') }}
          </Badge>
        </div>

        <dl class="grid gap-3 sm:grid-cols-2">
          <div class="grid min-w-0 gap-0.5 sm:col-span-2">
            <dt class="text-xs text-muted-foreground">{{ t('settings.dian.certificate.subject') }}</dt>
            <dd class="break-words">{{ certificate.subject }}</dd>
          </div>
          <div class="grid min-w-0 gap-0.5 sm:col-span-2">
            <dt class="text-xs text-muted-foreground">{{ t('settings.dian.certificate.issuer') }}</dt>
            <dd class="break-words">{{ certificate.issuer }}</dd>
          </div>
          <div class="grid min-w-0 gap-0.5">
            <dt class="text-xs text-muted-foreground">{{ t('settings.dian.certificate.serial') }}</dt>
            <dd class="font-mono text-xs break-all">{{ certificate.serial_number }}</dd>
          </div>
          <div class="grid min-w-0 gap-0.5">
            <dt class="text-xs text-muted-foreground">{{ t('settings.dian.certificate.validity') }}</dt>
            <dd class="tabular-nums">{{ t('settings.dian.certificate.validityRange', { from: formatDateTime(certificate.valid_from), to: formatDateTime(certificate.valid_to) }) }}</dd>
          </div>
        </dl>

        <Alert v-if="certificate.is_test">
          <FlaskConicalIcon />
          <AlertDescription>{{ t('settings.dian.certificate.testNote') }}</AlertDescription>
        </Alert>
      </template>

      <Alert v-else>
        <KeyRoundIcon />
        <AlertDescription>{{ t('settings.dian.certificate.none') }}</AlertDescription>
      </Alert>

      <FormError :error="generate.error.value ?? remove.error.value" />
    </div>

    <template #footer>
      <Button v-if="certificate" variant="ghost" @click="askRemove">
        <Trash2Icon />
        {{ t('settings.dian.certificate.remove') }}
      </Button>
      <Button variant="outline" :disabled="generate.isPending.value" @click="askGenerate">
        <FlaskConicalIcon />
        {{ generate.isPending.value ? t('settings.dian.certificate.generating') : t('settings.dian.certificate.generate') }}
      </Button>
      <Button @click="uploadOpen = true">
        <UploadIcon />
        {{ t('settings.dian.certificate.upload') }}
      </Button>
    </template>

    <UploadCertificateDialog v-model:open="uploadOpen" @uploaded="store" />

    <ConfirmDialog
      v-model:open="generateOpen"
      :title="t('settings.dian.certificate.generateTitle')"
      :description="t('settings.dian.certificate.generateDescription')"
      :confirm-label="generate.isPending.value ? t('settings.dian.certificate.generating') : t('settings.dian.certificate.generate')"
      variant="default"
      :pending="generate.isPending.value"
      :error="generate.error.value"
      @confirm="generate.mutate()"
    />

    <ConfirmDialog
      v-model:open="removeOpen"
      :title="t('settings.dian.certificate.removeTitle')"
      :description="t('settings.dian.certificate.removeDescription')"
      :confirm-label="t('settings.dian.certificate.remove')"
      :pending="remove.isPending.value"
      :error="remove.error.value"
      @confirm="remove.mutate()"
    />
  </FormSection>
</template>
