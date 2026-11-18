<script setup lang="ts">
import { ExternalLinkIcon, InboxIcon, InfoIcon } from '@lucide/vue'
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { localMailboxApi } from '@/api/delivery'
import FormError from '@/components/FormError.vue'
import FormSection from '@/components/FormSection.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * The local development mailbox, a development tool next to the local simulator: every email of
 * this project lands there, invoices delivered to customers included, and none leaves the machine.
 * It links to its web interface; the API tells its address and whether it answers.
 */
const { t } = useI18n()
const session = useSessionStore()

const { data: mailbox, error } = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, 'local-mailbox')),
  queryFn: () => localMailboxApi.get(),
  refetchInterval: 10_000,
})
</script>

<template>
  <FormSection :title="t('settings.dian.mailbox.title')" :description="t('settings.dian.mailbox.description')">
    <div class="grid gap-4">
      <FormError :error="error" />

      <div v-if="mailbox" class="grid gap-3">
        <div class="flex flex-wrap items-center gap-2">
          <Badge variant="secondary">
            <InboxIcon />
            {{ mailbox.reachable ? t('settings.dian.mailbox.reachable') : t('settings.dian.mailbox.unreachable') }}
          </Badge>
          <span v-if="mailbox.reachable && mailbox.messages !== null" class="text-sm text-muted-foreground tabular-nums">
            {{ t('settings.dian.mailbox.messages', { count: mailbox.messages }) }}
          </span>
        </div>

        <div class="grid gap-0.5">
          <p class="text-xs text-muted-foreground">{{ t('settings.dian.mailbox.address') }}</p>
          <p class="font-mono text-sm break-all">{{ mailbox.url }}</p>
        </div>

        <p v-if="!mailbox.reachable" class="text-sm text-destructive">{{ t('settings.dian.mailbox.unreachableNote') }}</p>

        <div>
          <Button as-child variant="outline" size="sm">
            <a :href="mailbox.url" target="_blank" rel="noopener noreferrer">
              <ExternalLinkIcon />
              {{ t('settings.dian.mailbox.open') }}
            </a>
          </Button>
        </div>
      </div>
      <p v-else-if="!error" class="text-muted-foreground">{{ t('common.loading') }}</p>

      <Alert>
        <InfoIcon />
        <AlertDescription>{{ t('settings.dian.mailbox.note') }}</AlertDescription>
      </Alert>
    </div>
  </FormSection>
</template>
