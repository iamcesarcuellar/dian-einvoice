<script setup lang="ts">
import type { Me } from '@/api/types'
import { InfoIcon } from '@lucide/vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { fieldErrors } from '@/api/client'
import { companiesApi } from '@/api/companies'
import CompanyFields from '@/components/company/CompanyFields.vue'
import { companyFormFrom, emptyCompanyForm, toCompanyInput } from '@/components/company/companyForm'
import FormError from '@/components/FormError.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
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
const canEdit = computed(() => company.value?.role === 'owner' || company.value?.role === 'admin')

const saved = ref(false)
let savedTimer: ReturnType<typeof setTimeout> | undefined

const { mutate, isPending, error, reset } = useMutation({
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

const errors = computed(() => fieldErrors(error.value))

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
  <div class="grid max-w-3xl gap-8">
    <header class="grid gap-1">
      <h1 class="text-2xl font-semibold tracking-tight">{{ t('settings.company.title') }}</h1>
      <p class="text-muted-foreground">{{ t('settings.company.subtitle') }}</p>
    </header>

    <p v-if="!company" class="text-muted-foreground">{{ t('common.loading') }}</p>

    <form v-else class="grid gap-6" novalidate @submit.prevent="mutate()">
      <Alert v-if="!canEdit">
        <InfoIcon />
        <AlertDescription>{{ t('settings.company.readOnly') }}</AlertDescription>
      </Alert>

      <FormError :error="error" />

      <Card>
        <CardHeader>
          <CardTitle>{{ t('settings.company.identity') }}</CardTitle>
          <CardDescription>{{ t('settings.company.identityDescription') }}</CardDescription>
        </CardHeader>
        <CardContent>
          <CompanyFields v-model="form" section="identity" id-prefix="settings" :errors="errors" :disabled="!canEdit || isPending" />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{{ t('settings.company.contact') }}</CardTitle>
          <CardDescription>{{ t('settings.company.contactDescription') }}</CardDescription>
        </CardHeader>
        <CardContent>
          <CompanyFields v-model="form" section="contact" id-prefix="settings" :errors="errors" :disabled="!canEdit || isPending" />
        </CardContent>
        <CardFooter v-if="canEdit" class="justify-end gap-3 border-t">
          <p v-if="saved" class="text-sm text-muted-foreground" role="status">{{ t('common.saved') }}</p>
          <Button type="submit" :disabled="isPending">
            {{ isPending ? t('common.saving') : t('common.save') }}
          </Button>
        </CardFooter>
      </Card>
    </form>
  </div>
</template>
