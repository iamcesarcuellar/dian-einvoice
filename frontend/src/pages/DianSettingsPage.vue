<script setup lang="ts">
import type { DianSettings, DianSettingsInput } from '@/api/types'
import { FlaskConicalIcon, InfoIcon } from '@lucide/vue'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { dianSettingsApi } from '@/api/dian'
import { orNull } from '@/components/company/companyForm'
import LocalMailboxCard from '@/components/dian/LocalMailboxCard.vue'
import SigningCertificateCard from '@/components/dian/SigningCertificateCard.vue'
import SimulatorCard from '@/components/dian/SimulatorCard.vue'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import FormSection from '@/components/FormSection.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormMutation } from '@/composables/useFormMutation'
import { useSession } from '@/composables/useSession'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { tenantKey } from '@/lib/query'
import { TONE_BADGE } from '@/lib/status'
import { useSessionStore } from '@/stores/session'

/**
 * DIAN settings of the current company, for owners and admins: the environment (always test),
 * the software ID, the write-only PIN, the optional test set ID, the signing certificate, the
 * local DIAN simulator that stands in for the DIAN, and the local mailbox that catches every email.
 */
const { t } = useI18n()
const queryClient = useQueryClient()
const session = useSessionStore()
const { can } = useSession()

const canManage = computed(() => can('manage-settings'))
const queryKey = computed(() => tenantKey(session.currentCompanyId, 'dian-settings'))

const { data: settings } = useQuery({
  queryKey,
  queryFn: () => dianSettingsApi.get(),
  enabled: canManage,
})

interface SettingsForm {
  software_id: string
  software_pin: string
  test_set_id: string
}

function formFrom(value: DianSettings | undefined): SettingsForm {
  return {
    software_id: value?.software_id ?? '',
    software_pin: '',
    test_set_id: value?.test_set_id ?? '',
  }
}

const form = ref<SettingsForm>(formFrom(settings.value))

function toInput(): DianSettingsInput {
  const input: DianSettingsInput = {
    software_id: orNull(form.value.software_id),
    test_set_id: orNull(form.value.test_set_id),
  }

  // Only send a PIN when one was typed, so the stored one is kept otherwise.
  if (form.value.software_pin.trim() !== '') {
    input.software_pin = form.value.software_pin.trim()
  }

  return input
}

const unsaved = useUnsavedChanges(form, canManage)

const { submit, isPending, error, errors, reset } = useFormMutation({
  mutationFn: () => dianSettingsApi.update(toInput()),
  success: t('settings.dian.saved'),
  onSuccess: (updated) => {
    queryClient.setQueryData(queryKey.value, updated)
    form.value = formFrom(updated)
    unsaved.reset()
  },
})

watch(
  settings,
  (value) => {
    form.value = formFrom(value)
    unsaved.reset()
  },
  { immediate: true },
)

watch(
  () => session.currentCompanyId,
  () => {
    reset()
  },
)

const pinHint = computed(() => (settings.value?.software_pin_set ? t('settings.dian.pinStored') : t('settings.dian.pinHint')))
</script>

<template>
  <div class="grid max-w-3xl gap-6">
    <Alert v-if="!canManage">
      <InfoIcon />
      <AlertDescription>{{ t('settings.dian.restricted') }}</AlertDescription>
    </Alert>

    <div v-else-if="!settings" class="grid gap-4" aria-busy="true">
      <span class="sr-only">{{ t('common.loading') }}</span>
      <Skeleton class="h-32 rounded-xl" />
      <Skeleton class="h-64 rounded-xl" />
    </div>

    <form v-else class="grid gap-6" novalidate @submit.prevent="submit">
      <FormError :error="error" />

      <FormSection :title="t('settings.dian.environment')" :description="t('settings.dian.environmentDescription')">
        <div class="grid gap-3">
          <div class="flex items-center gap-2">
            <Badge variant="outline" :class="TONE_BADGE.warning">
              <FlaskConicalIcon />
              {{ t('settings.dian.test') }}
            </Badge>
            <span class="text-sm text-muted-foreground">{{ t('settings.dian.environmentCode', { code: settings.environment }) }}</span>
          </div>
          <p class="text-sm text-muted-foreground">{{ t('settings.dian.productionNote') }}</p>
        </div>
      </FormSection>

      <FormSection :title="t('settings.dian.software')" :description="t('settings.dian.softwareDescription')">
        <div class="grid gap-4">
          <FormField id="dian-software-id" :label="t('settings.dian.softwareId')" :hint="t('settings.dian.softwareIdHint')" :error="errors.software_id?.[0]">
            <Input
              id="dian-software-id"
              v-model="form.software_id"
              autocomplete="off"
              spellcheck="false"
              class="font-mono"
              :disabled="isPending"
              :aria-invalid="!!errors.software_id"
            />
          </FormField>

          <FormField id="dian-software-pin" :label="t('settings.dian.pin')" :hint="pinHint" :error="errors.software_pin?.[0]">
            <Input
              id="dian-software-pin"
              v-model="form.software_pin"
              type="password"
              autocomplete="new-password"
              :placeholder="settings.software_pin_set ? t('settings.dian.pinPlaceholder') : ''"
              :disabled="isPending"
              :aria-invalid="!!errors.software_pin"
            />
          </FormField>

          <FormField
            id="dian-test-set"
            :label="t('settings.dian.testSetId')"
            :hint="t('settings.dian.testSetIdHint')"
            :error="errors.test_set_id?.[0]"
            optional
          >
            <Input
              id="dian-test-set"
              v-model="form.test_set_id"
              autocomplete="off"
              spellcheck="false"
              class="font-mono"
              :disabled="isPending"
              :aria-invalid="!!errors.test_set_id"
            />
          </FormField>
        </div>

        <template #footer>
          <Button type="submit" :disabled="isPending">
            {{ isPending ? t('common.saving') : t('common.save') }}
          </Button>
        </template>
      </FormSection>
    </form>

    <SigningCertificateCard v-if="canManage && settings" />

    <SimulatorCard v-if="canManage && settings" />

    <LocalMailboxCard v-if="canManage && settings" />
  </div>
</template>
