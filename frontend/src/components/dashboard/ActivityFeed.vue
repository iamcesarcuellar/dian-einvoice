<script setup lang="ts">
import type { Component } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
import type { ActivityEntry } from '@/api/workspace'
import {
  BanIcon,
  CircleCheckIcon,
  CircleXIcon,
  FileCheckIcon,
  FileWarningIcon,
  MailCheckIcon,
  MailWarningIcon,
  RefreshCwIcon,
  SendIcon,
  WalletIcon,
} from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { DOCUMENT_ROUTES } from '@/lib/documents'
import { formatDateTime, formatMoney, formatWholeMoney } from '@/lib/format'
import { TONE_TEXT } from '@/lib/status'
import { cn } from '@/lib/utils'

/** The latest entries of the timelines of documents and payments, with who did it and a link. */
defineProps<{
  entries: ActivityEntry[]
}>()

const { t } = useI18n()

const ICONS: Record<string, { icon: Component; tone: string }> = {
  issued: { icon: FileCheckIcon, tone: TONE_TEXT.neutral },
  accepted: { icon: CircleCheckIcon, tone: TONE_TEXT.success },
  rejected: { icon: CircleXIcon, tone: TONE_TEXT.danger },
  xml_failed: { icon: FileWarningIcon, tone: TONE_TEXT.warning },
  transmission_failed: { icon: FileWarningIcon, tone: TONE_TEXT.warning },
  retry_requested: { icon: RefreshCwIcon, tone: TONE_TEXT.neutral },
  resend_requested: { icon: SendIcon, tone: TONE_TEXT.neutral },
  delivered: { icon: MailCheckIcon, tone: TONE_TEXT.success },
  delivery_failed: { icon: MailWarningIcon, tone: TONE_TEXT.warning },
  delivery_requested: { icon: SendIcon, tone: TONE_TEXT.neutral },
  pdf_regeneration_requested: { icon: RefreshCwIcon, tone: TONE_TEXT.neutral },
  recorded: { icon: WalletIcon, tone: TONE_TEXT.success },
  voided: { icon: BanIcon, tone: TONE_TEXT.danger },
}

const FALLBACK = { icon: FileCheckIcon, tone: TONE_TEXT.outline }

function look(entry: ActivityEntry) {
  return ICONS[entry.type] ?? FALLBACK
}

function link(entry: ActivityEntry): RouteLocationRaw {
  return entry.subject.type === 'payment'
    ? { name: 'payment', params: { id: entry.subject.id } }
    : { name: DOCUMENT_ROUTES[entry.subject.type].detail, params: { id: entry.subject.id } }
}

function actor(entry: ActivityEntry): string {
  if (!entry.actor) {
    return t('dashboard.activity.system')
  }

  return entry.actor.type === 'api_key' ? t('dashboard.activity.apiKey', { name: entry.actor.name }) : entry.actor.name
}

function sentence(entry: ActivityEntry): string {
  const key = `dashboard.activity.types.${entry.source === 'payment' ? `payment_${entry.type}` : entry.type}`

  return t(key, {
    actor: actor(entry),
    subject: t(`dashboard.activity.subjects.${entry.subject.type}`),
  })
}
</script>

<template>
  <p v-if="entries.length === 0" class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">{{ t('dashboard.activity.empty') }}</p>
  <ol v-else class="grid gap-0.5">
    <li v-for="entry in entries" :key="`${entry.source}-${entry.id}`" class="flex items-start gap-3 rounded-md px-2 py-2 hover:bg-muted/60">
      <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-muted">
        <component :is="look(entry).icon" :class="cn('size-3.5', look(entry).tone)" aria-hidden="true" />
      </span>
      <div class="grid min-w-0 flex-1 gap-0.5">
        <p class="text-sm">
          {{ sentence(entry) }}
          <RouterLink :to="link(entry)" class="font-medium tabular-nums hover:underline">{{ entry.subject.number ?? t('invoices.draft') }}</RouterLink>
          <span v-if="entry.amount" class="text-muted-foreground tabular-nums" :title="formatMoney(entry.amount)"> · {{ formatWholeMoney(entry.amount) }}</span>
          <span v-if="entry.detail" class="text-muted-foreground"> · {{ entry.detail }}</span>
        </p>
        <p class="truncate text-xs text-muted-foreground tabular-nums">
          {{ [entry.subject.customer_name, formatDateTime(entry.occurred_at)].filter(Boolean).join(' · ') }}
        </p>
      </div>
    </li>
  </ol>
</template>
