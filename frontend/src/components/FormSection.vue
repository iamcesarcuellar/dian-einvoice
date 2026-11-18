<script setup lang="ts">
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'

defineProps<{
  title: string
  description?: string
}>()
</script>

<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ title }}</CardTitle>
      <CardDescription v-if="description">{{ description }}</CardDescription>
    </CardHeader>
    <CardContent>
      <slot />
    </CardContent>
    <CardFooter v-if="$slots.footer" class="justify-end gap-3 border-t">
      <slot name="footer" />
    </CardFooter>
  </Card>
</template>
