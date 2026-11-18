<script setup lang="ts">
import type { BalanceStatus } from '@/api/types'
import { useI18n } from 'vue-i18n'
import { Badge } from '@/components/ui/badge'

defineProps<{
  status: BalanceStatus
}>()

const { t } = useI18n()
</script>

<template>
  <Badge :variant="status === 'voided' ? 'destructive' : (status === 'partially_credited' ? 'secondary' : 'outline')">
    {{ t(`notes.balanceStatus.${status}`) }}
  </Badge>
</template>
