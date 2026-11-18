<script setup lang="ts">
import DecimalInput from '@/components/DecimalInput.vue'

/**
 * An amount of Colombian pesos: "$ 1.234.567,50" on screen and "1234567.5" for the API.
 */
withDefaults(defineProps<{
  id?: string
  /** Decimals accepted: 6 for unit prices. */
  scale?: number
  disabled?: boolean
  invalid?: boolean
}>(), {
  scale: 6,
  disabled: false,
  invalid: false,
})

const model = defineModel<string>({ required: true })
</script>

<template>
  <DecimalInput :id="id" v-model="model" :scale="scale" :min-fraction="2" prefix="$" :disabled="disabled" :invalid="invalid" />
</template>
