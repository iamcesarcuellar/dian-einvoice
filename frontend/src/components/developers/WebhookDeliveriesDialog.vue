<script setup lang="ts">
import type { WebhookDelivery, WebhookSubscription } from '@/api/types'
import type { DataTableColumn } from '@/components/data-table/types'
import { useQuery } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { webhooksApi } from '@/api/developers'
import FormError from '@/components/FormError.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Table,
  TableBody,
  TableCell,
  TableEmpty,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { formatDateTime } from '@/lib/format'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'
import DeliveryStatusBadge from './DeliveryStatusBadge.vue'
import WebhookDeliveryDialog from './WebhookDeliveryDialog.vue'

/** The deliveries of one subscription, newest first: event, status, attempts, last code and time. */
const props = defineProps<{
  subscription: WebhookSubscription | null
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
const session = useSessionStore()
const page = ref(1)

watch(() => props.subscription?.id, () => {
  page.value = 1
})

const { data, error, isPending } = useQuery({
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

      <FormError :error="error" />

      <div class="-mx-6 max-h-[60vh] overflow-auto">
        <Table>
          <TableHeader>
            <TableRow class="hover:bg-transparent">
              <TableHead v-for="(column, index) in columns" :key="column.key" :class="[column.align === 'right' ? 'text-right' : '', index === 0 ? 'pl-6' : '', index === columns.length - 1 ? 'pr-6' : '']">
                {{ column.label }}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <template v-if="data && data.data.length > 0">
              <TableRow v-for="delivery in data.data" :key="delivery.id" class="cursor-pointer" @click="openDetail(delivery)">
                <TableCell class="pl-6">
                  <button type="button" class="font-mono text-xs underline-offset-4 hover:underline" @click.stop="openDetail(delivery)">{{ delivery.event_type }}</button>
                </TableCell>
                <TableCell><DeliveryStatusBadge :status="delivery.status" /></TableCell>
                <TableCell class="text-right tabular-nums">{{ delivery.attempts }}</TableCell>
                <TableCell class="font-mono text-xs">{{ delivery.last_status_code ?? '—' }}</TableCell>
                <TableCell class="pr-6 whitespace-nowrap tabular-nums">{{ formatDateTime(delivery.last_attempt_at ?? delivery.created_at) }}</TableCell>
              </TableRow>
            </template>
            <TableEmpty v-else :colspan="columns.length">
              <p class="text-muted-foreground">{{ isPending ? t('common.loading') : t('developers.deliveries.empty') }}</p>
            </TableEmpty>
          </TableBody>
        </Table>
      </div>

      <div v-if="data && data.meta.last_page > 1" class="flex items-center justify-end gap-2">
        <span class="text-xs text-muted-foreground tabular-nums">{{ t('developers.deliveries.page', { page: data.meta.current_page, pages: data.meta.last_page }) }}</span>
        <Button variant="outline" size="sm" :disabled="page <= 1" @click="page--">{{ t('table.previous') }}</Button>
        <Button variant="outline" size="sm" :disabled="page >= data.meta.last_page" @click="page++">{{ t('table.next') }}</Button>
      </div>

      <WebhookDeliveryDialog v-model:open="detailOpen" :delivery-id="selected" />
    </DialogContent>
  </Dialog>
</template>
