<script setup lang="ts">
import type { Component } from 'vue'
import {
  CreditCardIcon,
  FileTextIcon,
  LayoutDashboardIcon,
  PackageIcon,
  SettingsIcon,
  UsersIcon,
} from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import BrandMark from '@/components/BrandMark.vue'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

interface NavItem {
  /** i18n key of the label. */
  label: string
  icon: Component
  /** Sections without a route yet are shown disabled; they arrive in later stages. */
  to?: string
  /** Active only on this exact path, for the dashboard at "/". */
  exact?: boolean
}

const { t } = useI18n()

const items: NavItem[] = [
  { label: 'nav.dashboard', icon: LayoutDashboardIcon, to: '/', exact: true },
  { label: 'nav.invoices', icon: FileTextIcon, to: '/invoices' },
  { label: 'nav.contacts', icon: UsersIcon, to: '/contacts' },
  { label: 'nav.items', icon: PackageIcon, to: '/items' },
  { label: 'nav.payments', icon: CreditCardIcon },
  { label: 'nav.settings', icon: SettingsIcon, to: '/settings' },
]

const itemClass = 'flex h-9 items-center gap-3 rounded-md px-3 text-sm font-medium transition-colors'

const route = useRoute()

// A section stays highlighted on its inner pages, such as a contact form under /contacts.
function isCurrent(item: NavItem): boolean {
  if (!item.to) {
    return false
  }

  return item.exact ? route.path === item.to : route.path === item.to || route.path.startsWith(`${item.to}/`)
}
</script>

<template>
  <aside class="sticky top-0 flex h-screen w-60 shrink-0 flex-col border-r border-sidebar-border bg-sidebar">
    <div class="flex h-14 shrink-0 items-center px-5">
      <BrandMark />
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4" :aria-label="t('nav.main')">
      <ul class="grid gap-0.5">
        <li v-for="item in items" :key="item.label">
          <RouterLink v-if="item.to" v-slot="{ href, navigate }" :to="item.to" custom>
            <a
              :href="href"
              :aria-current="isCurrent(item) ? 'page' : undefined"
              :class="cn(
                itemClass,
                isCurrent(item)
                  ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                  : 'text-sidebar-foreground hover:bg-muted hover:text-foreground',
              )"
              @click="navigate"
            >
              <component :is="item.icon" class="size-4" />
              {{ t(item.label) }}
            </a>
          </RouterLink>

          <span v-else :class="cn(itemClass, 'cursor-not-allowed text-muted-foreground/70')" aria-disabled="true">
            <component :is="item.icon" class="size-4" />
            {{ t(item.label) }}
            <Badge variant="outline" class="ml-auto font-normal text-muted-foreground">{{ t('nav.soon') }}</Badge>
          </span>
        </li>
      </ul>
    </nav>
  </aside>
</template>
