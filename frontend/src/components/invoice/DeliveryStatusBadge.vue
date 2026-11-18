<script setup lang="ts">
import type { DeliveryStatus } from '@/api/types'
import { LoaderCircleIcon, MailCheckIcon, MailIcon, MailMinusIcon, MailWarningIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

/**
 * State of the delivery of an invoice to its customer: Delivering, Delivered, Failed or Not
 * applicable (no email), and Not started before the local simulator accepts it. Compact shows only
 * the icon, for the list, with the label for screen readers and as a tooltip.
 */
const props = defineProps<{
  status: DeliveryStatus | null
  compact?: boolean
}>()

const { t } = useI18n()

type Key = DeliveryStatus | 'waiting'

const key = computed<Key>(() => props.status ?? 'waiting')

const classes: Record<Key, string> = {
  pending: 'bg-sky-100 text-sky-900 dark:bg-sky-500/20 dark:text-sky-100',
  delivered: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-500/20 dark:text-emerald-100',
  failed: 'bg-amber-100 text-amber-950 dark:bg-amber-500/20 dark:text-amber-100',
  not_applicable: 'bg-muted text-muted-foreground',
  waiting: 'bg-muted text-muted-foreground',
}

const iconClasses: Record<Key, string> = {
  pending: 'text-sky-600 dark:text-sky-300',
  delivered: 'text-emerald-600 dark:text-emerald-300',
  failed: 'text-amber-600 dark:text-amber-300',
  not_applicable: 'text-muted-foreground',
  waiting: 'text-muted-foreground/60',
}

const icons = {
  pending: LoaderCircleIcon,
  delivered: MailCheckIcon,
  failed: MailWarningIcon,
  not_applicable: MailMinusIcon,
  waiting: MailIcon,
}

const label = computed(() => t(`invoices.delivery.status.${key.value}`))
const title = computed(() => t(`invoices.delivery.statusTitle.${key.value}`))
</script>

<template>
  <span v-if="compact" class="inline-flex items-center" :title="`${label}: ${title}`">
    <component :is="icons[key]" :class="cn('size-4', iconClasses[key], key === 'pending' ? 'animate-spin' : '')" aria-hidden="true" />
    <span class="sr-only">{{ label }}</span>
  </span>
  <Badge v-else variant="outline" :class="cn('border-transparent', classes[key])" :title="title">
    <component :is="icons[key]" :class="key === 'pending' ? 'animate-spin' : ''" aria-hidden="true" />
    {{ label }}
  </Badge>
</template>
