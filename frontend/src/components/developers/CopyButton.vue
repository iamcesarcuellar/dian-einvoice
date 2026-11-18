<script setup lang="ts">
import { CheckIcon, CopyIcon } from '@lucide/vue'
import { onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Button } from '@/components/ui/button'

/** Copies a text to the clipboard and says so for two seconds. */
const props = withDefaults(defineProps<{
  value: string
  label?: string
  size?: 'sm' | 'xs'
}>(), {
  size: 'sm',
})

const { t } = useI18n()
const copied = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined

async function copy(): Promise<void> {
  await navigator.clipboard.writeText(props.value)
  copied.value = true
  clearTimeout(timer)
  timer = setTimeout(() => (copied.value = false), 2000)
}

onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <Button type="button" variant="outline" :size="size" @click="copy">
    <CheckIcon v-if="copied" />
    <CopyIcon v-else />
    {{ copied ? t('developers.copied') : (label ?? t('developers.copy')) }}
  </Button>
</template>
