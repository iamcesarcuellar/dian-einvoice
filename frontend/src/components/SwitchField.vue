<script setup lang="ts">
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'

defineProps<{
  id: string
  label: string
  description?: string
  disabled?: boolean
}>()

const model = defineModel<boolean>({ required: true })
</script>

<template>
  <div class="flex items-start gap-3">
    <Switch :id="id" v-model="model" :disabled="disabled" class="mt-0.5" />
    <div class="grid gap-0.5">
      <Label :for="id">{{ label }}</Label>
      <p v-if="description" class="text-xs text-muted-foreground">{{ description }}</p>
    </div>
  </div>
</template>
