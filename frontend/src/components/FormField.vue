<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { Label } from '@/components/ui/label'

defineProps<{
  /** Id of the input inside the slot. */
  id: string
  label: string
  error?: string
  hint?: string
  optional?: boolean
}>()

const { t } = useI18n()
</script>

<template>
  <div class="grid content-start gap-1.5">
    <Label :for="id" class="flex items-baseline gap-1.5">
      {{ label }}
      <span v-if="optional" class="text-xs font-normal text-muted-foreground">{{ t('common.optional') }}</span>
    </Label>
    <slot />
    <p v-if="error" class="text-xs text-destructive">{{ error }}</p>
    <p v-else-if="hint" class="text-xs text-muted-foreground">{{ hint }}</p>
  </div>
</template>
