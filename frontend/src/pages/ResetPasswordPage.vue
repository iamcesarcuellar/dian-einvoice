<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { computed, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { authApi } from '@/api/auth'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useFormMutation } from '@/composables/useFormMutation'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { queryKeys } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * The link of the password reset email: the token in the path and the address in the query. A new
 * password ends every session of the account, so the page sends the person to sign in with it.
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const queryClient = useQueryClient()
const session = useSessionStore()

const token = computed(() => String(route.params.token ?? ''))
const form = reactive({
  email: typeof route.query.email === 'string' ? route.query.email : '',
  password: '',
  password_confirmation: '',
})

const { submit, isPending, error, errors } = useFormMutation({
  mutationFn: () => authApi.resetPassword({ token: token.value, ...form }),
  success: () => t('auth.login.passwordChanged'),
  onSuccess: async () => {
    // Any session of this browser ended with the old password.
    queryClient.setQueryData(queryKeys.me, null)
    session.selectCompany(null)
    await router.replace({ name: 'login', query: { email: form.email } })
  },
})
</script>

<template>
  <AuthLayout :title="t('auth.reset.title')" :description="t('auth.reset.subtitle')">
    <form class="grid gap-4" novalidate @submit.prevent="submit">
      <FormError :error="error" />

      <FormField id="reset-email" :label="t('fields.email')" :error="errors.email?.[0]">
        <Input id="reset-email" v-model="form.email" type="email" autocomplete="email" required :aria-invalid="!!errors.email" />
      </FormField>

      <FormField id="reset-password" :label="t('profile.password.new')" :hint="t('fields.passwordHint')" :error="errors.password?.[0]">
        <Input id="reset-password" v-model="form.password" type="password" autocomplete="new-password" required :aria-invalid="!!errors.password" />
      </FormField>

      <FormField id="reset-password-confirmation" :label="t('profile.password.confirm')" :error="errors.password_confirmation?.[0]">
        <Input
          id="reset-password-confirmation"
          v-model="form.password_confirmation"
          type="password"
          autocomplete="new-password"
          required
          :aria-invalid="!!errors.password_confirmation"
        />
      </FormField>

      <Button type="submit" class="mt-2 w-full" :disabled="isPending">
        {{ isPending ? t('auth.reset.submitting') : t('auth.reset.submit') }}
      </Button>
    </form>

    <template #footer>
      <RouterLink :to="{ name: 'forgot-password', query: form.email ? { email: form.email } : {} }" class="font-medium text-primary hover:underline">{{
        t('auth.reset.again')
      }}</RouterLink>
    </template>
  </AuthLayout>
</template>
