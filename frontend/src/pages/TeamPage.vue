<script setup lang="ts">
import type { TeamInvitation, TeamMember } from '@/api/team'
import type { DataTableColumn, RowAction } from '@/components/data-table/types'
import { MailPlusIcon, RefreshCwIcon, ShieldIcon, UserMinusIcon, UserPlusIcon, XIcon } from '@lucide/vue'
import { useMutation, useQuery } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { teamApi } from '@/api/team'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import DataTable from '@/components/data-table/DataTable.vue'
import RowActions from '@/components/data-table/RowActions.vue'
import FormSection from '@/components/FormSection.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import ChangeRoleDialog from '@/components/team/ChangeRoleDialog.vue'
import InviteDialog from '@/components/team/InviteDialog.vue'
import { assignableRoles } from '@/components/team/roles'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useSession } from '@/composables/useSession'
import { formatDate, formatDateTime } from '@/lib/format'
import { notify } from '@/lib/notify'
import { invalidateTenant, tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * Settings → Team, for owners and admins: the members of the company with their role and the
 * day they joined, and the invitations not accepted yet. A company always keeps an owner; only
 * owners give or change the owner role, and nobody removes themselves (the API enforces it all).
 */
const { t } = useI18n()
const session = useSessionStore()
const { currentCompany, can } = useSession()

const canManage = computed(() => can('manage-team'))
const myRole = computed(() => currentCompany.value?.role)
const roles = computed(() => assignableRoles(myRole.value))

const members = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, 'team-members')),
  queryFn: () => teamApi.members(),
  enabled: canManage,
})

const invitations = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, 'team-invitations')),
  queryFn: () => teamApi.invitations(),
  enabled: canManage,
})

const memberColumns = computed<DataTableColumn[]>(() => [
  { key: 'name', label: t('team.columns.member') },
  { key: 'role', label: t('team.columns.role') },
  { key: 'joined_at', label: t('team.columns.joined') },
])

const invitationColumns = computed<DataTableColumn[]>(() => [
  { key: 'email', label: t('team.columns.email') },
  { key: 'role', label: t('team.columns.role') },
  { key: 'status', label: t('team.columns.status') },
  { key: 'invited_by', label: t('team.columns.invitedBy') },
  { key: 'expires_at', label: t('team.columns.expires') },
])

const inviteOpen = ref(false)
const roleOpen = ref(false)
const removeOpen = ref(false)
const cancelOpen = ref(false)
const selectedMember = ref<TeamMember | null>(null)
const selectedInvitation = ref<TeamInvitation | null>(null)

/** Admins leave owners alone; nobody removes themselves. */
function memberActions(member: TeamMember): RowAction[] {
  if (member.role === 'owner' && myRole.value !== 'owner') {
    return []
  }

  return [
    { label: t('team.role.action'), icon: ShieldIcon, onSelect: () => { selectedMember.value = member; roleOpen.value = true } },
    ...(member.is_current_user
      ? []
      : [{ label: t('team.remove.action'), icon: UserMinusIcon, destructive: true, onSelect: () => { selectedMember.value = member; remove.reset(); removeOpen.value = true } }]),
  ]
}

function invitationActions(invitation: TeamInvitation): RowAction[] {
  if (invitation.role === 'owner' && myRole.value !== 'owner') {
    return []
  }

  return [
    { label: t('team.invitations.resend'), icon: RefreshCwIcon, onSelect: () => resend.mutate(invitation) },
    { label: t('team.invitations.cancel'), icon: XIcon, destructive: true, onSelect: () => { selectedInvitation.value = invitation; cancel.reset(); cancelOpen.value = true } },
  ]
}

const remove = useMutation({
  mutationFn: (member: TeamMember) => teamApi.removeMember(member.id),
  onSuccess: async (_, member) => {
    await invalidateTenant('team-members')
    removeOpen.value = false
    notify.success(t('team.remove.done', { name: member.name }))
  },
})

const resend = useMutation({
  mutationFn: (invitation: TeamInvitation) => teamApi.resend(invitation.id),
  onSuccess: async (answer) => {
    await invalidateTenant('team-invitations')

    if (answer.meta.email_sent) {
      notify.success(t('team.invite.sent', { email: answer.data.email }), t('team.invite.sentHint'))
    } else {
      notify.warning(t('team.invite.notSent', { email: answer.data.email }), t('team.invite.notSentHint'))
    }
  },
  onError: (error) => notify.error(t('team.invitations.resendFailed'), error.message),
})

const cancel = useMutation({
  mutationFn: (invitation: TeamInvitation) => teamApi.cancel(invitation.id),
  onSuccess: async (_, invitation) => {
    await invalidateTenant('team-invitations')
    cancelOpen.value = false
    notify.success(t('team.invitations.canceled', { email: invitation.email }))
  },
})
</script>

<template>
  <Alert v-if="!canManage">
    <ShieldIcon />
    <AlertDescription>{{ t('team.onlyManagers') }}</AlertDescription>
  </Alert>

  <div v-else class="grid gap-6">
    <FormSection :title="t('team.members.title')" :description="t('team.members.description', { company: currentCompany?.name ?? '' })">
      <DataTable
        :columns="memberColumns"
        :rows="members.data.value ?? []"
        :loading="members.isPending.value"
        :error="members.error.value"
        :empty-title="t('team.members.empty')"
        actions
        @retry="members.refetch()"
      >
        <template #toolbar>
          <Button @click="inviteOpen = true">
            <UserPlusIcon />
            {{ t('team.invite.action') }}
          </Button>
        </template>
        <template #cell-name="{ row }">
          <span class="grid min-w-48 gap-0.5">
            <span class="inline-flex items-center gap-2 font-medium">
              {{ row.name }}
              <Badge v-if="row.is_current_user" variant="outline" class="font-normal">{{ t('team.you') }}</Badge>
            </span>
            <span class="text-xs text-muted-foreground">{{ row.email }}</span>
          </span>
        </template>
        <template #cell-role="{ row }">
          <Badge variant="secondary">{{ t(`companies.roles.${row.role}`) }}</Badge>
        </template>
        <template #cell-joined_at="{ row }">
          <span class="whitespace-nowrap tabular-nums">{{ formatDate(row.joined_at) }}</span>
        </template>
        <template #actions="{ row }">
          <RowActions :actions="memberActions(row)" />
        </template>
      </DataTable>
    </FormSection>

    <FormSection :title="t('team.invitations.title')" :description="t('team.invitations.description')">
      <DataTable
        :columns="invitationColumns"
        :rows="invitations.data.value ?? []"
        :loading="invitations.isPending.value"
        :error="invitations.error.value"
        :empty-title="t('team.invitations.empty.title')"
        :empty-description="t('team.invitations.empty.description')"
        :skeleton-rows="2"
        actions
        @retry="invitations.refetch()"
      >
        <template #cell-email="{ row }">
          <span class="font-medium">{{ row.email }}</span>
        </template>
        <template #cell-role="{ row }">
          <Badge variant="secondary">{{ t(`companies.roles.${row.role}`) }}</Badge>
        </template>
        <template #cell-status="{ row }">
          <StatusBadge domain="invitation" :status="row.status" />
        </template>
        <template #cell-invited_by="{ row }">
          <span class="whitespace-nowrap">{{ row.invited_by?.name ?? '—' }}</span>
        </template>
        <template #cell-expires_at="{ row }">
          <span class="whitespace-nowrap tabular-nums" :class="row.status === 'expired' ? 'text-destructive' : ''">{{ formatDateTime(row.expires_at) }}</span>
        </template>
        <template #actions="{ row }">
          <RowActions :actions="invitationActions(row)" />
        </template>
        <template #empty-action>
          <Button variant="outline" size="sm" @click="inviteOpen = true">
            <MailPlusIcon />
            {{ t('team.invite.action') }}
          </Button>
        </template>
      </DataTable>
    </FormSection>

    <InviteDialog v-model:open="inviteOpen" :roles="roles" />
    <ChangeRoleDialog v-model:open="roleOpen" :member="selectedMember" :roles="roles" />

    <ConfirmDialog
      v-model:open="removeOpen"
      :title="t('team.remove.title', { name: selectedMember?.name ?? '' })"
      :description="t('team.remove.description', { company: currentCompany?.name ?? '' })"
      :confirm-label="t('team.remove.action')"
      :pending="remove.isPending.value"
      :error="remove.error.value"
      @confirm="selectedMember && remove.mutate(selectedMember)"
    />

    <ConfirmDialog
      v-model:open="cancelOpen"
      :title="t('team.invitations.cancelTitle', { email: selectedInvitation?.email ?? '' })"
      :description="t('team.invitations.cancelDescription')"
      :confirm-label="t('team.invitations.cancel')"
      :pending="cancel.isPending.value"
      :error="cancel.error.value"
      @confirm="selectedInvitation && cancel.mutate(selectedInvitation)"
    />
  </div>
</template>
