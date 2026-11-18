<script setup lang="ts">
import type { DocumentTaxSubtotal, DocumentTotals, DocumentWithholdingAmount } from '@/api/types'
import { useI18n } from 'vue-i18n'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { formatMoney, formatPercent } from '@/lib/format'

/** Totals of a document as the API calculated them; the web app does no arithmetic. */
withDefaults(defineProps<{
  totals: DocumentTotals | null
  taxes: DocumentTaxSubtotal[]
  withholdings: DocumentWithholdingAmount[]
  pending?: boolean
  /** Shown instead of the totals, such as when the draft has invalid values. */
  notice?: string | null
}>(), {
  pending: false,
  notice: null,
})

const { t } = useI18n()

function money(value: string | undefined): string {
  return formatMoney(value ?? '0') || '$ 0'
}
</script>

<template>
  <Card>
    <CardHeader class="flex items-center justify-between gap-2">
      <CardTitle>{{ t('invoices.totals.title') }}</CardTitle>
      <span v-if="pending" class="text-xs text-muted-foreground" role="status">{{ t('invoices.totals.calculating') }}</span>
    </CardHeader>
    <CardContent>
      <p v-if="notice" class="text-sm text-muted-foreground">{{ notice }}</p>

      <dl v-else class="grid gap-2.5 text-sm">
        <div class="flex items-baseline justify-between gap-4">
          <dt class="text-muted-foreground">{{ t('invoices.totals.subtotal') }}</dt>
          <dd class="tabular-nums">{{ money(totals?.subtotal_amount) }}</dd>
        </div>
        <div class="flex items-baseline justify-between gap-4">
          <dt class="text-muted-foreground">{{ t('invoices.totals.discounts') }}</dt>
          <dd class="tabular-nums">− {{ money(totals?.discount_total) }}</dd>
        </div>
        <div v-for="tax in taxes" :key="`${tax.dian_code}-${tax.rate}`" class="flex items-baseline justify-between gap-4">
          <dt class="grid text-muted-foreground">
            <span>{{ tax.name }} {{ formatPercent(tax.rate) }}</span>
            <span class="text-xs">{{ t('invoices.totals.on', { base: money(tax.taxable_amount) }) }}</span>
          </dt>
          <dd class="tabular-nums">{{ money(tax.tax_amount) }}</dd>
        </div>
        <div class="flex items-baseline justify-between gap-4 border-t pt-2.5 font-semibold">
          <dt>{{ t('invoices.totals.total') }}</dt>
          <dd class="tabular-nums">{{ money(totals?.payable_amount) }}</dd>
        </div>
        <div v-for="withholding in withholdings" :key="withholding.withholding_id ?? withholding.name" class="flex items-baseline justify-between gap-4">
          <dt class="grid text-muted-foreground">
            <span>{{ withholding.name }}</span>
            <span class="text-xs">{{ t('invoices.totals.on', { base: money(withholding.base_amount) }) }}</span>
          </dt>
          <dd class="tabular-nums">− {{ money(withholding.amount) }}</dd>
        </div>
        <div class="flex items-baseline justify-between gap-4 border-t pt-2.5 text-base font-semibold">
          <dt>{{ t('invoices.totals.netToReceive') }}</dt>
          <dd class="text-primary tabular-nums">{{ money(totals?.amount_due) }}</dd>
        </div>
      </dl>
    </CardContent>
  </Card>
</template>
