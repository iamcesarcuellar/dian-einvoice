<script setup lang="ts">
import type { DocumentStatus } from '@/api/types'
import { useI18n } from 'vue-i18n'
import { Badge } from '@/components/ui/badge'

defineProps<{
  status: DocumentStatus
}>()

const { t } = useI18n()
</script>

<template>
  <Badge :variant="status === 'issued' ? 'default' : 'secondary'">
    {{ t(`invoices.status.${status}`) }}
  </Badge>
</template>
