<script setup lang="ts">
/**
 * The hover card of the dashboard charts. It is rendered to HTML outside the app (Unovis takes a
 * string), so it gets every text already translated and formatted, and uses no composable.
 */
export interface TooltipRow {
  label: string
  value: string
  /** A theme color, such as var(--chart-1). */
  color: string
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
      <span class="size-2.5 shrink-0 rounded-xs" :style="{ backgroundColor: row.color }" />
      <span class="text-muted-foreground">{{ row.label }}</span>
      <span class="ml-auto pl-3 font-medium tabular-nums">{{ row.value }}</span>
    </div>
    <p v-for="row in rows.filter((entry) => entry.hint)" :key="`${row.label}-hint`" class="text-muted-foreground tabular-nums">{{ row.hint }}</p>
  </div>
</template>
