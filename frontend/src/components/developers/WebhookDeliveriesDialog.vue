<script setup lang="ts">
import type { WebhookDelivery, WebhookSubscription } from '@/api/types'
import type { DataTableColumn } from '@/components/data-table/types'
import { useQuery } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { webhooksApi } from '@/api/developers'
import DataTable from '@/components/data-table/DataTable.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { formatDateTime, formatInteger } from '@/lib/format'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'
import WebhookDeliveryDialog from './WebhookDeliveryDialog.vue'

/** The deliveries of one subscription, newest first: event, status, attempts, last code and time. */
const props = defineProps<{
  subscription: WebhookSubscription | null
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
const session = useSessionStore()
const page = ref(1)

watch(
  () => props.subscription?.id,
  () => {
    page.value = 1
  },
)

const { data, error, isPending, refetch } = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, 'webhook-deliveries', 'list', props.subscription?.id, page.value)),
  queryFn: () => webhooksApi.deliveries(props.subscription?.id ?? '', { page: page.value, per_page: 15 }),
  enabled: computed(() => open.value && props.subscription !== null),
  refetchInterval: (query) => ((query.state.data?.data ?? []).some((delivery) => delivery.status === 'pending') ? 2000 : 10_000),
})

const columns = computed<DataTableColumn[]>(() => [
  { key: 'event', label: t('developers.deliveries.columns.event') },
  { key: 'status', label: t('developers.deliveries.columns.status') },
  { key: 'attempts', label: t('developers.deliveries.columns.attempts'), align: 'right' },
  { key: 'code', label: t('developers.deliveries.columns.code') },
  { key: 'time', label: t('developers.deliveries.columns.time') },
])

const detailOpen = ref(false)
const selected = ref<string | null>(null)

function openDetail(delivery: WebhookDelivery): void {
  selected.value = delivery.id
  detailOpen.value = true
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-3xl">
      <DialogHeader>
        <DialogTitle>{{ t('developers.deliveries.title') }}</DialogTitle>
        <DialogDescription class="font-mono break-all">{{ subscription?.url }}</DialogDescription>
      </DialogHeader>

      <div class="max-h-[60vh] overflow-auto">
        <DataTable
          v-model:page="page"
          :columns="columns"
          :rows="data?.data ?? []"
          :meta="data?.meta"
          :loading="isPending"
          :error="error"
          :empty-title="t('developers.deliveries.empty')"
          @retry="refetch()"
        >
          <template #cell-event="{ row }">
            <button type="button" class="font-mono text-xs underline-offset-4 hover:underline" @click="openDetail(row)">{{ row.event_type }}</button>
          </template>
          <template #cell-status="{ row }">
            <StatusBadge domain="webhookDelivery" :status="row.status" />
          </template>
          <template #cell-attempts="{ row }">
            <span class="tabular-nums">{{ formatInteger(row.attempts) }}</span>
          </template>
          <template #cell-code="{ row }">
            <span class="font-mono text-xs">{{ row.last_status_code ?? '—' }}</span>
          </template>
          <template #cell-time="{ row }">
            <span class="whitespace-nowrap tabular-nums">{{ formatDateTime(row.last_attempt_at ?? row.created_at) }}</span>
          </template>
        </DataTable>
      </div>

      <WebhookDeliveryDialog v-model:open="detailOpen" :delivery-id="selected" />
    </DialogContent>
  </Dialog>
</template>
