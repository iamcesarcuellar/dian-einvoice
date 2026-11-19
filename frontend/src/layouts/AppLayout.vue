<script setup lang="ts">
import { SearchIcon } from '@lucide/vue'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AboutDialog from '@/components/AboutDialog.vue'
import AppSidebar from '@/components/AppSidebar.vue'
import CommandPalette from '@/components/CommandPalette.vue'
import CompanySwitcher from '@/components/CompanySwitcher.vue'
import TestEnvironmentBadge from '@/components/TestEnvironmentBadge.vue'
import UserMenu from '@/components/UserMenu.vue'
import { Button } from '@/components/ui/button'
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar'
import { useCommandPalette } from '@/composables/useCommandPalette'

/**
 * The frame of every signed-in page: the sidebar, the top bar (menu button, company, search,
 * test environment label and account menu), the page, and the foot with what this product is.
 */
const { t } = useI18n()
const { show } = useCommandPalette()

const aboutOpen = ref(false)
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform)
</script>

<template>
  <SidebarProvider>
    <AppSidebar />

    <SidebarInset class="min-w-0 bg-muted/40">
      <header class="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-2 border-b bg-background px-3 sm:px-4 lg:px-6">
        <SidebarTrigger class="-ml-1" />
        <CompanySwitcher />

        <div class="ml-auto flex items-center gap-2">
          <Button
            variant="outline"
            class="h-9 w-9 justify-start gap-2 px-0 text-muted-foreground sm:w-56 sm:px-3"
            :aria-label="t('palette.open')"
            :aria-keyshortcuts="isMac ? 'Meta+K' : 'Control+K'"
            @click="show"
          >
            <SearchIcon class="mx-auto sm:mx-0" aria-hidden="true" />
            <span class="hidden flex-1 text-left font-normal sm:inline">{{ t('palette.button') }}</span>
            <kbd class="pointer-events-none hidden rounded border bg-muted px-1.5 font-mono text-[10px] font-medium sm:inline">{{
              isMac ? '⌘K' : 'Ctrl K'
            }}</kbd>
          </Button>
          <TestEnvironmentBadge />
          <UserMenu />
        </div>
      </header>

      <div class="flex-1 px-4 py-6 sm:px-6 lg:px-10 lg:py-8">
        <div class="mx-auto w-full max-w-6xl">
          <RouterView />
        </div>
      </div>

      <footer class="border-t px-4 py-4 text-xs text-muted-foreground sm:px-6 lg:px-10">
        <div class="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-2">
          <p>{{ t('about.disclaimer') }}</p>
          <button type="button" class="rounded-sm font-medium hover:text-foreground" @click="aboutOpen = true">{{ t('user.about') }}</button>
        </div>
      </footer>
    </SidebarInset>

    <CommandPalette />
    <AboutDialog v-model:open="aboutOpen" />
  </SidebarProvider>
</template>
