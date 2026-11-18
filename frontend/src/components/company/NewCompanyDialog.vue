<script setup lang="ts">
import type { Me } from '@/api/types'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { fieldErrors } from '@/api/client'
import { companiesApi } from '@/api/companies'
import CompanyFields from '@/components/company/CompanyFields.vue'
import { emptyCompanyForm, toCompanyInput } from '@/components/company/companyForm'
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
import { queryKeys } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
const queryClient = useQueryClient()
const session = useSessionStore()

const form = ref(emptyCompanyForm())

const { mutate, isPending, error, reset } = useMutation({
  mutationFn: () => companiesApi.create(toCompanyInput(form.value)),
  onSuccess: (company) => {
    queryClient.setQueryData<Me | null>(queryKeys.me, (me) =>
      me ? { ...me, companies: [...me.companies, company].sort((a, b) => a.name.localeCompare(b.name)) } : me,
    )
    // Switch to the new company right away.
    session.selectCompany(company.id)
    open.value = false
  },
})

const errors = computed(() => fieldErrors(error.value))

// Each time the dialog opens it starts with an empty form.
watch(open, (isOpen) => {
  if (isOpen) {
    form.value = emptyCompanyForm()
    reset()
  }
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ t('companies.new.title') }}</DialogTitle>
        <DialogDescription>{{ t('companies.new.description') }}</DialogDescription>
      </DialogHeader>

      <form id="new-company-form" class="grid gap-4" novalidate @submit.prevent="mutate()">
        <FormError :error="error" />
        <CompanyFields v-model="form" section="identity" id-prefix="new-company" :errors="errors" :disabled="isPending" />
      </form>

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">
          {{ t('common.cancel') }}
        </Button>
        <Button type="submit" form="new-company-form" :disabled="isPending">
          {{ isPending ? t('companies.new.submitting') : t('companies.new.submit') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
