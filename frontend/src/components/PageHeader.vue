<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import { ArrowLeftIcon } from '@lucide/vue'
import { useI18n } from 'vue-i18n'

defineProps<{
  title: string
  description?: string
  /** Shows a link back to this location above the title. */
  back?: RouteLocationRaw
}>()

const { t } = useI18n()
</script>

<template>
  <header class="flex flex-wrap items-end justify-between gap-4">
    <div class="grid gap-1">
      <RouterLink
        v-if="back"
        :to="back"
        class="mb-1 inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeftIcon class="size-4" />
        {{ t('common.back') }}
      </RouterLink>
      <div class="flex flex-wrap items-center gap-2">
        <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
        <slot name="badge" />
      </div>
      <p v-if="description" class="text-muted-foreground">{{ description }}</p>
    </div>
    <div v-if="$slots.default" class="flex items-center gap-2">
      <slot />
    </div>
  </header>
</template>
