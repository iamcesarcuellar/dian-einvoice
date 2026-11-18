<script setup lang="ts">
import type { PaymentStatus } from '@/api/types'
import { useI18n } from 'vue-i18n'
import { Badge } from '@/components/ui/badge'

defineProps<{
  status: PaymentStatus
}>()

const { t } = useI18n()
</script>

<template>
  <Badge :variant="status === 'voided' ? 'destructive' : 'secondary'">
    {{ t(`payments.status.${status}`) }}
  </Badge>
</template>
