<script setup lang="ts">
import { MailCheckIcon } from '@lucide/vue'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { authApi } from '@/api/auth'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { product } from '@/config/product'
import { useFormMutation } from '@/composables/useFormMutation'
import AuthLayout from '@/layouts/AuthLayout.vue'

/**
 * Asks for a link to choose a new password. The API answers the same whether or not the address
 * has an account, so the page says the same too.
 */
const { t } = useI18n()
const route = useRoute()

const email = ref(typeof route.query.email === 'string' ? route.query.email : '')
const sent = ref<string | null>(null)

const { submit, isPending, error, errors } = useFormMutation({
  mutationFn: () => authApi.forgotPassword(email.value),
  onSuccess: (message) => {
    sent.value = message
  },
})
</script>

<template>
  <AuthLayout :title="t('auth.forgot.title')" :description="t('auth.forgot.subtitle')">
    <div v-if="sent" class="grid gap-4">
      <Alert>
        <MailCheckIcon />
        <AlertDescription>
          <p class="font-medium text-foreground">{{ t('auth.forgot.sent') }}</p>
          <p>{{ sent }}</p>
          <p>{{ t('auth.forgot.sentHint') }}</p>
        </AlertDescription>
      </Alert>
      <Button v-if="product.localDemo" as-child variant="outline" class="w-full">
        <a :href="product.localDemo.mailboxUrl" target="_blank" rel="noopener noreferrer">{{ t('auth.forgot.openMailbox') }}</a>
      </Button>
    </div>

    <form v-else class="grid gap-4" novalidate @submit.prevent="submit">
      <FormError :error="error" />

      <FormField id="forgot-email" :label="t('fields.email')" :error="errors.email?.[0]">
        <Input id="forgot-email" v-model="email" type="email" autocomplete="email" required :aria-invalid="!!errors.email" />
      </FormField>

      <Button type="submit" class="mt-2 w-full" :disabled="isPending">
        {{ isPending ? t('auth.forgot.submitting') : t('auth.forgot.submit') }}
      </Button>
    </form>

    <template #footer>
      <RouterLink :to="{ name: 'login' }" class="font-medium text-primary hover:underline">{{ t('auth.forgot.back') }}</RouterLink>
    </template>
  </AuthLayout>
</template>
