<script setup lang="ts">
/**
 * The hover card of the dashboard charts. It is rendered to HTML outside the app (Unovis takes a
 * string), so it gets every text already translated and formatted, and uses no composable. Its
 * swatch is a class, never a style attribute: Unovis inserts the HTML as markup, and the
 * Content-Security-Policy of the edge refuses style attributes in markup.
 */
export interface TooltipRow {
  label: string
  value: string
  /** The background class of the swatch, such as bg-chart-1. */
  swatch: string
  hint?: string
}

defineProps<{
  title: string
  rows: TooltipRow[]
}>()
</script>

<template>
  <div class="grid min-w-40 gap-1.5 rounded-lg border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-lg">
    <p class="font-medium">{{ title }}</p>
    <div v-for="row in rows" :key="row.label" class="flex items-center gap-2">
      <span :class="['size-2.5 shrink-0 rounded-xs', row.swatch]" />
      <span class="text-muted-foreground">{{ row.label }}</span>
      <span class="ml-auto pl-3 font-medium tabular-nums">{{ row.value }}</span>
    </div>
    <p v-for="row in rows.filter((entry) => entry.hint)" :key="`${row.label}-hint`" class="text-muted-foreground tabular-nums">{{ row.hint }}</p>
  </div>
</template>
