<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { Badge } from '@/components/ui/badge'

defineProps<{
  active: boolean
  /** Company defaults and the final consumer. */
  system?: boolean
}>()

const { t } = useI18n()
</script>

<template>
  <span class="inline-flex flex-wrap items-center gap-1.5">
    <Badge :variant="active ? 'secondary' : 'outline'" :class="active ? '' : 'text-muted-foreground'">
      {{ active ? t('common.active') : t('common.inactive') }}
    </Badge>
    <Badge v-if="system" variant="outline">{{ t('common.system') }}</Badge>
  </span>
</template>
