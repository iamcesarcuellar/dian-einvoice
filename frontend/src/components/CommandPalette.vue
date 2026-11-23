<script setup lang="ts">
import type { Component } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
import type { SearchContact, SearchDocument } from '@/api/workspace'
import {
  ArrowRightIcon,
  Building2Icon,
  CreditCardIcon,
  FileMinusIcon,
  FilePlusIcon,
  FileTextIcon,
  LayoutDashboardIcon,
  MonitorIcon,
  MoonIcon,
  PackageIcon,
  SettingsIcon,
  SunIcon,
  UserPlusIcon,
  UserRoundIcon,
  UsersIcon,
  WalletIcon,
} from '@lucide/vue'
import { keepPreviousData, useQuery } from '@tanstack/vue-query'
import { refDebounced, useEventListener } from '@vueuse/core'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { workspaceApi } from '@/api/workspace'
import StatusBadge from '@/components/StatusBadge.vue'
import { Command, CommandGroup, CommandInput, CommandItem, CommandList, CommandSeparator, CommandShortcut } from '@/components/ui/command'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { useCommandPalette } from '@/composables/useCommandPalette'
import { useSession } from '@/composables/useSession'
import { useTheme } from '@/composables/useTheme'
import { DOCUMENT_ROUTES } from '@/lib/documents'
import { formatDate, formatInteger, formatMoney } from '@/lib/format'
import { notify } from '@/lib/notify'
import { tenantKey } from '@/lib/query'
import { electronicGroup } from '@/lib/status'
import { formatNit } from '@/lib/utils'
import { useSessionStore } from '@/stores/session'

interface PaletteAction {
  id: string
  label: string
  icon: Component
  run: () => void
  /** Marks the current choice, such as the theme in use. */
  current?: boolean
}

/**
 * The command palette, opened with Ctrl+K (Cmd+K) or the search button of the top bar: it
 * searches invoices, notes, payments, contacts and items of the current company as you type
 * (GET /search, 250 ms after the last key), and offers the quick actions, the pages, the other
 * companies and the theme.
 */
const MIN_CHARS = 2

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()
const { companies, currentCompany, can } = useSession()
const { choice } = useTheme()
const { open, toggle } = useCommandPalette()

const query = ref('')
const term = refDebounced(
  computed(() => query.value.trim()),
  250,
)
const searching = computed(() => term.value.length >= MIN_CHARS)

useEventListener('keydown', (event: KeyboardEvent) => {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    toggle()
  }
})

watch(open, (value) => {
  if (!value) {
    query.value = ''
  }
})

const { data: results, isFetching } = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, 'search', term.value)),
  queryFn: () => workspaceApi.search(term.value),
  enabled: computed(() => open.value && searching.value && session.currentCompanyId !== null),
  staleTime: 15_000,
  placeholderData: keepPreviousData,
})

/** The results shown are not yet those of the text typed: it waits for the pause in typing, or they are on their way. */
const busy = computed(() => isFetching.value || query.value.trim() !== term.value)

const groups = computed(() => (searching.value ? results.value?.groups : undefined))

function go(to: RouteLocationRaw): () => void {
  return () => {
    open.value = false
    void router.push(to)
  }
}

function switchCompany(id: string, name: string): void {
  open.value = false
  session.selectCompany(id)
  notify.success(t('palette.switched', { name }))
  void router.push({ name: 'dashboard' })
}

const quickActions = computed<PaletteAction[]>(() => [
  ...(can('manage-documents') ? [{ id: 'new-invoice', label: t('palette.actions.newInvoice'), icon: FilePlusIcon, run: go({ name: 'invoice-new' }) }] : []),
  ...(can('record-payments') ? [{ id: 'record-payment', label: t('palette.actions.recordPayment'), icon: WalletIcon, run: go({ name: 'payment-new' }) }] : []),
  ...(can('manage-catalog') ? [{ id: 'new-contact', label: t('palette.actions.newContact'), icon: UserPlusIcon, run: go({ name: 'contact-new' }) }] : []),
])

const pages = computed<PaletteAction[]>(() => [
  { id: 'go-dashboard', label: t('nav.dashboard'), icon: LayoutDashboardIcon, run: go({ name: 'dashboard' }) },
  { id: 'go-invoices', label: t('nav.invoices'), icon: FileTextIcon, run: go({ name: 'invoices' }) },
  { id: 'go-payments', label: t('nav.payments'), icon: CreditCardIcon, run: go({ name: 'payments' }) },
  { id: 'go-receivables', label: t('payments.tabs.receivables'), icon: WalletIcon, run: go({ name: 'receivables' }) },
  { id: 'go-contacts', label: t('nav.contacts'), icon: UsersIcon, run: go({ name: 'contacts' }) },
  { id: 'go-items', label: t('nav.items'), icon: PackageIcon, run: go({ name: 'items' }) },
  { id: 'go-settings', label: t('nav.settings'), icon: SettingsIcon, run: go({ name: 'company-settings' }) },
  ...(can('manage-team') ? [{ id: 'go-team', label: t('settings.tabs.team'), icon: UsersIcon, run: go({ name: 'team' }) }] : []),
  { id: 'go-profile', label: t('user.profile'), icon: UserRoundIcon, run: go({ name: 'profile' }) },
])

const companyActions = computed<PaletteAction[]>(() =>
  companies.value
    .filter((company) => company.id !== currentCompany.value?.id)
    .map((company) => ({
      id: `company-${company.id}`,
      label: t('palette.switchTo', { name: company.name }),
      icon: Building2Icon,
      run: () => switchCompany(company.id, company.name),
    })),
)

const themeActions = computed<PaletteAction[]>(() =>
  (
    [
      ['light', SunIcon],
      ['dark', MoonIcon],
      ['auto', MonitorIcon],
    ] as const
  ).map(([value, icon]) => ({
    id: `theme-${value}`,
    label: t('palette.theme', { theme: t(`user.themes.${value}`) }),
    icon,
    current: choice.value === value,
    run: () => {
      choice.value = value
      open.value = false
    },
  })),
)

// The actions are filtered here, by their label, without accents or case; search results come
// filtered by the server.
function normalize(text: string): string {
  return text
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
}

function matching(actions: PaletteAction[]): PaletteAction[] {
  const typed = normalize(query.value.trim())

  return typed === '' ? actions : actions.filter((action) => normalize(action.label).includes(typed))
}

const actionSections = computed(() =>
  [
    { key: 'actions', heading: t('palette.groups.actions'), actions: matching(quickActions.value) },
    { key: 'pages', heading: t('palette.groups.pages'), actions: matching(pages.value) },
    { key: 'companies', heading: t('palette.groups.companies'), actions: matching(companyActions.value) },
    { key: 'theme', heading: t('palette.groups.theme'), actions: matching(themeActions.value) },
  ].filter((section) => section.actions.length > 0),
)

const resultCount = computed(() => {
  const found = groups.value

  return found ? Object.values(found).reduce((sum, group) => sum + group.items.length, 0) : 0
})

const nothingFound = computed(() => searching.value && !isFetching.value && resultCount.value === 0 && actionSections.value.length === 0)

function documentRoute(document: SearchDocument): RouteLocationRaw {
  return { name: DOCUMENT_ROUTES[document.type].detail, params: { id: document.id } }
}

function documentDetails(document: SearchDocument): string {
  return [
    document.related_number ? t('palette.corrects', { number: document.related_number }) : null,
    document.customer_name,
    formatDate(document.issue_date) || null,
    formatMoney(document.payable_amount),
  ]
    .filter(Boolean)
    .join(' · ')
}

function contactIdentification(contact: SearchContact): string {
  return contact.check_digit === null ? contact.identification_number : formatNit(contact.identification_number, contact.check_digit)
}

function seeAll(name: string): () => void {
  return go({ name, query: { search: term.value } })
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="top-[15%] translate-y-0 overflow-hidden p-0 sm:max-w-xl" :show-close-button="false">
      <DialogHeader class="sr-only">
        <DialogTitle>{{ t('palette.title') }}</DialogTitle>
        <DialogDescription>{{ t('palette.description') }}</DialogDescription>
      </DialogHeader>

      <Command v-model:search="query" :should-filter="false" class="rounded-none!">
        <CommandInput :placeholder="t('palette.placeholder')" :aria-label="t('palette.placeholder')" />
        <CommandList class="max-h-[min(28rem,60vh)]" :aria-busy="busy ? 'true' : undefined">
          <p v-if="query.trim().length > 0 && query.trim().length < MIN_CHARS" class="px-3 py-2 text-xs text-muted-foreground">
            {{ t('palette.minChars', { count: MIN_CHARS }, MIN_CHARS) }}
          </p>
          <p v-else-if="searching && isFetching && resultCount === 0" class="px-3 py-2 text-xs text-muted-foreground" role="status">
            {{ t('palette.searching') }}
          </p>

          <p v-if="nothingFound" class="py-6 text-center text-sm text-muted-foreground" role="status">{{ t('palette.empty', { query: term }) }}</p>

          <template v-if="groups">
            <CommandGroup v-if="groups.invoices.items.length" :heading="t('palette.groups.invoices')">
              <CommandItem
                v-for="document in groups.invoices.items"
                :key="document.id"
                :value="`invoice:${document.id}`"
                @select="go(documentRoute(document))()"
              >
                <FileTextIcon class="text-muted-foreground" aria-hidden="true" />
                <span class="grid min-w-0 flex-1">
                  <span class="truncate font-medium tabular-nums">{{ document.full_number ?? t('invoices.draft') }}</span>
                  <span class="truncate text-xs text-muted-foreground tabular-nums">{{ documentDetails(document) }}</span>
                </span>
                <StatusBadge v-if="document.electronic_status" domain="electronic" :status="electronicGroup(document.electronic_status)" compact />
                <StatusBadge v-else domain="document" :status="document.status" />
              </CommandItem>
              <CommandItem v-if="groups.invoices.total > groups.invoices.items.length" value="see-all-invoices" @select="seeAll('invoices')()">
                <ArrowRightIcon class="text-muted-foreground" aria-hidden="true" />
                <span class="text-muted-foreground">{{ t('palette.seeAll', { count: formatInteger(groups.invoices.total) }, groups.invoices.total) }}</span>
              </CommandItem>
            </CommandGroup>

            <CommandGroup v-if="groups.notes.items.length" :heading="t('palette.groups.notes')">
              <CommandItem v-for="document in groups.notes.items" :key="document.id" :value="`note:${document.id}`" @select="go(documentRoute(document))()">
                <FileMinusIcon class="text-muted-foreground" aria-hidden="true" />
                <span class="grid min-w-0 flex-1">
                  <span class="truncate font-medium tabular-nums"
                    >{{ document.full_number ?? t('invoices.draft') }} · {{ t(`palette.noteTypes.${document.type}`) }}</span
                  >
                  <span class="truncate text-xs text-muted-foreground tabular-nums">{{ documentDetails(document) }}</span>
                </span>
                <StatusBadge v-if="document.electronic_status" domain="electronic" :status="electronicGroup(document.electronic_status)" compact />
                <StatusBadge v-else domain="document" :status="document.status" />
              </CommandItem>
            </CommandGroup>

            <CommandGroup v-if="groups.payments.items.length" :heading="t('palette.groups.payments')">
              <CommandItem
                v-for="payment in groups.payments.items"
                :key="payment.id"
                :value="`payment:${payment.id}`"
                @select="go({ name: 'payment', params: { id: payment.id } })()"
              >
                <WalletIcon class="text-muted-foreground" aria-hidden="true" />
                <span class="grid min-w-0 flex-1">
                  <span class="truncate font-medium tabular-nums">{{ payment.full_number }}</span>
                  <span class="truncate text-xs text-muted-foreground tabular-nums"
                    >{{ payment.customer_name }} · {{ formatDate(payment.payment_date) }} · {{ formatMoney(payment.amount) }}</span
                  >
                </span>
                <StatusBadge v-if="payment.status === 'voided'" domain="payment" :status="payment.status" />
              </CommandItem>
              <CommandItem v-if="groups.payments.total > groups.payments.items.length" value="see-all-payments" @select="seeAll('payments')()">
                <ArrowRightIcon class="text-muted-foreground" aria-hidden="true" />
                <span class="text-muted-foreground">{{ t('palette.seeAll', { count: formatInteger(groups.payments.total) }, groups.payments.total) }}</span>
              </CommandItem>
            </CommandGroup>

            <CommandGroup v-if="groups.contacts.items.length" :heading="t('palette.groups.contacts')">
              <CommandItem
                v-for="contact in groups.contacts.items"
                :key="contact.id"
                :value="`contact:${contact.id}`"
                @select="go({ name: 'contact-edit', params: { id: contact.id } })()"
              >
                <UsersIcon class="text-muted-foreground" aria-hidden="true" />
                <span class="grid min-w-0 flex-1">
                  <span class="truncate font-medium">{{ contact.legal_name }}</span>
                  <span class="truncate text-xs text-muted-foreground tabular-nums">{{
                    [contactIdentification(contact), contact.email].filter(Boolean).join(' · ')
                  }}</span>
                </span>
                <StatusBadge v-if="!contact.is_active" domain="record" status="inactive" />
              </CommandItem>
              <CommandItem v-if="groups.contacts.total > groups.contacts.items.length" value="see-all-contacts" @select="seeAll('contacts')()">
                <ArrowRightIcon class="text-muted-foreground" aria-hidden="true" />
                <span class="text-muted-foreground">{{ t('palette.seeAll', { count: groups.contacts.total }) }}</span>
              </CommandItem>
            </CommandGroup>

            <CommandGroup v-if="groups.items.items.length" :heading="t('palette.groups.items')">
              <CommandItem
                v-for="item in groups.items.items"
                :key="item.id"
                :value="`item:${item.id}`"
                @select="go({ name: 'items', query: { search: item.code } })()"
              >
                <PackageIcon class="text-muted-foreground" aria-hidden="true" />
                <span class="grid min-w-0 flex-1">
                  <span class="truncate font-medium">{{ item.name }}</span>
                  <span class="truncate text-xs text-muted-foreground tabular-nums">{{ item.code }} · {{ formatMoney(item.unit_price) }}</span>
                </span>
                <StatusBadge v-if="!item.is_active" domain="record" status="inactive" />
              </CommandItem>
              <CommandItem v-if="groups.items.total > groups.items.items.length" value="see-all-items" @select="seeAll('items')()">
                <ArrowRightIcon class="text-muted-foreground" aria-hidden="true" />
                <span class="text-muted-foreground">{{ t('palette.seeAll', { count: groups.items.total }) }}</span>
              </CommandItem>
            </CommandGroup>

            <CommandSeparator v-if="resultCount > 0 && actionSections.length > 0" />
          </template>

          <CommandGroup v-for="section in actionSections" :key="section.key" :heading="section.heading">
            <CommandItem v-for="action in section.actions" :key="action.id" :value="action.id" @select="action.run()">
              <component :is="action.icon" class="text-muted-foreground" aria-hidden="true" />
              <span class="flex-1 truncate">{{ action.label }}</span>
              <CommandShortcut v-if="action.current">{{ t('palette.current') }}</CommandShortcut>
            </CommandItem>
          </CommandGroup>
        </CommandList>
      </Command>
    </DialogContent>
  </Dialog>
</template>
