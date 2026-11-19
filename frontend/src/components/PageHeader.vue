<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb'

export interface Crumb {
  label: string
  /** Omitted for a section without a page of its own, such as Sales. */
  to?: RouteLocationRaw
}

/**
 * The header of every page: breadcrumbs on detail pages, the title (with an optional #badge), a
 * short description, and the main action on the right (default slot).
 */
defineProps<{
  title: string
  description?: string
  /** The trail to this page; the last crumb is the page itself. */
  breadcrumbs?: Crumb[]
}>()
</script>

<template>
  <header class="grid gap-3">
    <Breadcrumb v-if="breadcrumbs?.length">
      <BreadcrumbList>
        <template v-for="(crumb, index) in breadcrumbs" :key="index">
          <BreadcrumbItem>
            <BreadcrumbPage v-if="index === breadcrumbs.length - 1">{{ crumb.label }}</BreadcrumbPage>
            <BreadcrumbLink v-else-if="crumb.to" as-child>
              <RouterLink :to="crumb.to">{{ crumb.label }}</RouterLink>
            </BreadcrumbLink>
            <span v-else>{{ crumb.label }}</span>
          </BreadcrumbItem>
          <BreadcrumbSeparator v-if="index < breadcrumbs.length - 1" />
        </template>
      </BreadcrumbList>
    </Breadcrumb>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div class="grid min-w-0 gap-1">
        <div class="flex flex-wrap items-center gap-2">
          <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
          <slot name="badge" />
        </div>
        <p v-if="description" class="text-muted-foreground">{{ description }}</p>
      </div>
      <div v-if="$slots.default" class="flex shrink-0 flex-wrap items-center gap-2 sm:justify-end">
        <slot />
      </div>
    </div>
  </header>
</template>
