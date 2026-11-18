<script setup lang="ts">
import type { Option } from '@/lib/options'
import { Checkbox } from '@/components/ui/checkbox'

withDefaults(defineProps<{
  options: Option[]
  idPrefix?: string
  disabled?: boolean
}>(), {
  idPrefix: 'fiscal',
  disabled: false,
})

/** Codes of the checked responsibilities. */
const model = defineModel<string[]>({ required: true })

const NOT_APPLICABLE = 'R-99-PN'

// R-99-PN (not applicable) and the other responsibilities exclude each other.
function toggle(code: string, checked: boolean): void {
  const others = model.value.filter((value) => value !== code)

  if (!checked) {
    model.value = others

    return
  }

  model.value = code === NOT_APPLICABLE
    ? [NOT_APPLICABLE]
    : [...others.filter((value) => value !== NOT_APPLICABLE), code]
}
</script>

<template>
  <div class="grid gap-2.5 sm:grid-cols-2">
    <label
      v-for="option in options"
      :key="option.value"
      :for="`${idPrefix}-${option.value}`"
      class="flex items-center gap-2.5 text-sm"
    >
      <Checkbox
        :id="`${idPrefix}-${option.value}`"
        :model-value="model.includes(option.value)"
        :disabled="disabled"
        @update:model-value="(checked) => toggle(option.value, checked === true)"
      />
      {{ option.label }}
    </label>
  </div>
</template>
