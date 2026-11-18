<script setup lang="ts">
import type { DocumentType } from '@/api/types'
import { PlusIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { useSession } from '@/composables/useSession'
import { DOCUMENT_ROUTES } from '@/lib/documents'
import { cn } from '@/lib/utils'

/**
 * The Invoices section: one tab per document type, each with its own list, search and filters.
 * The new button follows the tab; a note made from here starts by choosing its invoice.
 */
const { t } = useI18n()
const route = useRoute()
const { can } = useSession()

const tabs: Array<{ type: DocumentType, label: string }> = [
  { type: 'invoice', label: 'notes.tabs.invoices' },
  { type: 'credit_note', label: 'notes.tabs.creditNotes' },
  { type: 'debit_note', label: 'notes.tabs.debitNotes' },
]

const active = computed<DocumentType>(() => tabs
  .find((tab) => DOCUMENT_ROUTES[tab.type].list === route.name)?.type ?? 'invoice')
</script>

<template>
  <div class="grid gap-6">
    <PageHeader :title="t('invoices.title')" :description="t(`notes.descriptions.${active}`)">
      <Button v-if="can('manage-documents')" as-child>
        <RouterLink :to="{ name: DOCUMENT_ROUTES[active].create }">
          <PlusIcon />
          {{ t(`notes.new.${active}`) }}
        </RouterLink>
      </Button>
    </PageHeader>

    <nav class="flex gap-1 overflow-x-auto border-b" :aria-label="t('notes.tabs.label')">
      <RouterLink v-for="tab in tabs" :key="tab.type" v-slot="{ href, navigate }" :to="{ name: DOCUMENT_ROUTES[tab.type].list }" custom>
        <a
          :href="href"
          :aria-current="active === tab.type ? 'page' : undefined"
          :class="cn(
            '-mb-px border-b-2 px-3 pb-2.5 text-sm font-medium whitespace-nowrap transition-colors',
            active === tab.type ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground',
          )"
          @click="navigate"
        >
          {{ t(tab.label) }}
        </a>
      </RouterLink>
    </nav>

    <!-- The three lists share a page component: the key gives each its own instance. -->
    <RouterView v-slot="{ Component, route: current }">
      <component :is="Component" :key="String(current.name)" />
    </RouterView>
  </div>
</template>
