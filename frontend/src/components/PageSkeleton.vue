<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { Skeleton } from '@/components/ui/skeleton'

/** The shape of a detail page while it loads: header, a wide column and a side panel. */
const { t } = useI18n()
</script>

<template>
  <div class="grid gap-6" aria-busy="true">
    <span class="sr-only">{{ t('common.loading') }}</span>
    <div class="grid gap-2">
      <Skeleton class="h-4 w-48" />
      <Skeleton class="h-8 w-64" />
      <Skeleton class="h-4 w-80 max-w-full" />
    </div>
    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
      <div class="grid gap-6">
        <Skeleton class="h-48 rounded-xl" />
        <Skeleton class="h-64 rounded-xl" />
      </div>
      <Skeleton class="h-56 rounded-xl" />
    </div>
  </div>
</template>
