<script setup lang="ts">
import { FilePlusIcon, InboxIcon, RotateCwIcon, UserPlusIcon, WalletIcon } from '@lucide/vue'
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { workspaceApi } from '@/api/workspace'
import ActivityFeed from '@/components/dashboard/ActivityFeed.vue'
import AgingChart from '@/components/dashboard/AgingChart.vue'
import AttentionList from '@/components/dashboard/AttentionList.vue'
import ElectronicSummary from '@/components/dashboard/ElectronicSummary.vue'
import KpiCard from '@/components/dashboard/KpiCard.vue'
import MonthlyChart from '@/components/dashboard/MonthlyChart.vue'
import NoticesCard from '@/components/dashboard/NoticesCard.vue'
import TopCustomers from '@/components/dashboard/TopCustomers.vue'
import FormError from '@/components/FormError.vue'
import PageHeader from '@/components/PageHeader.vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { useSession } from '@/composables/useSession'
import { formatDateRange, formatInteger, formatMoney, formatMonth, formatWholeMoney } from '@/lib/format'
import { tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/**
 * The home page: one request (GET /dashboard) with the indicators of the month to date against
 * the same days of the previous month, invoiced and collected over six months, the receivables
 * by age, the electronic documents of the last 30 days and what needs attention, the numbering
 * and certificate notices, the top customers and the latest activity. A company without invoices
 * gets an invitation to issue its first one instead.
 */
// The charts draw their axes once: they are drawn again in a new language.
const { t, locale } = useI18n()
const session = useSessionStore()
const { currentCompany, can } = useSession()

const {
  data: dashboard,
  isPending,
  error,
  refetch,
} = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, 'dashboard')),
  queryFn: () => workspaceApi.dashboard(),
  enabled: computed(() => session.currentCompanyId !== null),
  // Documents still moving through the chain change these figures: refresh every minute.
  refetchInterval: 60_000,
})

const description = computed(() =>
  [currentCompany.value?.name, dashboard.value ? formatMonth(dashboard.value.today.slice(0, 7), 'long') : null].filter(Boolean).join(' · '),
)

const comparedTo = computed(() =>
  dashboard.value ? t('dashboard.kpis.vs', { period: formatDateRange(dashboard.value.period.previous_from, dashboard.value.period.previous_to) }) : '',
)

const kpis = computed(() => {
  const value = dashboard.value

  if (!value) {
    return []
  }

  return [
    {
      key: 'invoiced',
      label: t('dashboard.kpis.invoiced'),
      hint: t('dashboard.kpis.invoicedHint'),
      kpi: value.kpis.invoiced,
      count: t('dashboard.kpis.invoices', { n: formatInteger(value.kpis.invoiced.count) }, value.kpis.invoiced.count),
      higherIsBetter: true,
    },
    {
      key: 'collected',
      label: t('dashboard.kpis.collected'),
      kpi: value.kpis.collected,
      count: t('dashboard.kpis.payments', { n: formatInteger(value.kpis.collected.count) }, value.kpis.collected.count),
      higherIsBetter: true,
    },
    {
      key: 'receivable',
      label: t('dashboard.kpis.receivable'),
      kpi: value.kpis.receivable,
      count: t('dashboard.kpis.openInvoices', { n: formatInteger(value.kpis.receivable.count) }, value.kpis.receivable.count),
      higherIsBetter: false,
    },
    {
      key: 'overdue',
      label: t('dashboard.kpis.overdue'),
      kpi: value.kpis.overdue,
      count: t('dashboard.kpis.overdueInvoices', { n: formatInteger(value.kpis.overdue.count) }, value.kpis.overdue.count),
      higherIsBetter: false,
    },
  ]
})
</script>

<template>
  <div class="grid gap-6">
    <PageHeader :title="t('dashboard.title')" :description="description">
      <Button v-if="can('record-payments')" variant="outline" as-child>
        <RouterLink :to="{ name: 'payment-new' }">
          <WalletIcon />
          {{ t('palette.actions.recordPayment') }}
        </RouterLink>
      </Button>
      <Button v-if="can('manage-catalog')" variant="outline" as-child>
        <RouterLink :to="{ name: 'contact-new' }">
          <UserPlusIcon />
          {{ t('palette.actions.newContact') }}
        </RouterLink>
      </Button>
      <Button v-if="can('manage-documents')" as-child>
        <RouterLink :to="{ name: 'invoice-new' }">
          <FilePlusIcon />
          {{ t('palette.actions.newInvoice') }}
        </RouterLink>
      </Button>
    </PageHeader>

    <!-- Loading: the shape of the page. -->
    <div v-if="isPending" class="grid gap-6" aria-busy="true">
      <span class="sr-only">{{ t('common.loading') }}</span>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Skeleton v-for="index in 4" :key="index" class="h-36 rounded-xl" />
      </div>
      <div class="grid gap-4 lg:grid-cols-3">
        <Skeleton class="h-80 rounded-xl lg:col-span-2" />
        <Skeleton class="h-80 rounded-xl" />
      </div>
    </div>

    <Card v-else-if="error || !dashboard">
      <CardContent class="grid justify-items-start gap-3">
        <FormError :error="error" />
        <Button variant="outline" size="sm" @click="refetch()">
          <RotateCwIcon />
          {{ t('table.retry') }}
        </Button>
      </CardContent>
    </Card>

    <!-- A company without invoices yet. -->
    <template v-else-if="!dashboard.has_invoices">
      <Card>
        <CardContent class="flex flex-col items-center gap-4 py-14 text-center">
          <span class="flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
            <InboxIcon class="size-6" aria-hidden="true" />
          </span>
          <div class="grid max-w-md gap-1.5">
            <p class="text-lg font-semibold">{{ t('dashboard.empty.title') }}</p>
            <p class="text-muted-foreground">{{ t('dashboard.empty.description') }}</p>
          </div>
          <Button v-if="can('manage-documents')" as-child>
            <RouterLink :to="{ name: 'invoice-new' }">
              <FilePlusIcon />
              {{ t('dashboard.empty.action') }}
            </RouterLink>
          </Button>
        </CardContent>
      </Card>
      <NoticesCard :notices="dashboard.notices" class="sm:grid-cols-2" />
    </template>

    <template v-else>
      <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" :aria-label="t('dashboard.kpis.label')">
        <KpiCard
          v-for="item in kpis"
          :key="item.key"
          :label="item.label"
          :hint="item.hint"
          :kpi="item.kpi"
          :count-label="item.count"
          :compared-to="comparedTo"
          :higher-is-better="item.higherIsBetter"
        />
      </section>

      <div class="grid gap-4 lg:grid-cols-3">
        <Card class="lg:col-span-2">
          <CardHeader>
            <CardTitle>{{ t('dashboard.monthly.title') }}</CardTitle>
            <CardDescription>{{ t('dashboard.monthly.description') }}</CardDescription>
          </CardHeader>
          <CardContent>
            <MonthlyChart :key="locale" :months="dashboard.monthly" />
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>{{ t('dashboard.aging.title') }}</CardTitle>
            <CardDescription :title="formatMoney(dashboard.aging.total)">{{
              t('dashboard.aging.description', { total: formatWholeMoney(dashboard.aging.total) })
            }}</CardDescription>
          </CardHeader>
          <CardContent class="grid gap-3">
            <AgingChart :key="locale" :aging="dashboard.aging" />
            <RouterLink :to="{ name: 'receivables' }" class="w-fit text-xs font-medium text-primary hover:underline">{{
              t('dashboard.aging.open')
            }}</RouterLink>
          </CardContent>
        </Card>
      </div>

      <div class="grid gap-4 lg:grid-cols-3">
        <Card class="lg:col-span-2">
          <CardHeader>
            <CardTitle>{{ t('dashboard.electronic.title') }}</CardTitle>
            <CardDescription>{{
              t('dashboard.electronic.description', {
                period: t('dashboard.electronic.period', { n: formatInteger(dashboard.electronic.days) }, dashboard.electronic.days),
                total: formatInteger(dashboard.electronic.total),
              })
            }}</CardDescription>
          </CardHeader>
          <CardContent class="grid gap-5">
            <ElectronicSummary :electronic="dashboard.electronic" />
            <div class="grid gap-2">
              <h3 class="text-sm font-medium">{{ t('dashboard.attention.title') }}</h3>
              <AttentionList :items="dashboard.attention.items" :total="dashboard.attention.total" />
            </div>
          </CardContent>
        </Card>

        <NoticesCard :notices="dashboard.notices" />
      </div>

      <div class="grid gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle>{{ t('dashboard.topCustomers.title') }}</CardTitle>
            <CardDescription>{{ t('dashboard.topCustomers.description') }}</CardDescription>
          </CardHeader>
          <CardContent>
            <TopCustomers :customers="dashboard.top_customers" />
          </CardContent>
        </Card>

        <Card class="lg:col-span-2">
          <CardHeader>
            <CardTitle>{{ t('dashboard.activity.title') }}</CardTitle>
            <CardDescription>{{ t('dashboard.activity.description') }}</CardDescription>
          </CardHeader>
          <CardContent>
            <ActivityFeed :entries="dashboard.activity" />
          </CardContent>
        </Card>
      </div>

      <p class="text-xs text-muted-foreground">{{ t('dashboard.footnote') }}</p>
    </template>
  </div>
</template>
