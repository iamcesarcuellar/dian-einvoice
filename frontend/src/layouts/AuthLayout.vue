<script setup lang="ts">
import { CheckIcon, ReceiptTextIcon } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import BrandMark from '@/components/BrandMark.vue'
import TestEnvironmentBadge from '@/components/TestEnvironmentBadge.vue'
import { product } from '@/config/product'
import { cn } from '@/lib/utils'

/**
 * The pages without the app frame (sign in, create an account, an invitation): the product on the
 * left, from lg up, and the form on the right.
 */
defineProps<{
  title: string
  description: string
  /** Wider form column for longer forms. */
  wide?: boolean
}>()

const { t } = useI18n()

const features = ['auth.panel.features.document', 'auth.panel.features.simulator', 'auth.panel.features.delivery', 'auth.panel.features.api']
</script>

<template>
  <div class="grid min-h-screen bg-background lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
    <aside class="relative hidden overflow-hidden bg-primary text-primary-foreground lg:flex lg:flex-col lg:justify-between lg:p-12">
      <div class="pointer-events-none absolute -top-32 -right-32 size-96 rounded-full bg-primary-foreground/10" aria-hidden="true" />
      <div class="pointer-events-none absolute -bottom-40 -left-24 size-112 rounded-full bg-primary-foreground/5" aria-hidden="true" />

      <span class="relative flex items-center gap-2.5">
        <span class="flex size-8 items-center justify-center rounded-md bg-primary-foreground/15">
          <ReceiptTextIcon class="size-4" aria-hidden="true" />
        </span>
        <span class="text-base font-semibold tracking-tight">{{ product.name }}</span>
      </span>

      <div class="relative grid max-w-md gap-8">
        <p class="text-3xl leading-tight font-semibold tracking-tight text-balance">{{ t('auth.panel.tagline') }}</p>
        <ul class="grid gap-3 text-sm text-primary-foreground/85">
          <li v-for="feature in features" :key="feature" class="flex items-start gap-3">
            <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-primary-foreground/15">
              <CheckIcon class="size-3" aria-hidden="true" />
            </span>
            {{ t(feature) }}
          </li>
        </ul>
      </div>

      <p class="relative max-w-md text-xs text-primary-foreground/70">{{ t('about.disclaimer') }}</p>
    </aside>

    <main class="flex flex-col px-4 py-8 sm:px-8">
      <div class="flex items-center justify-between gap-4">
        <BrandMark class="lg:invisible" />
        <TestEnvironmentBadge />
      </div>

      <div class="flex flex-1 items-center justify-center py-10">
        <div :class="cn('grid w-full gap-6', wide ? 'max-w-lg' : 'max-w-sm')">
          <div class="grid gap-1.5">
            <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
            <p class="text-muted-foreground">{{ description }}</p>
          </div>

          <slot />

          <p v-if="$slots.footer" class="text-sm text-muted-foreground">
            <slot name="footer" />
          </p>
        </div>
      </div>

      <p class="text-center text-xs text-muted-foreground lg:hidden">{{ t('about.disclaimer') }}</p>
    </main>
  </div>
</template>
