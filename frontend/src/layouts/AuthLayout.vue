<script setup lang="ts">
import BrandMark from '@/components/BrandMark.vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { cn } from '@/lib/utils'

defineProps<{
  title: string
  description: string
  /** Wider card for longer forms. */
  wide?: boolean
}>()
</script>

<template>
  <div class="flex min-h-screen flex-col items-center justify-center bg-muted/40 px-4 py-12">
    <BrandMark class="mb-8" />

    <Card :class="cn('w-full', wide ? 'max-w-lg' : 'max-w-md')">
      <CardHeader>
        <CardTitle class="text-lg font-semibold tracking-tight">{{ title }}</CardTitle>
        <CardDescription>{{ description }}</CardDescription>
      </CardHeader>
      <CardContent>
        <slot />
      </CardContent>
    </Card>

    <p class="mt-6 text-sm text-muted-foreground">
      <slot name="footer" />
    </p>
  </div>
</template>
