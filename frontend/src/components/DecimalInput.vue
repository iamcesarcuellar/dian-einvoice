<script setup lang="ts">
import { ref, watch } from 'vue'
import { Input } from '@/components/ui/input'
import { formatDecimal, parseDecimal, toEditableDecimal } from '@/lib/format'
import { cn } from '@/lib/utils'

/**
 * A decimal amount shown the Colombian way ("1.234.567,50") and kept as an API decimal string
 * ("1234567.5"). While focused it shows the plain number for editing.
 */
const props = withDefaults(defineProps<{
  id?: string
  /** Decimals accepted: 6 for prices, 4 for rates. */
  scale: number
  /** Decimals shown when the amount has any: 2 for money. */
  minFraction?: number
  prefix?: string
  suffix?: string
  disabled?: boolean
  invalid?: boolean
}>(), {
  minFraction: 0,
  disabled: false,
  invalid: false,
})

const model = defineModel<string>({ required: true })

const focused = ref(false)
const text = ref(formatDecimal(model.value, props.minFraction))

watch(model, (value) => {
  if (!focused.value) {
    text.value = formatDecimal(value, props.minFraction)
  }
})

function onInput(value: string | number): void {
  text.value = String(value)

  // Anything that is not a number is kept as typed, so the API can say what is wrong.
  model.value = parseDecimal(text.value, props.scale) ?? text.value
}

function onFocus(): void {
  focused.value = true
  text.value = toEditableDecimal(model.value)
}

function onBlur(): void {
  focused.value = false

  const parsed = parseDecimal(text.value, props.scale)

  if (parsed !== null) {
    model.value = parsed
    text.value = formatDecimal(parsed, props.minFraction)
  }
}
</script>

<template>
  <div class="relative">
    <span v-if="prefix" class="pointer-events-none absolute inset-y-0 left-2.5 flex items-center text-muted-foreground">
      {{ prefix }}
    </span>
    <Input
      :id="id"
      :model-value="text"
      inputmode="decimal"
      autocomplete="off"
      :disabled="disabled"
      :aria-invalid="invalid || undefined"
      :class="cn('text-right tabular-nums', prefix && 'pl-7', suffix && 'pr-8')"
      @update:model-value="onInput"
      @focus="onFocus"
      @blur="onBlur"
    />
    <span v-if="suffix" class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-muted-foreground">
      {{ suffix }}
    </span>
  </div>
</template>
