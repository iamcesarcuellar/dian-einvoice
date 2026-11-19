<script setup lang="ts">
import type { TeamMember } from '@/api/team'
import type { Role } from '@/api/types'
import { useQueryClient } from '@tanstack/vue-query'
import { ref, watch } from 'vue'
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
import { useFormMutation } from '@/composables/useFormMutation'
import { invalidateTenant, queryKeys } from '@/lib/query'
import RoleSelect from './RoleSelect.vue'

/** Gives a member another role; a change of one's own role reloads what the session may do. */
const props = defineProps<{
  member: TeamMember | null
  roles: Role[]
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
const queryClient = useQueryClient()

const role = ref<Role>('member')

const { submit, isPending, error, errors, reset } = useFormMutation({
  mutationFn: () => teamApi.changeRole(props.member?.id ?? '', role.value),
  success: (member) => t('team.role.changed', { name: member.name, role: t(`companies.roles.${member.role}`) }),
  onSuccess: async (member) => {
    await invalidateTenant('team-members')

    if (member.is_current_user) {
      await queryClient.invalidateQueries({ queryKey: queryKeys.me })
    }

    open.value = false
  },
})

watch(open, (value) => {
  if (value && props.member) {
    role.value = props.member.role
    reset()
  }
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>{{ t('team.role.title', { name: member?.name ?? '' }) }}</DialogTitle>
        <DialogDescription>{{ t('team.role.description') }}</DialogDescription>
      </DialogHeader>

      <form id="role-form" class="grid gap-4" novalidate @submit.prevent="submit">
        <FormError :error="error" />
        <FormField id="member-role" :label="t('team.columns.role')" :error="errors.role?.[0]">
          <RoleSelect id="member-role" v-model="role" :roles="roles" :invalid="!!errors.role" />
        </FormField>
      </form>

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">{{ t('common.cancel') }}</Button>
        <Button type="submit" form="role-form" :disabled="isPending || role === member?.role">
          {{ isPending ? t('common.saving') : t('team.role.submit') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
