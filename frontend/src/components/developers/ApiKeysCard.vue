<script setup lang="ts">
import type { ApiKey, CreatedApiKey } from '@/api/types'
import type { DataTableColumn, RowAction } from '@/components/data-table/types'
import { BanIcon, KeyRoundIcon, PlusIcon } from '@lucide/vue'
import { useMutation } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiKeysApi } from '@/api/developers'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import DataTable from '@/components/data-table/DataTable.vue'
import RowActions from '@/components/data-table/RowActions.vue'
import FormSection from '@/components/FormSection.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useListQuery } from '@/composables/useListQuery'
import { formatDateTime } from '@/lib/format'
import { notify } from '@/lib/notify'
import { invalidateTenant } from '@/lib/query'
import CreateApiKeyDialog from './CreateApiKeyDialog.vue'
import SecretDialog from './SecretDialog.vue'

/** API keys of the company: the table, Create key (shown once) and Revoke. */
const { t } = useI18n()

const { page, search, rows, meta, isLoading, error, refetch } = useListQuery('api-keys', apiKeysApi.list)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'name', label: t('developers.keys.columns.name') },
  { key: 'level', label: t('developers.keys.columns.level') },
  { key: 'visible_id', label: t('developers.keys.columns.id') },
  { key: 'last_used_at', label: t('developers.keys.columns.lastUsed') },
  { key: 'expires_at', label: t('developers.keys.columns.expires') },
  { key: 'status', label: t('developers.keys.columns.status') },
])

const createOpen = ref(false)
const created = ref<CreatedApiKey | null>(null)
const revealOpen = ref(false)

function onCreated(key: CreatedApiKey): void {
  created.value = key
  revealOpen.value = true
}

const confirmOpen = ref(false)
const pendingRevoke = ref<ApiKey | null>(null)

const revoke = useMutation({
  mutationFn: (key: ApiKey) => apiKeysApi.revoke(key.id),
  onSuccess: async (_, key) => {
    await invalidateTenant('api-keys')
    confirmOpen.value = false
    notify.success(t('developers.keys.revoked', { name: key.name }))
  },
})

function actions(key: ApiKey): RowAction[] {
  return key.status === 'revoked'
    ? []
    : [{ label: t('developers.keys.revoke'), icon: BanIcon, destructive: true, onSelect: () => askRevoke(key) }]
}

function askRevoke(key: ApiKey): void {
  pendingRevoke.value = key
  revoke.reset()
  confirmOpen.value = true
}
</script>

<template>
  <FormSection :title="t('developers.keys.title')" :description="t('developers.keys.description')">
    <DataTable
      v-model:search="search"
      v-model:page="page"
      :columns="columns"
      :rows="rows"
      :meta="meta"
      :loading="isLoading"
      :error="error"
      :search-placeholder="t('developers.keys.searchPlaceholder')"
      :empty-title="t('developers.keys.empty.title')"
      :empty-description="t('developers.keys.empty.description')"
      actions
      @retry="refetch"
    >
      <template #toolbar>
        <Button @click="createOpen = true">
          <PlusIcon />
          {{ t('developers.keys.new') }}
        </Button>
      </template>
      <template #cell-name="{ row }">
        <span class="grid gap-0.5">
          <span class="font-medium">{{ row.name }}</span>
          <span v-if="row.custom_rate_limit" class="text-xs text-muted-foreground">{{ t('developers.keys.perMinute', { count: row.rate_limit_per_minute }) }}</span>
        </span>
      </template>
      <template #cell-level="{ row }">
        <Badge variant="outline">{{ t(`developers.keys.levels.${row.level}`) }}</Badge>
      </template>
      <template #cell-visible_id="{ row }">
        <span class="inline-flex items-center gap-1.5 font-mono text-xs whitespace-nowrap">
          <KeyRoundIcon class="size-3.5 text-muted-foreground" />
          {{ row.visible_id }}…
        </span>
      </template>
      <template #cell-last_used_at="{ row }">
        <span class="whitespace-nowrap tabular-nums" :class="row.last_used_at ? '' : 'text-muted-foreground'">{{ row.last_used_at ? formatDateTime(row.last_used_at) : t('developers.keys.neverUsed') }}</span>
      </template>
      <template #cell-expires_at="{ row }">
        <span class="whitespace-nowrap tabular-nums" :class="row.expires_at ? '' : 'text-muted-foreground'">{{ row.expires_at ? formatDateTime(row.expires_at) : t('developers.keys.noExpiration') }}</span>
      </template>
      <template #cell-status="{ row }">
        <StatusBadge domain="apiKey" :status="row.status" />
      </template>
      <template #actions="{ row }">
        <RowActions :actions="actions(row)" />
      </template>
      <template #empty-action>
        <Button variant="outline" size="sm" @click="createOpen = true">
          <PlusIcon />
          {{ t('developers.keys.new') }}
        </Button>
      </template>
    </DataTable>

    <CreateApiKeyDialog v-model:open="createOpen" @created="onCreated" />

    <SecretDialog
      v-if="created"
      v-model:open="revealOpen"
      :title="t('developers.keys.createdTitle', { name: created.name })"
      :description="t('developers.keys.createdDescription')"
      :label="t('developers.keys.theKey')"
      :value="created.key"
    />

    <ConfirmDialog
      v-model:open="confirmOpen"
      :title="t('developers.keys.revokeTitle', { name: pendingRevoke?.name ?? '' })"
      :description="t('developers.keys.revokeDescription')"
      :confirm-label="t('developers.keys.revoke')"
      :pending="revoke.isPending.value"
      :error="revoke.error.value"
      @confirm="pendingRevoke && revoke.mutate(pendingRevoke)"
    />
  </FormSection>
</template>
