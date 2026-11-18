<script setup lang="ts">
import { InfoIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ApiKeysCard from '@/components/developers/ApiKeysCard.vue'
import LocalReceiverCard from '@/components/developers/LocalReceiverCard.vue'
import QuickStartCard from '@/components/developers/QuickStartCard.vue'
import WebhooksCard from '@/components/developers/WebhooksCard.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { useSession } from '@/composables/useSession'
import { useWebhookReceiver } from '@/composables/useWebhookReceiver'

/**
 * Developers, for owners and admins: API keys of the company, webhook subscriptions and their
 * deliveries, a quick start with curl, and the local webhook receiver, a development tool.
 */
const { t } = useI18n()
const { can } = useSession()

const canManage = computed(() => can('manage-settings'))
const { data: receiver } = useWebhookReceiver(canManage)
</script>

<template>
  <div class="grid max-w-5xl gap-6">
    <Alert v-if="!canManage">
      <InfoIcon />
      <AlertDescription>{{ t('developers.restricted') }}</AlertDescription>
    </Alert>

    <template v-else>
      <p class="text-muted-foreground">{{ t('developers.description') }}</p>
      <ApiKeysCard />
      <WebhooksCard :receiver-url="receiver?.url" />
      <QuickStartCard />
      <LocalReceiverCard />
    </template>
  </div>
</template>
