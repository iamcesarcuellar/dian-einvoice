<script setup lang="ts">
import { ReceiptTextIcon } from '@lucide/vue'
import { product } from '@/config/product'

/** The product mark and name; the name hides when the sidebar folds to icons. */
</script>

<template>
  <span class="flex items-center gap-2.5">
    <span class="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary text-primary-foreground">
      <ReceiptTextIcon class="size-4" aria-hidden="true" />
    </span>
    <span class="truncate text-[15px] font-semibold tracking-tight text-foreground group-data-[collapsible=icon]:hidden">{{ product.name }}</span>
  </span>
</template>
