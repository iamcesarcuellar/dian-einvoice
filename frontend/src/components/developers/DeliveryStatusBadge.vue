<script setup lang="ts">
import type { WebhookDeliveryStatus } from '@/api/types'
import { useI18n } from 'vue-i18n'
import { Badge } from '@/components/ui/badge'

defineProps<{
  status: WebhookDeliveryStatus
}>()

const { t } = useI18n()

const variant: Record<WebhookDeliveryStatus, 'secondary' | 'outline' | 'destructive'> = {
  pending: 'outline',
  succeeded: 'secondary',
  failed: 'destructive',
}
</script>

<template>
  <Badge :variant="variant[status]">{{ t(`developers.deliveries.status.${status}`) }}</Badge>
</template>
