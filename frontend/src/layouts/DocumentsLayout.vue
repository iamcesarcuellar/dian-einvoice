<script setup lang="ts">
import type { DocumentType } from '@/api/types'
import type { PageTab } from '@/components/PageTabs.vue'
import { PlusIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import PageTabs from '@/components/PageTabs.vue'
import { Button } from '@/components/ui/button'
import { useSession } from '@/composables/useSession'
import { DOCUMENT_ROUTES } from '@/lib/documents'

/**
 * The Invoices section: one tab per document type, each with its own list, search and filters.
 * The new button follows the tab; a note made from here starts by choosing its invoice.
 */
const { t } = useI18n()
const route = useRoute()
const { can } = useSession()

const types: Array<{ type: DocumentType; label: string }> = [
  { type: 'invoice', label: 'notes.tabs.invoices' },
  { type: 'credit_note', label: 'notes.tabs.creditNotes' },
  { type: 'debit_note', label: 'notes.tabs.debitNotes' },
]

const active = computed<DocumentType>(() => types.find((tab) => DOCUMENT_ROUTES[tab.type].list === route.name)?.type ?? 'invoice')

const tabs = computed<PageTab[]>(() =>
  types.map((tab) => ({
    key: tab.type,
    label: t(tab.label),
    to: { name: DOCUMENT_ROUTES[tab.type].list },
    active: active.value === tab.type,
  })),
)
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

    <PageTabs :tabs="tabs" :label="t('notes.tabs.label')" />

    <!-- The three lists share a page component: the key gives each its own instance. -->
    <RouterView v-slot="{ Component, route: current }">
      <component :is="Component" :key="String(current.name)" />
    </RouterView>
  </div>
</template>
