<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import { cn } from '@/lib/utils'

export interface PageTab {
  key: string
  label: string
  to: RouteLocationRaw
  /** Whether this tab is the page on screen. */
  active: boolean
}

/** The tabs of a section with several pages, such as Settings: links, so each tab has its URL. */
defineProps<{
  tabs: PageTab[]
  /** Accessible name of the navigation. */
  label: string
}>()
</script>

<template>
  <nav class="-mx-1 flex gap-1 overflow-x-auto border-b px-1" :aria-label="label">
    <RouterLink
      v-for="tab in tabs"
      :key="tab.key"
      :to="tab.to"
      :aria-current="tab.active ? 'page' : undefined"
      :class="
        cn(
          '-mb-px rounded-t-sm border-b-2 px-3 pb-2.5 text-sm font-medium whitespace-nowrap transition-colors',
          tab.active ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground',
        )
      "
    >
      {{ tab.label }}
    </RouterLink>
  </nav>
</template>
