<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { authApi } from '@/api/auth'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import FormSection from '@/components/FormSection.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useFormMutation } from '@/composables/useFormMutation'
import { useSession } from '@/composables/useSession'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { queryKeys } from '@/lib/query'

/** The signed-in user: their name, and a new password with the current one asked again. */
const { t } = useI18n()
const queryClient = useQueryClient()
const { user } = useSession()

const name = ref(user.value?.name ?? '')
const password = reactive({ current_password: '', password: '', password_confirmation: '' })

const { isDirty, reset } = useUnsavedChanges(() => ({ name: name.value, ...password }))

watch(() => user.value?.name, (value) => {
  if (value !== undefined && !isDirty.value) {
    name.value = value
    reset()
  }
})

const profile = useFormMutation({
  mutationFn: () => authApi.updateProfile({ name: name.value.trim() }),
  success: t('profile.details.saved'),
  onSuccess: (me) => {
    queryClient.setQueryData(queryKeys.me, me)
    name.value = me.name
    reset()
  },
})

const passwordChange = useFormMutation({
  mutationFn: () => authApi.changePassword({ ...password }),
  success: t('profile.password.changed'),
  onSuccess: () => {
    Object.assign(password, { current_password: '', password: '', password_confirmation: '' })
    reset()
  },
})

const nameChanged = computed(() => name.value.trim() !== (user.value?.name ?? ''))
</script>

<template>
  <div class="grid gap-6">
    <PageHeader :title="t('profile.title')" :description="t('profile.description')" />

    <form novalidate @submit.prevent="profile.submit">
      <FormSection :title="t('profile.details.title')" :description="t('profile.details.description')">
        <div class="grid gap-4 sm:grid-cols-2">
          <FormError :error="profile.error.value" class="sm:col-span-2" />
          <FormField id="profile-name" :label="t('fields.name')" :error="profile.errors.value.name?.[0]">
            <Input id="profile-name" v-model="name" autocomplete="name" required :aria-invalid="!!profile.errors.value.name" />
          </FormField>
          <FormField id="profile-email" :label="t('fields.email')" :hint="t('profile.details.emailHint')">
            <Input id="profile-email" :model-value="user?.email ?? ''" type="email" disabled />
          </FormField>
        </div>
        <template #footer>
          <Button type="submit" :disabled="profile.isPending.value || !nameChanged">
            {{ profile.isPending.value ? t('common.saving') : t('common.save') }}
          </Button>
        </template>
      </FormSection>
    </form>

    <form novalidate @submit.prevent="passwordChange.submit">
      <FormSection :title="t('profile.password.title')" :description="t('profile.password.description')">
        <div class="grid gap-4 sm:grid-cols-2">
          <FormError :error="passwordChange.error.value" class="sm:col-span-2" />
          <FormField id="profile-current-password" :label="t('profile.password.current')" :error="passwordChange.errors.value.current_password?.[0]" class="sm:col-span-2 sm:max-w-sm">
            <Input id="profile-current-password" v-model="password.current_password" type="password" autocomplete="current-password" required :aria-invalid="!!passwordChange.errors.value.current_password" />
          </FormField>
          <FormField id="profile-new-password" :label="t('profile.password.new')" :hint="t('fields.passwordHint')" :error="passwordChange.errors.value.password?.[0]">
            <Input id="profile-new-password" v-model="password.password" type="password" autocomplete="new-password" required :aria-invalid="!!passwordChange.errors.value.password" />
          </FormField>
          <FormField id="profile-confirm-password" :label="t('profile.password.confirm')" :error="passwordChange.errors.value.password_confirmation?.[0]">
            <Input id="profile-confirm-password" v-model="password.password_confirmation" type="password" autocomplete="new-password" required :aria-invalid="!!passwordChange.errors.value.password_confirmation" />
          </FormField>
        </div>
        <template #footer>
          <Button type="submit" :disabled="passwordChange.isPending.value || !password.current_password || !password.password">
            {{ passwordChange.isPending.value ? t('common.saving') : t('profile.password.submit') }}
          </Button>
        </template>
      </FormSection>
    </form>
  </div>
</template>
