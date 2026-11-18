<script setup lang="ts">
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { authApi } from '@/api/auth'
import { fieldErrors } from '@/api/client'
import CompanyFields from '@/components/company/CompanyFields.vue'
import { emptyCompanyForm, toCompanyInput } from '@/components/company/companyForm'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { queryKeys } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const queryClient = useQueryClient()
const session = useSessionStore()

const account = reactive({ name: '', email: '', password: '' })
const company = ref(emptyCompanyForm())

const { mutate, isPending, error } = useMutation({
  mutationFn: () => {
    const { name, legal_name, identification_number, check_digit } = toCompanyInput(company.value)

    return authApi.register({ ...account, company: { name, legal_name, identification_number, check_digit } })
  },
  onSuccess: async (me) => {
    queryClient.setQueryData(queryKeys.me, me)
    session.selectCompany(me.companies[0]?.id ?? null)

    await router.replace({ name: 'dashboard' })
  },
})

const errors = computed(() => fieldErrors(error.value))
</script>

<template>
  <AuthLayout :title="t('auth.register.title')" :description="t('auth.register.subtitle')" wide>
    <form class="grid gap-8" novalidate @submit.prevent="mutate()">
      <FormError :error="error" />

      <section class="grid gap-4">
        <h2 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ t('auth.register.account') }}</h2>

        <FormField id="register-name" :label="t('fields.name')" :error="errors.name?.[0]">
          <Input
            id="register-name"
            v-model="account.name"
            autocomplete="name"
            required
            :disabled="isPending"
            :aria-invalid="!!errors.name"
          />
        </FormField>

        <FormField id="register-email" :label="t('fields.email')" :error="errors.email?.[0]">
          <Input
            id="register-email"
            v-model="account.email"
            type="email"
            autocomplete="email"
            required
            :disabled="isPending"
            :aria-invalid="!!errors.email"
          />
        </FormField>

        <FormField id="register-password" :label="t('fields.password')" :hint="t('fields.passwordHint')" :error="errors.password?.[0]">
          <Input
            id="register-password"
            v-model="account.password"
            type="password"
            autocomplete="new-password"
            required
            :disabled="isPending"
            :aria-invalid="!!errors.password"
          />
        </FormField>
      </section>

      <section class="grid gap-4">
        <h2 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ t('auth.register.company') }}</h2>

        <CompanyFields
          v-model="company"
          section="identity"
          id-prefix="register-company"
          error-prefix="company."
          :errors="errors"
          :disabled="isPending"
        />
      </section>

      <Button type="submit" class="w-full" :disabled="isPending">
        {{ isPending ? t('auth.register.submitting') : t('auth.register.submit') }}
      </Button>
    </form>

    <template #footer>
      {{ t('auth.register.hasAccount') }}
      <RouterLink :to="{ name: 'login' }" class="font-medium text-primary hover:underline">
        {{ t('auth.register.toLogin') }}
      </RouterLink>
    </template>
  </AuthLayout>
</template>
