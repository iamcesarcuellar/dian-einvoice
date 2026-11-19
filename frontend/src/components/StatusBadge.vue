<script setup lang="ts">
import type { StatusDomain } from '@/lib/status'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Badge } from '@/components/ui/badge'
import { statusKey, statusStyle, TONE_BADGE, TONE_TEXT } from '@/lib/status'
import { cn } from '@/lib/utils'

/**
 * The label of any status, from the one map in lib/status.ts: same state, same color, same text.
 * Compact shows only the icon (with the label for screen readers and as a tooltip), for dense lists.
 */
const props = defineProps<{
  domain: StatusDomain
  status: string
  compact?: boolean
  /** Extra explanation on hover. */
  hint?: string
}>()

const i18n = useI18n()

const style = computed(() => statusStyle(props.domain, props.status))
// A state this version of the app has no label for shows as the API sends it.
const label = computed(() => {
  const key = statusKey(props.domain, props.status)

  return i18n.te(key) ? i18n.t(key) : props.status
})
const title = computed(() => (props.hint ? `${label.value}: ${props.hint}` : undefined))
</script>

<template>
  <span v-if="compact && style.icon" class="inline-flex items-center" :title="title ?? label">
    <component :is="style.icon" :class="cn('size-4', TONE_TEXT[style.tone], style.spin && 'animate-spin')" aria-hidden="true" />
    <span class="sr-only">{{ label }}</span>
  </span>
  <Badge v-else variant="outline" :class="TONE_BADGE[style.tone]" :title="title">
    <component :is="style.icon" v-if="style.icon" :class="style.spin ? 'animate-spin' : ''" aria-hidden="true" />
    {{ label }}
  </Badge>
</template>
