<script setup lang="ts">
import type { Item } from '@/api/types'
import type { DataTableColumn, RowAction } from '@/components/data-table/types'
import { PencilIcon, PlusIcon, PowerIcon, PowerOffIcon, Trash2Icon } from '@lucide/vue'
import { useMutation } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { itemsApi } from '@/api/resources'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import DataTable from '@/components/data-table/DataTable.vue'
import RowActions from '@/components/data-table/RowActions.vue'
import ItemDialog from '@/components/item/ItemDialog.vue'
import PageHeader from '@/components/PageHeader.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { Button } from '@/components/ui/button'
import { useListQuery } from '@/composables/useListQuery'
import { useReference } from '@/composables/useReference'
import { useSession } from '@/composables/useSession'
import { formatMoney } from '@/lib/format'
import { invalidateTenant } from '@/lib/query'

const { t } = useI18n()
const { can } = useSession()
const { label } = useReference()

const canManage = computed(() => can('manage-catalog'))

const { page, search, rows, meta, isLoading } = useListQuery('items', itemsApi.list)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'code', label: t('items.columns.code') },
  { key: 'name', label: t('items.columns.name') },
  { key: 'type', label: t('items.columns.type') },
  { key: 'unit', label: t('items.columns.unit') },
  { key: 'price', label: t('items.columns.price'), align: 'right' },
  { key: 'tax', label: t('items.columns.tax') },
  { key: 'status', label: t('items.columns.status') },
])

const dialogOpen = ref(false)
const editing = ref<Item | null>(null)

function openCreate(): void {
  editing.value = null
  dialogOpen.value = true
}

function openEdit(item: Item): void {
  editing.value = item
  dialogOpen.value = true
}

const toggle = useMutation({
  mutationFn: (item: Item) => itemsApi.update(item.id, { is_active: !item.is_active }),
  onSuccess: () => invalidateTenant('items'),
})

const confirmOpen = ref(false)
const pendingDelete = ref<Item | null>(null)

const remove = useMutation({
  mutationFn: (item: Item) => itemsApi.remove(item.id),
  onSuccess: async () => {
    await invalidateTenant('items')
    confirmOpen.value = false
  },
})

function askDelete(item: Item): void {
  pendingDelete.value = item
  remove.reset()
  confirmOpen.value = true
}

function actions(item: Item): RowAction[] {
  if (!canManage.value) {
    return []
  }

  return [
    { label: t('common.edit'), icon: PencilIcon, onSelect: () => openEdit(item) },
    {
      label: item.is_active ? t('common.deactivate') : t('common.activate'),
      icon: item.is_active ? PowerOffIcon : PowerIcon,
      onSelect: () => toggle.mutate(item),
    },
    { label: t('common.delete'), icon: Trash2Icon, destructive: true, onSelect: () => askDelete(item) },
  ]
}
</script>

<template>
  <div class="grid gap-6">
    <PageHeader :title="t('items.title')" :description="t('items.description')">
      <Button v-if="canManage" @click="openCreate">
        <PlusIcon />
        {{ t('items.new') }}
      </Button>
    </PageHeader>

    <DataTable
      v-model:search="search"
      v-model:page="page"
      :columns="columns"
      :rows="rows"
      :meta="meta"
      :loading="isLoading"
      :search-placeholder="t('items.searchPlaceholder')"
      :empty-title="t('items.empty.title')"
      :empty-description="t('items.empty.description')"
      :actions="canManage"
    >
      <template #cell-code="{ row }">
        <span class="font-medium whitespace-nowrap tabular-nums">{{ row.code }}</span>
      </template>
      <template #cell-name="{ row }">
        <div class="grid min-w-0 max-w-72">
          <span class="truncate">{{ row.name }}</span>
          <span v-if="row.description" class="truncate text-xs text-muted-foreground">{{ row.description }}</span>
        </div>
      </template>
      <template #cell-type="{ row }">
        {{ t(`items.types.${row.type}`) }}
      </template>
      <template #cell-unit="{ row }">
        <span class="text-muted-foreground">{{ label('units', row.unit_code) }}</span>
      </template>
      <template #cell-price="{ row }">
        <span class="whitespace-nowrap tabular-nums">{{ formatMoney(row.unit_price) }}</span>
      </template>
      <template #cell-tax="{ row }">
        <span v-if="row.tax" class="whitespace-nowrap">{{ row.tax.name }}</span>
        <span v-else class="whitespace-nowrap text-muted-foreground">{{ t('items.excluded') }}</span>
      </template>
      <template #cell-status="{ row }">
        <StatusBadge :active="row.is_active" />
      </template>
      <template #actions="{ row }">
        <RowActions :actions="actions(row)" />
      </template>
      <template v-if="canManage" #empty-action>
        <Button variant="outline" size="sm" @click="openCreate">
          <PlusIcon />
          {{ t('items.new') }}
        </Button>
      </template>
    </DataTable>

    <ItemDialog v-model:open="dialogOpen" :item="editing" />

    <ConfirmDialog
      v-model:open="confirmOpen"
      :title="t('items.delete.title')"
      :description="t('items.delete.description', { name: pendingDelete?.name ?? '' })"
      :confirm-label="t('common.delete')"
      :pending="remove.isPending.value"
      :error="remove.error.value"
      @confirm="pendingDelete && remove.mutate(pendingDelete)"
    />
  </div>
</template>
