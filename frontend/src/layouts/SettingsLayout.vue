<script setup lang="ts">
import type { PageTab } from '@/components/PageTabs.vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import PageTabs from '@/components/PageTabs.vue'
import { useSession } from '@/composables/useSession'

/** Settings of the current company, one tab per page; Team only for owners and admins. */
const { t } = useI18n()
const route = useRoute()
const { can } = useSession()

const tabs = computed<PageTab[]>(() =>
  [
    { name: 'company-settings', label: 'settings.tabs.company' },
    { name: 'taxes', label: 'settings.tabs.taxes' },
    { name: 'withholdings', label: 'settings.tabs.withholdings' },
    { name: 'numbering', label: 'settings.tabs.numbering' },
    { name: 'dian-settings', label: 'settings.tabs.dian' },
    ...(can('manage-team') ? [{ name: 'team', label: 'settings.tabs.team' }] : []),
    { name: 'developers', label: 'settings.tabs.developers' },
  ].map((tab) => ({ key: tab.name, label: t(tab.label), to: { name: tab.name }, active: route.name === tab.name })),
)
</script>

<template>
  <div class="grid gap-6">
    <PageHeader :title="t('settings.title')" :description="t('settings.description')" />

    <PageTabs :tabs="tabs" :label="t('settings.nav')" />

    <!-- Taxes and withholdings share a page component: the key gives each its own instance. -->
    <RouterView v-slot="{ Component, route: current }">
      <component :is="Component" :key="String(current.name)" />
    </RouterView>
  </div>
</template>
