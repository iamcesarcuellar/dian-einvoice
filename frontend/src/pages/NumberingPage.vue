<script setup lang="ts">
import type { NumberTemplate } from '@/api/types'
import type { DataTableColumn, RowAction } from '@/components/data-table/types'
import { PencilIcon, PlusIcon, Trash2Icon } from '@lucide/vue'
import { useMutation } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { numberTemplatesApi } from '@/api/resources'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import DataTable from '@/components/data-table/DataTable.vue'
import RowActions from '@/components/data-table/RowActions.vue'
import NumberTemplateDialog from '@/components/numbering/NumberTemplateDialog.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useListQuery } from '@/composables/useListQuery'
import { useSession } from '@/composables/useSession'
import { formatDate, formatInteger, formatPercent } from '@/lib/format'
import { notify } from '@/lib/notify'
import { invalidateTenant } from '@/lib/query'

const { t } = useI18n()
const { can } = useSession()

const canManage = computed(() => can('manage-settings'))

const { page, search, rows, meta, isLoading, error, refetch } = useListQuery('number-templates', (params) => numberTemplatesApi.list(params))

const columns = computed<DataTableColumn[]>(() => [
  { key: 'document_type', label: t('numbering.columns.type') },
  { key: 'prefix', label: t('numbering.columns.prefix') },
  { key: 'range', label: t('numbering.columns.range') },
  { key: 'next', label: t('numbering.columns.next'), align: 'right' },
  { key: 'validity', label: t('numbering.columns.validity') },
  { key: 'status', label: t('numbering.columns.status') },
  { key: 'usage', label: t('numbering.columns.usage'), class: 'w-40' },
])

/** Share of the range already used, in whole percent. */
function usagePercent(template: NumberTemplate): number {
  const { used, total } = template.usage

  return total > 0 ? Math.min(100, Math.floor((used * 100) / total)) : 0
}

const dialogOpen = ref(false)
const editing = ref<NumberTemplate | null>(null)

function openCreate(): void {
  editing.value = null
  dialogOpen.value = true
}

function openEdit(template: NumberTemplate): void {
  editing.value = template
  dialogOpen.value = true
}

const confirmOpen = ref(false)
const pendingDelete = ref<NumberTemplate | null>(null)

const remove = useMutation({
  mutationFn: (template: NumberTemplate) => numberTemplatesApi.remove(template.id),
  onSuccess: async () => {
    await invalidateTenant('number-templates')
    confirmOpen.value = false
    notify.success(t('numbering.deleted'))
  },
})

function askDelete(template: NumberTemplate): void {
  pendingDelete.value = template
  remove.reset()
  confirmOpen.value = true
}

function actions(template: NumberTemplate): RowAction[] {
  if (!canManage.value) {
    return []
  }

  return [
    { label: t('common.edit'), icon: PencilIcon, onSelect: () => openEdit(template) },
    { label: t('common.delete'), icon: Trash2Icon, destructive: true, onSelect: () => askDelete(template) },
  ]
}
</script>

<template>
  <div class="grid gap-4">
    <p class="text-muted-foreground">{{ t('numbering.description') }}</p>

    <DataTable
      v-model:search="search"
      v-model:page="page"
      :columns="columns"
      :rows="rows"
      :meta="meta"
      :loading="isLoading"
      :error="error"
      :search-placeholder="t('numbering.searchPlaceholder')"
      :empty-title="t('numbering.empty.title')"
      :empty-description="t('numbering.empty.description')"
      :actions="canManage"
      @retry="refetch"
    >
      <template #toolbar>
        <Button v-if="canManage" @click="openCreate">
          <PlusIcon />
          {{ t('numbering.new') }}
        </Button>
      </template>
      <template #cell-document_type="{ row }">
        <span class="inline-flex items-center gap-2 whitespace-nowrap">
          {{ t(`numbering.documentTypes.${row.document_type}`) }}
          <Badge v-if="row.is_default" variant="outline">{{ t('common.default') }}</Badge>
        </span>
      </template>
      <template #cell-prefix="{ row }">
        <span class="font-medium">{{ row.prefix ?? '—' }}</span>
      </template>
      <template #cell-range="{ row }">
        <span class="whitespace-nowrap tabular-nums">{{ formatInteger(row.from_number) }} – {{ formatInteger(row.to_number) }}</span>
      </template>
      <template #cell-next="{ row }">
        <span class="tabular-nums">{{ formatInteger(row.next_number) }}</span>
      </template>
      <template #cell-validity="{ row }">
        <span v-if="row.valid_from || row.valid_to" class="whitespace-nowrap tabular-nums">
          {{ formatDate(row.valid_from) }} – {{ formatDate(row.valid_to) }}
        </span>
        <span v-else class="text-muted-foreground">—</span>
      </template>
      <template #cell-status="{ row }">
        <span class="inline-flex flex-wrap items-center gap-1.5">
          <StatusBadge domain="numbering" :status="row.status" />
          <StatusBadge v-if="!row.is_active" domain="record" status="inactive" />
        </span>
      </template>
      <template #cell-usage="{ row }">
        <div
          class="flex items-center gap-2"
          :title="t('numbering.usageTitle', { used: formatInteger(row.usage.used), total: formatInteger(row.usage.total) }, row.usage.total)"
        >
          <div class="h-1.5 w-20 overflow-hidden rounded-full bg-muted">
            <div class="h-full rounded-full bg-primary" :style="{ width: `${usagePercent(row)}%` }" />
          </div>
          <span class="text-xs text-muted-foreground tabular-nums">{{ formatPercent(String(usagePercent(row))) }}</span>
        </div>
      </template>
      <template #actions="{ row }">
        <RowActions :actions="actions(row)" />
      </template>
      <template v-if="canManage" #empty-action>
        <Button variant="outline" size="sm" @click="openCreate">
          <PlusIcon />
          {{ t('numbering.new') }}
        </Button>
      </template>
    </DataTable>

    <NumberTemplateDialog v-model:open="dialogOpen" :template="editing" />

    <ConfirmDialog
      v-model:open="confirmOpen"
      :title="t('numbering.delete.title')"
      :description="t('numbering.delete.description')"
      :confirm-label="t('common.delete')"
      :pending="remove.isPending.value"
      :error="remove.error.value"
      @confirm="pendingDelete && remove.mutate(pendingDelete)"
    />
  </div>
</template>
