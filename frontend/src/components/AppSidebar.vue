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
import { watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import BrandMark from '@/components/BrandMark.vue'
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarRail,
  useSidebar,
} from '@/components/ui/sidebar'

interface NavItem {
  /** i18n key of the label. */
  label: string
  icon: Component
  to: string
  /** Active only on this exact path, for the dashboard at "/". */
  exact?: boolean
}

interface NavGroup {
  /** i18n key of the group heading; null for the ungrouped dashboard. */
  label: string | null
  items: NavItem[]
}

/**
 * The main navigation: the dashboard, then Sales (invoices and payments), Catalog (contacts and
 * items), and Settings at the foot. It folds to icons on wide screens and becomes a sheet behind
 * the menu button on narrow ones.
 */
const { t } = useI18n()
const route = useRoute()
const { isMobile, setOpenMobile } = useSidebar()

const groups: NavGroup[] = [
  { label: null, items: [{ label: 'nav.dashboard', icon: LayoutDashboardIcon, to: '/', exact: true }] },
  {
    label: 'nav.sales',
    items: [
      { label: 'nav.invoices', icon: FileTextIcon, to: '/invoices' },
      { label: 'nav.payments', icon: CreditCardIcon, to: '/payments' },
    ],
  },
  {
    label: 'nav.catalog',
    items: [
      { label: 'nav.contacts', icon: UsersIcon, to: '/contacts' },
      { label: 'nav.items', icon: PackageIcon, to: '/items' },
    ],
  },
]

const settings: NavItem = { label: 'nav.settings', icon: SettingsIcon, to: '/settings' }

// A section stays highlighted on its inner pages, such as a contact form under /contacts.
function isCurrent(item: NavItem): boolean {
  return item.exact ? route.path === item.to : route.path === item.to || route.path.startsWith(`${item.to}/`)
}

// On narrow screens the sheet closes once a page is chosen.
watch(() => route.fullPath, () => {
  if (isMobile.value) {
    setOpenMobile(false)
  }
})
</script>

<template>
  <Sidebar collapsible="icon">
    <SidebarHeader class="h-14 justify-center px-3">
      <RouterLink :to="{ name: 'dashboard' }" class="flex items-center rounded-md">
        <BrandMark />
      </RouterLink>
    </SidebarHeader>

    <SidebarContent>
      <nav :aria-label="t('nav.main')" class="contents">
        <SidebarGroup v-for="group in groups" :key="group.label ?? 'top'">
          <SidebarGroupLabel v-if="group.label">{{ t(group.label) }}</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>
              <SidebarMenuItem v-for="item in group.items" :key="item.to">
                <SidebarMenuButton as-child :is-active="isCurrent(item)" :tooltip="t(item.label)">
                  <RouterLink :to="item.to" :aria-current="isCurrent(item) ? 'page' : undefined">
                    <component :is="item.icon" aria-hidden="true" />
                    <span>{{ t(item.label) }}</span>
                  </RouterLink>
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </nav>
    </SidebarContent>

    <SidebarFooter>
      <SidebarMenu>
        <SidebarMenuItem>
          <SidebarMenuButton as-child :is-active="isCurrent(settings)" :tooltip="t(settings.label)">
            <RouterLink :to="settings.to" :aria-current="isCurrent(settings) ? 'page' : undefined">
              <component :is="settings.icon" aria-hidden="true" />
              <span>{{ t(settings.label) }}</span>
            </RouterLink>
          </SidebarMenuButton>
        </SidebarMenuItem>
      </SidebarMenu>
    </SidebarFooter>

    <SidebarRail />
  </Sidebar>
</template>
