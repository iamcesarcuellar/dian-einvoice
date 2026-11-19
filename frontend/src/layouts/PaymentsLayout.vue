<script setup lang="ts">
import type { PageTab } from '@/components/PageTabs.vue'
import { PlusIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import PageTabs from '@/components/PageTabs.vue'
import { Button } from '@/components/ui/button'
import { useSession } from '@/composables/useSession'

/** The Payments section: the payments received, and the receivables by customer and age. */
const { t } = useI18n()
const route = useRoute()
const { can } = useSession()

const tabs = computed<PageTab[]>(() =>
  [
    { name: 'payments', label: t('payments.tabs.payments') },
    { name: 'receivables', label: t('payments.tabs.receivables') },
  ].map((tab) => ({ key: tab.name, label: tab.label, to: { name: tab.name }, active: route.name === tab.name })),
)

const description = computed(() => (route.name === 'receivables' ? t('receivables.description') : t('payments.description')))
</script>

<template>
  <div class="grid gap-6">
    <PageHeader :title="t('payments.title')" :description="description">
      <Button v-if="can('record-payments')" as-child>
        <RouterLink :to="{ name: 'payment-new' }">
          <PlusIcon />
          {{ t('payments.new') }}
        </RouterLink>
      </Button>
    </PageHeader>

    <PageTabs :tabs="tabs" :label="t('payments.tabs.label')" />

    <RouterView v-slot="{ Component, route: current }">
      <component :is="Component" :key="String(current.name)" />
    </RouterView>
  </div>
</template>
