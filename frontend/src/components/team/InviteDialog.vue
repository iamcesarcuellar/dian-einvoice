<script setup lang="ts">
import type { Role } from '@/api/types'
import { reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { teamApi } from '@/api/team'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { useFormMutation } from '@/composables/useFormMutation'
import { notify } from '@/lib/notify'
import { invalidateTenant } from '@/lib/query'
import RoleSelect from './RoleSelect.vue'

/** Invites someone by email with a role; the email, with a link valid for 7 days, lands in the local mailbox. */
const props = defineProps<{
  roles: Role[]
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()

const form = reactive<{ email: string, role: Role }>({ email: '', role: 'member' })

const { submit, isPending, error, errors, reset } = useFormMutation({
  mutationFn: () => teamApi.invite(form.email.trim(), form.role),
  onSuccess: async (answer) => {
    await invalidateTenant('team-invitations')
    open.value = false

    if (answer.meta.email_sent) {
      notify.success(t('team.invite.sent', { email: answer.data.email }), t('team.invite.sentHint'))
    } else {
      notify.warning(t('team.invite.notSent', { email: answer.data.email }), t('team.invite.notSentHint'))
    }
  },
})

watch(open, (value) => {
  if (value) {
    form.email = ''
    form.role = props.roles.includes('member') ? 'member' : (props.roles[0] ?? 'viewer')
    reset()
  }
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ t('team.invite.title') }}</DialogTitle>
        <DialogDescription>{{ t('team.invite.description') }}</DialogDescription>
      </DialogHeader>

      <form id="invite-form" class="grid gap-4" novalidate @submit.prevent="submit">
        <FormError :error="error" />

        <FormField id="invite-email" :label="t('fields.email')" :error="errors.email?.[0]">
          <Input id="invite-email" v-model="form.email" type="email" autocomplete="off" required :aria-invalid="!!errors.email" />
        </FormField>

        <FormField id="invite-role" :label="t('team.columns.role')" :error="errors.role?.[0]">
          <RoleSelect id="invite-role" v-model="form.role" :roles="roles" :invalid="!!errors.role" />
        </FormField>
      </form>

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">{{ t('common.cancel') }}</Button>
        <Button type="submit" form="invite-form" :disabled="isPending">
          {{ isPending ? t('team.invite.sending') : t('team.invite.submit') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
