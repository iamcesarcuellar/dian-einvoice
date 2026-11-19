<script setup lang="ts">
import type { Option } from '@/lib/options'
import { useI18n } from 'vue-i18n'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'

withDefaults(defineProps<{
  id?: string
  options: Option[]
  placeholder?: string
  /** Accessible name when there is no visible label, as in the filters of a list. */
  label?: string
  disabled?: boolean
  invalid?: boolean
}>(), {
  disabled: false,
  invalid: false,
})

const model = defineModel<string | null>({ required: true })

const { t } = useI18n()

function update(value: unknown): void {
  model.value = typeof value === 'string' ? value : null
}
</script>

<template>
  <Select :model-value="model ?? undefined" :disabled="disabled" @update:model-value="update">
    <SelectTrigger :id="id" class="w-full" :aria-invalid="invalid || undefined" :aria-label="label">
      <SelectValue :placeholder="placeholder ?? t('common.choose')" />
    </SelectTrigger>
    <SelectContent position="popper" class="max-h-72">
      <SelectItem v-for="option in options" :key="option.value" :value="option.value">
        {{ option.label }}
      </SelectItem>
    </SelectContent>
  </Select>
</template>
