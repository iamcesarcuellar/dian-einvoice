<script setup lang="ts">
import type { Contact } from '@/api/types'
import type { DataTableColumn, RowAction } from '@/components/data-table/types'
import { EyeIcon, PencilIcon, PlusIcon, PowerIcon, PowerOffIcon, Trash2Icon } from '@lucide/vue'
import { useMutation } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { contactsApi } from '@/api/resources'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import DataTable from '@/components/data-table/DataTable.vue'
import RowActions from '@/components/data-table/RowActions.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useListQuery } from '@/composables/useListQuery'
import { useReference } from '@/composables/useReference'
import { useSession } from '@/composables/useSession'
import { notify } from '@/lib/notify'
import { invalidateTenant } from '@/lib/query'
import { formatNit } from '@/lib/utils'

const { t } = useI18n()
const router = useRouter()
const { can } = useSession()
const { label } = useReference()

const canManage = computed(() => can('manage-catalog'))

const { page, search, rows, meta, isLoading, error, refetch } = useListQuery('contacts', (params) => contactsApi.list(params))

const columns = computed<DataTableColumn[]>(() => [
  { key: 'name', label: t('contacts.columns.name') },
  { key: 'identification', label: t('contacts.columns.identification') },
  { key: 'city', label: t('contacts.columns.city') },
  { key: 'email', label: t('contacts.columns.email') },
  { key: 'status', label: t('contacts.columns.status') },
])

function identification(contact: Contact): string {
  return contact.check_digit === null ? contact.identification_number : formatNit(contact.identification_number, contact.check_digit)
}

// Contacts abroad show their country code instead of a municipality.
function city(contact: Contact): string {
  if (contact.municipality) {
    return contact.municipality.name
  }

  return contact.country_code === 'CO' ? '—' : contact.country_code
}

const toggle = useMutation({
  mutationFn: (contact: Contact) => contactsApi.update(contact.id, { is_active: !contact.is_active }),
  onSuccess: async (contact) => {
    await invalidateTenant('contacts')
    notify.success(t(contact.is_active ? 'contacts.activated' : 'contacts.deactivated', { name: contact.legal_name }))
  },
  onError: (failure) => notify.error(t('errors.actionFailed'), failure.message),
})

const confirmOpen = ref(false)
const pendingDelete = ref<Contact | null>(null)

const remove = useMutation({
  mutationFn: (contact: Contact) => contactsApi.remove(contact.id),
  onSuccess: async (_, contact) => {
    await invalidateTenant('contacts')
    confirmOpen.value = false
    notify.success(t('contacts.deleted', { name: contact.legal_name }))
  },
})

function askDelete(contact: Contact): void {
  pendingDelete.value = contact
  remove.reset()
  confirmOpen.value = true
}

function actions(contact: Contact): RowAction[] {
  const open = () => void router.push({ name: 'contact-edit', params: { id: contact.id } })

  // The final consumer, and every contact for viewers, can only be looked at.
  if (!canManage.value || contact.is_system) {
    return [{ label: t('common.view'), icon: EyeIcon, onSelect: open }]
  }

  return [
    { label: t('common.edit'), icon: PencilIcon, onSelect: open },
    {
      label: contact.is_active ? t('common.deactivate') : t('common.activate'),
      icon: contact.is_active ? PowerOffIcon : PowerIcon,
      onSelect: () => toggle.mutate(contact),
    },
    { label: t('common.delete'), icon: Trash2Icon, destructive: true, onSelect: () => askDelete(contact) },
  ]
}
</script>

<template>
  <div class="grid gap-6">
    <PageHeader :title="t('contacts.title')" :description="t('contacts.description')">
      <Button v-if="canManage" as-child>
        <RouterLink :to="{ name: 'contact-new' }">
          <PlusIcon />
          {{ t('contacts.new') }}
        </RouterLink>
      </Button>
    </PageHeader>

    <DataTable
      v-model:search="search"
      v-model:page="page"
      :columns="columns"
      :rows="rows"
      :meta="meta"
      :loading="isLoading"
      :error="error"
      :search-placeholder="t('contacts.searchPlaceholder')"
      :empty-title="t('contacts.empty.title')"
      :empty-description="t('contacts.empty.description')"
      actions
      @retry="refetch"
    >
      <template #cell-name="{ row }">
        <div class="grid min-w-0 max-w-72">
          <RouterLink :to="{ name: 'contact-edit', params: { id: row.id } }" class="truncate font-medium text-foreground hover:underline">
            {{ row.legal_name }}
          </RouterLink>
          <span v-if="row.trade_name" class="truncate text-xs text-muted-foreground">{{ row.trade_name }}</span>
        </div>
      </template>
      <template #cell-identification="{ row }">
        <span class="whitespace-nowrap">
          <span class="text-muted-foreground">{{ label('identificationShort', row.identification_type) }}</span>
          {{ ' ' }}
          <span class="tabular-nums">{{ identification(row) }}</span>
        </span>
      </template>
      <template #cell-city="{ row }">
        {{ city(row) }}
      </template>
      <template #cell-email="{ row }">
        <span class="text-muted-foreground">{{ row.email ?? '—' }}</span>
      </template>
      <template #cell-status="{ row }">
        <span class="inline-flex flex-wrap items-center gap-1.5">
          <StatusBadge domain="record" :status="row.is_active ? 'active' : 'inactive'" />
          <Badge v-if="row.is_system" variant="outline">{{ t('common.system') }}</Badge>
        </span>
      </template>
      <template #actions="{ row }">
        <RowActions :actions="actions(row)" />
      </template>
      <template v-if="canManage" #empty-action>
        <Button variant="outline" size="sm" as-child>
          <RouterLink :to="{ name: 'contact-new' }">
            <PlusIcon />
            {{ t('contacts.new') }}
          </RouterLink>
        </Button>
      </template>
    </DataTable>

    <ConfirmDialog
      v-model:open="confirmOpen"
      :title="t('contacts.delete.title')"
      :description="t('contacts.delete.description', { name: pendingDelete?.legal_name ?? '' })"
      :confirm-label="t('common.delete')"
      :pending="remove.isPending.value"
      :error="remove.error.value"
      @confirm="pendingDelete && remove.mutate(pendingDelete)"
    />
  </div>
</template>
