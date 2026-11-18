<script setup lang="ts">
import type { Invoice } from '@/api/types'
import { useI18n } from 'vue-i18n'
import { Badge } from '@/components/ui/badge'

/** Paid, Partially paid and Overdue labels of an issued invoice; unpaid shows no label. */
defineProps<{
  invoice: Pick<Invoice, 'payment_status' | 'overdue'>
}>()

const { t } = useI18n()
</script>

<template>
  <Badge v-if="invoice.payment_status === 'paid'" variant="secondary" class="text-emerald-700 dark:text-emerald-400">{{ t('invoices.paymentStatus.paid') }}</Badge>
  <Badge v-else-if="invoice.payment_status === 'partially_paid'" variant="secondary">{{ t('invoices.paymentStatus.partially_paid') }}</Badge>
  <Badge v-if="invoice.overdue" variant="destructive">{{ t('invoices.paymentStatus.overdue') }}</Badge>
</template>
