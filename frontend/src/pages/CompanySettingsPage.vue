<script setup lang="ts">
import type { Me } from '@/api/types'
import { InfoIcon } from '@lucide/vue'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { companiesApi } from '@/api/companies'
import CompanyFields from '@/components/company/CompanyFields.vue'
import { companyFormFrom, emptyCompanyForm, toCompanyInput } from '@/components/company/companyForm'
import FormError from '@/components/FormError.vue'
import FormSection from '@/components/FormSection.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { useFormMutation } from '@/composables/useFormMutation'
import { useSession } from '@/composables/useSession'
import { queryKeys } from '@/lib/query'

const { t } = useI18n()
const queryClient = useQueryClient()
const { currentCompany } = useSession()

// Follows the company switcher: changing company loads that company here.
const companyId = computed(() => currentCompany.value?.id ?? '')

const { data: company } = useQuery({
  queryKey: computed(() => queryKeys.company(companyId.value)),
  queryFn: () => companiesApi.get(companyId.value),
  enabled: computed(() => companyId.value !== ''),
})

const form = ref(emptyCompanyForm())
const canEdit = computed(() => company.value?.permissions?.includes('manage-settings') ?? false)

const saved = ref(false)
let savedTimer: ReturnType<typeof setTimeout> | undefined

const { submit, isPending, error, errors, reset } = useFormMutation({
  mutationFn: () => companiesApi.update(companyId.value, toCompanyInput(form.value)),
  onSuccess: (updated) => {
    queryClient.setQueryData(queryKeys.company(updated.id), updated)
    queryClient.setQueryData<Me | null>(queryKeys.me, (me) =>
      me ? { ...me, companies: me.companies.map((item) => (item.id === updated.id ? updated : item)) } : me,
    )

    saved.value = true
    clearTimeout(savedTimer)
    savedTimer = setTimeout(() => (saved.value = false), 4000)
  },
})

// The form shows the stored values, including the check digit computed by the API.
watch(company, (value) => {
  if (value) {
    form.value = companyFormFrom(value)
  }
}, { immediate: true })

watch(companyId, () => {
  reset()
  saved.value = false
})

onBeforeUnmount(() => clearTimeout(savedTimer))
</script>

<template>
  <div class="grid max-w-3xl gap-6">
    <p v-if="!company" class="text-muted-foreground">{{ t('common.loading') }}</p>

    <form v-else class="grid gap-6" novalidate @submit.prevent="submit">
      <Alert v-if="!canEdit">
        <InfoIcon />
        <AlertDescription>{{ t('settings.company.readOnly') }}</AlertDescription>
      </Alert>

      <FormError :error="error" />

      <FormSection :title="t('settings.company.identity')" :description="t('settings.company.identityDescription')">
        <CompanyFields v-model="form" section="identity" id-prefix="settings" :errors="errors" :disabled="!canEdit || isPending" />
      </FormSection>

      <FormSection :title="t('settings.company.tax')" :description="t('settings.company.taxDescription')">
        <CompanyFields v-model="form" section="tax" id-prefix="settings" :errors="errors" :disabled="!canEdit || isPending" />
      </FormSection>

      <FormSection :title="t('settings.company.contact')" :description="t('settings.company.contactDescription')">
        <CompanyFields
          v-model="form"
          section="contact"
          id-prefix="settings"
          :municipality="company.municipality ?? null"
          :errors="errors"
          :disabled="!canEdit || isPending"
        />

        <template v-if="canEdit" #footer>
          <p v-if="saved" class="text-sm text-muted-foreground" role="status">{{ t('common.saved') }}</p>
          <Button type="submit" :disabled="isPending">
            {{ isPending ? t('common.saving') : t('common.save') }}
          </Button>
        </template>
      </FormSection>
    </form>
  </div>
</template>
