<script setup lang="ts">
import { computed } from 'vue'
import { encode } from 'uqr'

/**
 * A QR code drawn as SVG from its text. Always dark modules on white with a quiet zone, also in
 * dark mode, so phones can read it.
 */
const props = defineProps<{
  value: string
  /** Accessible name of the image. */
  label: string
}>()

const qr = computed(() => encode(props.value, { ecc: 'M', border: 4 }))

/** One path with a unit square per dark module. */
const path = computed(() => {
  const commands: string[] = []

  qr.value.data.forEach((row, y) => {
    row.forEach((dark, x) => {
      if (dark) {
        commands.push(`M${x} ${y}h1v1h-1z`)
      }
    })
  })

  return commands.join('')
})
</script>

<template>
  <svg :viewBox="`0 0 ${qr.size} ${qr.size}`" role="img" :aria-label="label" shape-rendering="crispEdges" class="block rounded-md">
    <rect :width="qr.size" :height="qr.size" fill="#ffffff" />
    <path :d="path" fill="#000000" />
  </svg>
</template>
