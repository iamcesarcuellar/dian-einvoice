<script setup lang="ts">
import type { Me } from '@/api/types'
import { CircleAlertIcon, LogInIcon, LogOutIcon, UserCheckIcon } from '@lucide/vue'
import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { authApi } from '@/api/auth'
import { ApiError } from '@/api/client'
import { invitationApi } from '@/api/team'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormMutation } from '@/composables/useFormMutation'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { formatDateTime } from '@/lib/format'
import { meQuery, queryKeys } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * The link of an invitation email. A new address creates its account here (name and password)
 * and joins; an address that already has an account joins by signing in as it and accepting.
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const queryClient = useQueryClient()
const session = useSessionStore()

const token = computed(() => String(route.params.token ?? ''))

const { data: invitation, isPending, error } = useQuery({
  queryKey: computed(() => ['invitation', token.value]),
  queryFn: () => invitationApi.get(token.value),
})

const { data: me } = useQuery(meQuery)

const notFound = computed(() => error.value instanceof ApiError && error.value.status === 404)
const pending = computed(() => invitation.value?.status === 'pending')
const signedInAsInvited = computed(() => !!me.value && !!invitation.value
  && me.value.email.toLowerCase() === invitation.value.email.toLowerCase())

const title = computed(() => (invitation.value ? t('invitation.join', { company: invitation.value.company.name }) : t('invitation.title')))
const description = computed(() => {
  if (!invitation.value) {
    return t('invitation.loading')
  }

  return t(invitation.value.invited_by ? 'invitation.invitedBy' : 'invitation.invited', {
    name: invitation.value.invited_by ?? '',
    role: t(`companies.roles.${invitation.value.role}`),
  })
})

const account = reactive({ name: '', password: '', password_confirmation: '' })

async function joined(result: { me: Me, companyId: string }): Promise<void> {
  queryClient.setQueryData(queryKeys.me, result.me)
  session.selectCompany(result.companyId)
  await router.replace({ name: 'dashboard' })
}

const accept = useFormMutation({
  mutationFn: () => invitationApi.accept(token.value, invitation.value?.existing_user ? undefined : { ...account }),
  success: () => t('invitation.welcome', { company: invitation.value?.company.name ?? '' }),
  onSuccess: joined,
})

const logout = useMutation({
  mutationFn: () => authApi.logout(),
  onSettled: () => {
    queryClient.setQueryData(queryKeys.me, null)
    session.selectCompany(null)
  },
})

function signIn(): void {
  void router.push({ name: 'login', query: { redirect: route.fullPath, email: invitation.value?.email } })
}
</script>

<template>
  <AuthLayout :title="title" :description="description">
    <div v-if="isPending" class="grid gap-3">
      <Skeleton class="h-10 w-full" />
      <Skeleton class="h-10 w-full" />
      <Skeleton class="h-9 w-32" />
    </div>

    <Alert v-else-if="notFound || !invitation" variant="destructive">
      <CircleAlertIcon />
      <AlertDescription>{{ notFound ? t('invitation.notFound') : t('errors.generic') }}</AlertDescription>
    </Alert>

    <template v-else-if="!pending">
      <Alert>
        <CircleAlertIcon />
        <AlertDescription>{{ t(`invitation.closed.${invitation.status}`) }}</AlertDescription>
      </Alert>
      <Button as-child variant="outline" class="w-fit">
        <RouterLink :to="me ? { name: 'dashboard' } : { name: 'login' }">{{ me ? t('invitation.toApp') : t('auth.login.submit') }}</RouterLink>
      </Button>
    </template>

    <template v-else-if="invitation.existing_user">
      <dl class="grid gap-1 rounded-lg border bg-muted/40 p-4 text-sm">
        <dt class="text-xs text-muted-foreground">{{ t('invitation.for') }}</dt>
        <dd class="font-medium">{{ invitation.email }}</dd>
        <dt class="mt-2 text-xs text-muted-foreground">{{ t('invitation.expires') }}</dt>
        <dd class="tabular-nums">{{ formatDateTime(invitation.expires_at) }}</dd>
      </dl>

      <FormError :error="accept.error.value" />

      <Button v-if="signedInAsInvited" class="w-full" :disabled="accept.isPending.value" @click="accept.submit">
        <UserCheckIcon />
        {{ accept.isPending.value ? t('invitation.joining') : t('invitation.accept') }}
      </Button>

      <template v-else-if="me">
        <Alert>
          <CircleAlertIcon />
          <AlertDescription>{{ t('invitation.otherUser', { current: me.email, invited: invitation.email }) }}</AlertDescription>
        </Alert>
        <Button variant="outline" class="w-full" :disabled="logout.isPending.value" @click="logout.mutate()">
          <LogOutIcon />
          {{ t('invitation.signOut') }}
        </Button>
      </template>

      <Button v-else class="w-full" @click="signIn">
        <LogInIcon />
        {{ t('invitation.signIn', { email: invitation.email }) }}
      </Button>
    </template>

    <form v-else class="grid gap-4" novalidate @submit.prevent="accept.submit">
      <FormError :error="accept.error.value" />

      <FormField id="invitation-email" :label="t('fields.email')" :hint="t('invitation.emailHint')">
        <Input id="invitation-email" :model-value="invitation.email" type="email" disabled />
      </FormField>

      <FormField id="invitation-name" :label="t('fields.name')" :error="accept.errors.value.name?.[0]">
        <Input id="invitation-name" v-model="account.name" autocomplete="name" required :aria-invalid="!!accept.errors.value.name" />
      </FormField>

      <FormField id="invitation-password" :label="t('fields.password')" :hint="t('fields.passwordHint')" :error="accept.errors.value.password?.[0]">
        <Input id="invitation-password" v-model="account.password" type="password" autocomplete="new-password" required :aria-invalid="!!accept.errors.value.password" />
      </FormField>

      <FormField id="invitation-password-confirmation" :label="t('profile.password.confirm')" :error="accept.errors.value.password_confirmation?.[0]">
        <Input id="invitation-password-confirmation" v-model="account.password_confirmation" type="password" autocomplete="new-password" required :aria-invalid="!!accept.errors.value.password_confirmation" />
      </FormField>

      <Button type="submit" class="mt-2 w-full" :disabled="accept.isPending.value">
        {{ accept.isPending.value ? t('invitation.joining') : t('invitation.create') }}
      </Button>

      <p v-if="me" class="text-xs text-muted-foreground">{{ t('invitation.replacesSession', { email: me.email }) }}</p>
    </form>
  </AuthLayout>
</template>
