<script setup lang="ts">
import type { Dashboard } from '@/api/workspace'
import { HashIcon, ShieldCheckIcon, TriangleAlertIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import StatusBadge from '@/components/StatusBadge.vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useSession } from '@/composables/useSession'
import { formatDate, formatDateTime, formatInteger, formatPercent } from '@/lib/format'
import { cn } from '@/lib/utils'

/**
 * What can stop invoicing soon: the invoice numbering (how much of the range is used and the days
 * until it expires) and the signing certificate (days until it expires). Either one stands out
 * when it passes 80 % or has less than 30 days left, or when it is missing.
 */
const props = defineProps<{
  notices: Dashboard['notices']
}>()

const { t } = useI18n()
const { can } = useSession()

const numbering = computed(() => props.notices.numbering)
const certificate = computed(() => props.notices.certificate)
const usageWidth = computed(() => `${Math.min(100, Math.max(numbering.value ? Number(numbering.value.usage) : 0, 0.5))}%`)

function highlighted(warning: boolean): string {
  return warning ? 'ring-warning/60 bg-warning/8' : ''
}
</script>

<template>
  <div class="grid gap-4">
    <Card :class="cn('gap-3', highlighted(!numbering || numbering.warning))">
      <CardHeader class="flex items-start justify-between gap-3">
        <div class="grid gap-1">
          <CardTitle class="flex items-center gap-2 text-sm">
            <HashIcon class="size-4 text-muted-foreground" aria-hidden="true" />
            {{ t('dashboard.notices.numbering.title') }}
          </CardTitle>
          <CardDescription v-if="numbering">{{ t('dashboard.notices.numbering.prefix', { prefix: numbering.prefix ?? '—' }) }}</CardDescription>
        </div>
        <StatusBadge v-if="numbering" domain="numbering" :status="numbering.status" />
      </CardHeader>
      <CardContent class="grid gap-2 text-sm">
        <template v-if="numbering">
          <div class="h-1.5 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="Number(numbering.usage)" aria-valuemin="0" aria-valuemax="100" :aria-label="t('dashboard.notices.numbering.usage')">
            <div :class="cn('h-full rounded-full', numbering.warning ? 'bg-warning' : 'bg-primary')" :style="{ width: usageWidth }" />
          </div>
          <p class="text-muted-foreground tabular-nums">{{ t('dashboard.notices.numbering.used', { used: formatInteger(numbering.used), total: formatInteger(numbering.total), percent: formatPercent(numbering.usage) }) }}</p>
          <p v-if="numbering.days_left !== null" :class="cn('tabular-nums', numbering.warning ? 'font-medium text-warning' : 'text-muted-foreground')">
            {{ t('dashboard.notices.numbering.expires', { date: formatDate(numbering.valid_to), days: formatInteger(numbering.days_left) }, numbering.days_left) }}
          </p>
        </template>
        <p v-else class="flex items-start gap-2 font-medium text-warning">
          <TriangleAlertIcon class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
          {{ t('dashboard.notices.numbering.missing') }}
        </p>
        <RouterLink v-if="can('manage-settings')" :to="{ name: 'numbering' }" class="w-fit text-xs font-medium text-primary hover:underline">{{ t('dashboard.notices.numbering.manage') }}</RouterLink>
      </CardContent>
    </Card>

    <Card :class="cn('gap-3', highlighted(!certificate || certificate.warning))">
      <CardHeader class="flex items-start justify-between gap-3">
        <div class="grid gap-1">
          <CardTitle class="flex items-center gap-2 text-sm">
            <ShieldCheckIcon class="size-4 text-muted-foreground" aria-hidden="true" />
            {{ t('dashboard.notices.certificate.title') }}
          </CardTitle>
          <CardDescription v-if="certificate" class="line-clamp-1" :title="certificate.subject">{{ certificate.subject }}</CardDescription>
        </div>
        <StatusBadge v-if="certificate" domain="certificate" :status="certificate.status" />
      </CardHeader>
      <CardContent class="grid gap-2 text-sm">
        <template v-if="certificate">
          <p :class="cn('tabular-nums', certificate.warning ? 'font-medium text-warning' : 'text-muted-foreground')">
            {{ certificate.days_left >= 0
              ? t('dashboard.notices.certificate.expires', { date: formatDateTime(certificate.valid_to), days: formatInteger(certificate.days_left) }, certificate.days_left)
              : t('dashboard.notices.certificate.expired', { date: formatDateTime(certificate.valid_to) }) }}
          </p>
          <p v-if="certificate.is_test" class="text-xs text-muted-foreground">{{ t('dashboard.notices.certificate.test') }}</p>
        </template>
        <p v-else class="flex items-start gap-2 font-medium text-warning">
          <TriangleAlertIcon class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
          {{ t('dashboard.notices.certificate.missing') }}
        </p>
        <RouterLink v-if="can('manage-settings')" :to="{ name: 'dian-settings' }" class="w-fit text-xs font-medium text-primary hover:underline">{{ t('dashboard.notices.certificate.manage') }}</RouterLink>
      </CardContent>
    </Card>
  </div>
</template>
