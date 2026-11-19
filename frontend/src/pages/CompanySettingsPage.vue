<script setup lang="ts">
import type { Me } from '@/api/types'
import { InfoIcon } from '@lucide/vue'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { companiesApi } from '@/api/companies'
import CompanyFields from '@/components/company/CompanyFields.vue'
import { companyFormFrom, emptyCompanyForm, toCompanyInput } from '@/components/company/companyForm'
import FormError from '@/components/FormError.vue'
import FormSection from '@/components/FormSection.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormMutation } from '@/composables/useFormMutation'
import { useSession } from '@/composables/useSession'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
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

const unsaved = useUnsavedChanges(form, canEdit)

const { submit, isPending, error, errors, reset } = useFormMutation({
  mutationFn: () => companiesApi.update(companyId.value, toCompanyInput(form.value)),
  success: t('settings.company.saved'),
  onSuccess: (updated) => {
    queryClient.setQueryData(queryKeys.company(updated.id), updated)
    queryClient.setQueryData<Me | null>(queryKeys.me, (me) =>
      me ? { ...me, companies: me.companies.map((item) => (item.id === updated.id ? updated : item)) } : me,
    )
    form.value = companyFormFrom(updated)
    unsaved.reset()
  },
})

// The form shows the stored values, including the check digit computed by the API.
watch(company, (value) => {
  if (value) {
    form.value = companyFormFrom(value)
    unsaved.reset()
  }
}, { immediate: true })

watch(companyId, () => {
  reset()
})
</script>

<template>
  <div class="grid max-w-3xl gap-6">
    <div v-if="!company" class="grid gap-4" aria-busy="true">
      <span class="sr-only">{{ t('common.loading') }}</span>
      <Skeleton class="h-56 rounded-xl" />
      <Skeleton class="h-40 rounded-xl" />
    </div>

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
          <Button type="submit" :disabled="isPending">
            {{ isPending ? t('common.saving') : t('common.save') }}
          </Button>
        </template>
      </FormSection>
    </form>
  </div>
</template>
