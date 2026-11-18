<script setup lang="ts">
import type { DianSettings, DianSettingsInput } from '@/api/types'
import { FlaskConicalIcon, InfoIcon } from '@lucide/vue'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { dianSettingsApi } from '@/api/dian'
import { orNull } from '@/components/company/companyForm'
import SigningCertificateCard from '@/components/dian/SigningCertificateCard.vue'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import FormSection from '@/components/FormSection.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useFormMutation } from '@/composables/useFormMutation'
import { useSession } from '@/composables/useSession'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * DIAN settings of the current company, for owners and admins: the environment (always test),
 * the software ID, the write-only PIN, the optional test set ID and the signing certificate.
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

const saved = ref(false)
let savedTimer: ReturnType<typeof setTimeout> | undefined

const { submit, isPending, error, errors, reset } = useFormMutation({
  mutationFn: () => dianSettingsApi.update(toInput()),
  onSuccess: (updated) => {
    queryClient.setQueryData(queryKey.value, updated)
    form.value = formFrom(updated)

    saved.value = true
    clearTimeout(savedTimer)
    savedTimer = setTimeout(() => (saved.value = false), 4000)
  },
})

watch(settings, (value) => {
  form.value = formFrom(value)
}, { immediate: true })

watch(() => session.currentCompanyId, () => {
  reset()
  saved.value = false
})

const pinHint = computed(() => (settings.value?.software_pin_set ? t('settings.dian.pinStored') : t('settings.dian.pinHint')))

onBeforeUnmount(() => clearTimeout(savedTimer))
</script>

<template>
  <div class="grid max-w-3xl gap-6">
    <Alert v-if="!canManage">
      <InfoIcon />
      <AlertDescription>{{ t('settings.dian.restricted') }}</AlertDescription>
    </Alert>

    <p v-else-if="!settings" class="text-muted-foreground">{{ t('common.loading') }}</p>

    <form v-else class="grid gap-6" novalidate @submit.prevent="submit">
      <FormError :error="error" />

      <FormSection :title="t('settings.dian.environment')" :description="t('settings.dian.environmentDescription')">
        <div class="grid gap-3">
          <div class="flex items-center gap-2">
            <Badge variant="secondary">
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

          <FormField id="dian-test-set" :label="t('settings.dian.testSetId')" :hint="t('settings.dian.testSetIdHint')" :error="errors.test_set_id?.[0]" optional>
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
          <p v-if="saved" class="text-sm text-muted-foreground" role="status">{{ t('common.saved') }}</p>
          <Button type="submit" :disabled="isPending">
            {{ isPending ? t('common.saving') : t('common.save') }}
          </Button>
        </template>
      </FormSection>
    </form>

    <SigningCertificateCard v-if="canManage && settings" />
  </div>
</template>
