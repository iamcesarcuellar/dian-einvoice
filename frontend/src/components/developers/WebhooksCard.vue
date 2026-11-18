<script setup lang="ts">
import type { WebhookSubscription, WebhookSubscriptionStatus, WebhookSubscriptionWithSecret } from '@/api/types'
import type { DataTableColumn, RowAction } from '@/components/data-table/types'
import { HistoryIcon, KeyRoundIcon, PencilIcon, PlusIcon, PowerIcon, SendIcon, Trash2Icon } from '@lucide/vue'
import { useMutation } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { webhooksApi } from '@/api/developers'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import DataTable from '@/components/data-table/DataTable.vue'
import RowActions from '@/components/data-table/RowActions.vue'
import FormError from '@/components/FormError.vue'
import FormSection from '@/components/FormSection.vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useListQuery } from '@/composables/useListQuery'
import { formatDateTime } from '@/lib/format'
import { invalidateTenant } from '@/lib/query'
import SecretDialog from './SecretDialog.vue'
import WebhookDeliveriesDialog from './WebhookDeliveriesDialog.vue'
import WebhookDialog from './WebhookDialog.vue'

/**
 * Webhook subscriptions of the company: URL, events, status and last delivery; create and edit,
 * rotate the secret (shown once), switch on or off, send a test event, see the deliveries, delete.
 */
const props = defineProps<{
  /** The URL of the local webhook receiver, offered for a new subscription. */
  receiverUrl?: string
}>()

const { t } = useI18n()

const { page, search, rows, meta, isLoading } = useListQuery('webhooks', webhooksApi.list, {
  refetchInterval: () => 10_000,
})

const columns = computed<DataTableColumn[]>(() => [
  { key: 'url', label: t('developers.webhooks.columns.url') },
  { key: 'events', label: t('developers.webhooks.columns.events') },
  { key: 'status', label: t('developers.webhooks.columns.status') },
  { key: 'last_delivery', label: t('developers.webhooks.columns.lastDelivery') },
])

const statusVariant: Record<WebhookSubscriptionStatus, 'secondary' | 'outline' | 'destructive'> = {
  active: 'secondary',
  inactive: 'outline',
  disabled: 'destructive',
}

const dialogOpen = ref(false)
const editing = ref<WebhookSubscription | null>(null)

function openCreate(): void {
  editing.value = null
  dialogOpen.value = true
}

function openEdit(subscription: WebhookSubscription): void {
  editing.value = subscription
  dialogOpen.value = true
}

const secret = ref<WebhookSubscriptionWithSecret | null>(null)
const secretOpen = ref(false)
const secretTitle = ref('')

function showSecret(subscription: WebhookSubscriptionWithSecret, title: string): void {
  secret.value = subscription
  secretTitle.value = title
  secretOpen.value = true
}

const notice = ref<string | null>(null)
let noticeTimer: ReturnType<typeof setTimeout> | undefined

function announce(message: string): void {
  notice.value = message
  clearTimeout(noticeTimer)
  noticeTimer = setTimeout(() => (notice.value = null), 5000)
}

const toggle = useMutation({
  mutationFn: (subscription: WebhookSubscription) => webhooksApi.update(subscription.id, { is_active: !subscription.is_active }),
  onSuccess: () => invalidateTenant('webhooks'),
})

const test = useMutation({
  mutationFn: (subscription: WebhookSubscription) => webhooksApi.sendTest(subscription.id),
  onSuccess: async () => {
    announce(t('developers.webhooks.testSent'))
    await invalidateTenant('webhooks', 'webhook-deliveries', 'webhook-receiver')
  },
})

const rotateOpen = ref(false)
const deleteOpen = ref(false)
const target = ref<WebhookSubscription | null>(null)

const rotate = useMutation({
  mutationFn: (subscription: WebhookSubscription) => webhooksApi.rotateSecret(subscription.id),
  onSuccess: async (rotated) => {
    rotateOpen.value = false
    await invalidateTenant('webhooks')
    showSecret(rotated, t('developers.webhooks.rotatedTitle'))
  },
})

const remove = useMutation({
  mutationFn: (subscription: WebhookSubscription) => webhooksApi.remove(subscription.id),
  onSuccess: async () => {
    deleteOpen.value = false
    await invalidateTenant('webhooks')
  },
})

const deliveriesOpen = ref(false)

function actions(subscription: WebhookSubscription): RowAction[] {
  return [
    { label: t('developers.webhooks.deliveries'), icon: HistoryIcon, onSelect: () => { target.value = subscription; deliveriesOpen.value = true } },
    { label: t('developers.webhooks.sendTest'), icon: SendIcon, onSelect: () => test.mutate(subscription) },
    { label: t('common.edit'), icon: PencilIcon, onSelect: () => openEdit(subscription) },
    { label: subscription.is_active ? t('common.deactivate') : t('common.activate'), icon: PowerIcon, onSelect: () => toggle.mutate(subscription) },
    { label: t('developers.webhooks.rotate'), icon: KeyRoundIcon, onSelect: () => { target.value = subscription; rotate.reset(); rotateOpen.value = true } },
    { label: t('common.delete'), icon: Trash2Icon, destructive: true, onSelect: () => { target.value = subscription; remove.reset(); deleteOpen.value = true } },
  ]
}

function eventsLabel(subscription: WebhookSubscription): string {
  return subscription.events.length >= 17 ? t('developers.webhooks.allEvents') : t('developers.webhooks.eventCount', { count: subscription.events.length })
}
</script>

<template>
  <FormSection :title="t('developers.webhooks.title')" :description="t('developers.webhooks.description')">
    <div class="grid gap-3">
      <FormError :error="toggle.error.value ?? test.error.value" />
      <p v-if="notice" class="text-sm text-muted-foreground" role="status">{{ notice }}</p>

      <DataTable
        v-model:search="search"
        v-model:page="page"
        :columns="columns"
        :rows="rows"
        :meta="meta"
        :loading="isLoading"
        :search-placeholder="t('developers.webhooks.searchPlaceholder')"
        :empty-title="t('developers.webhooks.empty.title')"
        :empty-description="t('developers.webhooks.empty.description')"
        actions
      >
        <template #toolbar>
          <Button @click="openCreate">
            <PlusIcon />
            {{ t('developers.webhooks.new') }}
          </Button>
        </template>
        <template #cell-url="{ row }">
          <span class="grid max-w-md gap-0.5">
            <span class="font-mono text-xs break-all">{{ row.url }}</span>
            <span v-if="row.description" class="text-xs text-muted-foreground">{{ row.description }}</span>
          </span>
        </template>
        <template #cell-events="{ row }">
          <span class="whitespace-nowrap" :title="row.events.join(', ')">{{ eventsLabel(row) }}</span>
        </template>
        <template #cell-status="{ row }">
          <span class="grid gap-1">
            <Badge :variant="statusVariant[row.status]">{{ t(`developers.webhooks.status.${row.status}`) }}</Badge>
            <span v-if="row.disabled_reason" class="max-w-56 text-xs text-destructive">{{ row.disabled_reason }}</span>
            <span v-else-if="row.consecutive_failures > 0" class="text-xs text-muted-foreground">{{ t('developers.webhooks.failuresInARow', { count: row.consecutive_failures }) }}</span>
          </span>
        </template>
        <template #cell-last_delivery="{ row }">
          <span v-if="row.last_delivery" class="grid gap-0.5 whitespace-nowrap">
            <span :class="row.last_delivery.status === 'failed' ? 'text-destructive' : ''">
              {{ t(`developers.webhooks.lastStatus.${row.last_delivery.status}`) }}<template v-if="row.last_delivery.response_code"> · HTTP {{ row.last_delivery.response_code }}</template>
            </span>
            <span class="text-xs text-muted-foreground tabular-nums">{{ formatDateTime(row.last_delivery.at) }}</span>
          </span>
          <span v-else class="text-muted-foreground">{{ t('developers.webhooks.noDeliveries') }}</span>
        </template>
        <template #actions="{ row }">
          <RowActions :actions="actions(row)" />
        </template>
      </DataTable>
    </div>

    <WebhookDialog
      v-model:open="dialogOpen"
      :subscription="editing"
      :suggested-url="props.receiverUrl"
      @created="(created) => showSecret(created, t('developers.webhooks.createdTitle'))"
    />

    <SecretDialog
      v-if="secret"
      v-model:open="secretOpen"
      :title="secretTitle"
      :description="t('developers.webhooks.secretDescription', { url: secret.url })"
      :label="t('developers.webhooks.secret')"
      :value="secret.secret"
    />

    <WebhookDeliveriesDialog v-model:open="deliveriesOpen" :subscription="target" />

    <ConfirmDialog
      v-model:open="rotateOpen"
      :title="t('developers.webhooks.rotateTitle')"
      :description="t('developers.webhooks.rotateDescription')"
      :confirm-label="t('developers.webhooks.rotate')"
      variant="default"
      :pending="rotate.isPending.value"
      :error="rotate.error.value"
      @confirm="target && rotate.mutate(target)"
    />

    <ConfirmDialog
      v-model:open="deleteOpen"
      :title="t('developers.webhooks.deleteTitle')"
      :description="t('developers.webhooks.deleteDescription')"
      :confirm-label="t('common.delete')"
      :pending="remove.isPending.value"
      :error="remove.error.value"
      @confirm="target && remove.mutate(target)"
    />
  </FormSection>
</template>
