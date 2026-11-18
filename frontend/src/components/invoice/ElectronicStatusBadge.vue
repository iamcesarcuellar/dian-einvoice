<script setup lang="ts">
import type { ElectronicStatus } from '@/api/types'
import { CircleCheckIcon, CircleXIcon, LoaderCircleIcon, TriangleAlertIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

/**
 * Color label of the electronic document: Processing while the chain runs, then Accepted, Rejected
 * or Failed. Accepted and rejected always mean by the local DIAN simulator, never by the DIAN.
 */
const props = defineProps<{
  status: ElectronicStatus
}>()

const { t } = useI18n()

type Group = 'processing' | 'accepted' | 'rejected' | 'failed'

const group = computed<Group>(() => {
  switch (props.status) {
    case 'accepted':
    case 'rejected':
    case 'failed':
      return props.status
    default:
      return 'processing'
  }
})

const classes: Record<Group, string> = {
  processing: 'bg-sky-100 text-sky-900 dark:bg-sky-500/20 dark:text-sky-100',
  accepted: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/20 dark:text-emerald-100',
  rejected: 'bg-red-100 text-red-900 dark:bg-red-500/20 dark:text-red-100',
  failed: 'bg-amber-100 text-amber-950 dark:bg-amber-500/20 dark:text-amber-100',
}

const icons = {
  processing: LoaderCircleIcon,
  accepted: CircleCheckIcon,
  rejected: CircleXIcon,
  failed: TriangleAlertIcon,
}
</script>

<template>
  <Badge
    variant="outline"
    :class="cn('border-transparent', classes[group])"
    :title="group === 'accepted' || group === 'rejected' ? t('invoices.electronic.bySimulator') : undefined"
  >
    <component :is="icons[group]" :class="group === 'processing' ? 'animate-spin' : ''" aria-hidden="true" />
    {{ t(`invoices.electronic.${group}`) }}
  </Badge>
</template>
