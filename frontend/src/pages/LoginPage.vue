<script setup lang="ts">
import { FlaskConicalIcon } from '@lucide/vue'
import { useQueryClient } from '@tanstack/vue-query'
import { reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { authApi } from '@/api/auth'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { product } from '@/config/product'
import { useFormMutation } from '@/composables/useFormMutation'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { queryKeys } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const queryClient = useQueryClient()
const session = useSessionStore()

// An invitation sends its address along (?email=), so the user only types the password.
const form = reactive({ email: typeof route.query.email === 'string' ? route.query.email : '', password: '' })

const { submit, isPending, error, errors } = useFormMutation({
  mutationFn: () => authApi.login({ ...form }),
  onSuccess: async (me) => {
    queryClient.setQueryData(queryKeys.me, me)
    session.syncCompanies(me.companies)

    // Return to the page that asked for a session, but only within this app.
    const redirect = route.query.redirect
    const target = typeof redirect === 'string' && redirect.startsWith('/') && !redirect.startsWith('//') ? redirect : '/'

    await router.replace(target)
  },
})

/** Only on the local development server: fills in the synthetic account of make demo. */
function useDemoAccount(): void {
  if (product.localDemo) {
    form.email = product.localDemo.email
    form.password = product.localDemo.password
  }
}
</script>

<template>
  <AuthLayout :title="t('auth.login.title')" :description="t('auth.login.subtitle')">
    <form class="grid gap-4" novalidate @submit.prevent="submit">
      <FormError :error="error" />

      <FormField id="login-email" :label="t('fields.email')" :error="errors.email?.[0]">
        <Input
          id="login-email"
          v-model="form.email"
          type="email"
          autocomplete="email"
          required
          :aria-invalid="!!errors.email"
        />
      </FormField>

      <FormField id="login-password" :label="t('fields.password')" :error="errors.password?.[0]">
        <Input
          id="login-password"
          v-model="form.password"
          type="password"
          autocomplete="current-password"
          required
          :aria-invalid="!!errors.password"
        />
      </FormField>

      <Button type="submit" class="mt-2 w-full" :disabled="isPending">
        {{ isPending ? t('auth.login.submitting') : t('auth.login.submit') }}
      </Button>

      <template v-if="product.localDemo">
        <div class="flex items-center gap-3 text-xs text-muted-foreground">
          <span class="h-px flex-1 bg-border" />
          {{ t('auth.login.localOnly') }}
          <span class="h-px flex-1 bg-border" />
        </div>
        <Button type="button" variant="outline" class="w-full" @click="useDemoAccount">
          <FlaskConicalIcon />
          {{ t('auth.login.demo') }}
        </Button>
        <p class="text-center text-xs text-muted-foreground">{{ t('auth.login.demoHint', { email: product.localDemo.email }) }}</p>
      </template>
    </form>

    <template #footer>
      {{ t('auth.login.noAccount') }}
      <RouterLink :to="{ name: 'register' }" class="font-medium text-primary hover:underline">
        {{ t('auth.login.toRegister') }}
      </RouterLink>
    </template>
  </AuthLayout>
</template>
