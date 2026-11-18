<script setup lang="ts">
import { PlusIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { useSession } from '@/composables/useSession'
import { cn } from '@/lib/utils'

/** The Payments section: the payments received, and the receivables by customer and age. */
const { t } = useI18n()
const route = useRoute()
const { can } = useSession()

const tabs = [
  { name: 'payments', label: 'payments.tabs.payments' },
  { name: 'receivables', label: 'payments.tabs.receivables' },
]

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

    <nav class="flex gap-1 overflow-x-auto border-b" :aria-label="t('payments.tabs.label')">
      <RouterLink v-for="tab in tabs" :key="tab.name" v-slot="{ href, navigate, isExactActive }" :to="{ name: tab.name }" custom>
        <a
          :href="href"
          :aria-current="isExactActive ? 'page' : undefined"
          :class="cn(
            '-mb-px border-b-2 px-3 pb-2.5 text-sm font-medium whitespace-nowrap transition-colors',
            isExactActive ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground',
          )"
          @click="navigate"
        >
          {{ t(tab.label) }}
        </a>
      </RouterLink>
    </nav>

    <RouterView v-slot="{ Component, route: current }">
      <component :is="Component" :key="String(current.name)" />
    </RouterView>
  </div>
</template>
