<script setup lang="ts">
import CopyButton from './CopyButton.vue'

/** A block of code or of a payload, with a copy button. */
defineProps<{
  code: string
  label?: string
  /** Hides the copy button, for read-only views such as a response. */
  noCopy?: boolean
}>()
</script>

<template>
  <div class="grid gap-1.5">
    <div v-if="label || !noCopy" class="flex items-center justify-between gap-3">
      <p v-if="label" class="text-xs font-medium text-muted-foreground">{{ label }}</p>
      <CopyButton v-if="!noCopy" :value="code" size="xs" class="ml-auto" />
    </div>
    <pre class="max-h-80 overflow-auto rounded-md border bg-muted/40 p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap break-all">{{ code }}</pre>
  </div>
</template>
