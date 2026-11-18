<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/PageHeader.vue'
import { cn } from '@/lib/utils'

const { t } = useI18n()

const tabs = [
  { name: 'company-settings', label: 'settings.tabs.company' },
  { name: 'taxes', label: 'settings.tabs.taxes' },
  { name: 'withholdings', label: 'settings.tabs.withholdings' },
  { name: 'numbering', label: 'settings.tabs.numbering' },
]
</script>

<template>
  <div class="grid gap-6">
    <PageHeader :title="t('settings.title')" :description="t('settings.description')" />

    <nav class="flex gap-1 overflow-x-auto border-b" :aria-label="t('settings.nav')">
      <RouterLink v-for="tab in tabs" :key="tab.name" v-slot="{ href, navigate, isActive }" :to="{ name: tab.name }" custom>
        <a
          :href="href"
          :aria-current="isActive ? 'page' : undefined"
          :class="cn(
            '-mb-px border-b-2 px-3 pb-2.5 text-sm font-medium whitespace-nowrap transition-colors',
            isActive ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground',
          )"
          @click="navigate"
        >
          {{ t(tab.label) }}
        </a>
      </RouterLink>
    </nav>

    <!-- Taxes and withholdings share a page component: the key gives each its own instance. -->
    <RouterView v-slot="{ Component, route }">
      <component :is="Component" :key="String(route.name)" />
    </RouterView>
  </div>
</template>
