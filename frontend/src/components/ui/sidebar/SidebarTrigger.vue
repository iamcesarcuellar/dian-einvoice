<script setup lang="ts">
import type { HTMLAttributes } from 'vue'

import { PanelLeftIcon } from '@lucide/vue'
import { cn } from 'cn'
import { useI18n } from 'vue-i18n'
import { Button } from '@/components/ui/button'
import { useSidebar } from './utils'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()

const { toggleSidebar } = useSidebar()
const { t } = useI18n()
</script>

<template>
  <Button
    data-sidebar="trigger"
    data-slot="sidebar-trigger"
    variant="ghost"
    size="icon-sm"
    :class="cn('', props.class)"
    @click="toggleSidebar"
  >
    <PanelLeftIcon class="cn-rtl-flip" />
    <span class="sr-only">{{ t('nav.toggleSidebar') }}</span>
  </Button>
</template>
