<script setup lang="ts">
import type { ListParams, Paginated, Tax, Withholding } from '@/api/types'
import type { DataTableColumn, RowAction } from '@/components/data-table/types'
import { PencilIcon, PlusIcon, PowerIcon, PowerOffIcon, Trash2Icon } from '@lucide/vue'
import { useMutation } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { taxesApi, withholdingsApi } from '@/api/resources'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import DataTable from '@/components/data-table/DataTable.vue'
import RowActions from '@/components/data-table/RowActions.vue'
import RateDialog from '@/components/rate/RateDialog.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useListQuery } from '@/composables/useListQuery'
import { useReference } from '@/composables/useReference'
import { useSession } from '@/composables/useSession'
import { formatPercent } from '@/lib/format'
import { notify } from '@/lib/notify'
import { invalidateTenant } from '@/lib/query'

type Rate = Tax & Partial<Pick<Withholding, 'base'>>

/** Taxes or withholdings of the company, created and edited in a dialog. */
const props = defineProps<{
  kind: 'taxes' | 'withholdings'
}>()

const { t } = useI18n()
const { can } = useSession()
const { label } = useReference()

const canManage = computed(() => can('manage-settings'))
const isWithholding = props.kind === 'withholdings'
const api = isWithholding ? withholdingsApi : taxesApi
const typeGroup = isWithholding ? 'withholdingTypes' : 'taxTypes'

const { page, search, rows, meta, isLoading, error, refetch } = useListQuery<Rate>(
  props.kind,
  (params: ListParams) => api.list(params) as Promise<Paginated<Rate>>,
)

const columns = computed<DataTableColumn[]>(() => [
  { key: 'name', label: t('rates.columns.name') },
  { key: 'type', label: t('rates.columns.type') },
  { key: 'rate', label: t('rates.columns.rate'), align: 'right' },
  ...(isWithholding ? [{ key: 'base', label: t('rates.columns.base') }] : []),
  { key: 'status', label: t('rates.columns.status') },
])

const dialogOpen = ref(false)
const editing = ref<Rate | null>(null)

function openCreate(): void {
  editing.value = null
  dialogOpen.value = true
}

function openEdit(rate: Rate): void {
  editing.value = rate
  dialogOpen.value = true
}

const toggle = useMutation({
  mutationFn: (rate: Rate) => api.update(rate.id, { is_active: !rate.is_active }),
  onSuccess: async (rate) => {
    await invalidateTenant(props.kind, 'items')
    notify.success(t(rate.is_active ? 'rates.activated' : 'rates.deactivated', { name: rate.name }))
  },
  onError: (failure) => notify.error(t('errors.actionFailed'), failure.message),
})

const confirmOpen = ref(false)
const pendingDelete = ref<Rate | null>(null)

const remove = useMutation({
  mutationFn: (rate: Rate) => api.remove(rate.id),
  onSuccess: async (_, rate) => {
    await invalidateTenant(props.kind, 'items')
    confirmOpen.value = false
    notify.success(t('rates.deleted', { name: rate.name }))
  },
})

function askDelete(rate: Rate): void {
  pendingDelete.value = rate
  remove.reset()
  confirmOpen.value = true
}

function actions(rate: Rate): RowAction[] {
  if (!canManage.value) {
    return []
  }

  const list: RowAction[] = [
    { label: t('common.edit'), icon: PencilIcon, onSelect: () => openEdit(rate) },
    {
      label: rate.is_active ? t('common.deactivate') : t('common.activate'),
      icon: rate.is_active ? PowerOffIcon : PowerIcon,
      onSelect: () => toggle.mutate(rate),
    },
  ]

  // Company defaults can be deactivated but never deleted.
  if (!rate.is_system) {
    list.push({ label: t('common.delete'), icon: Trash2Icon, destructive: true, onSelect: () => askDelete(rate) })
  }

  return list
}
</script>

<template>
  <div class="grid gap-4">
    <p class="text-muted-foreground">{{ t(`${kind}.description`) }}</p>

    <DataTable
      v-model:search="search"
      v-model:page="page"
      :columns="columns"
      :rows="rows"
      :meta="meta"
      :loading="isLoading"
      :error="error"
      :search-placeholder="t(`${kind}.searchPlaceholder`)"
      :empty-title="t(`${kind}.empty.title`)"
      :empty-description="t(`${kind}.empty.description`)"
      :actions="canManage"
      @retry="refetch"
    >
      <template #toolbar>
        <Button v-if="canManage" @click="openCreate">
          <PlusIcon />
          {{ t(`${kind}.new`) }}
        </Button>
      </template>
      <template #cell-name="{ row }">
        <span class="font-medium">{{ row.name }}</span>
      </template>
      <template #cell-type="{ row }">
        <span class="text-muted-foreground">{{ label(typeGroup, row.dian_code) }}</span>
      </template>
      <template #cell-rate="{ row }">
        <span class="whitespace-nowrap tabular-nums">{{ formatPercent(row.rate) }}</span>
      </template>
      <template #cell-base="{ row }">
        <span class="text-muted-foreground">{{ row.base ? t(`rates.baseShort.${row.base}`) : '' }}</span>
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
        <Button variant="outline" size="sm" @click="openCreate">
          <PlusIcon />
          {{ t(`${kind}.new`) }}
        </Button>
      </template>
    </DataTable>

    <RateDialog v-model:open="dialogOpen" :kind="kind" :rate="editing" />

    <ConfirmDialog
      v-model:open="confirmOpen"
      :title="t(`${kind}.delete.title`)"
      :description="t(`${kind}.delete.description`, { name: pendingDelete?.name ?? '' })"
      :confirm-label="t('common.delete')"
      :pending="remove.isPending.value"
      :error="remove.error.value"
      @confirm="pendingDelete && remove.mutate(pendingDelete)"
    />
  </div>
</template>
