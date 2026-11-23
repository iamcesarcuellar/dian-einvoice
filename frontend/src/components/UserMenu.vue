<script setup lang="ts">
import type { ThemeChoice } from '@/composables/useTheme'
import { InfoIcon, LanguagesIcon, LogOutIcon, MonitorIcon, MoonIcon, SunIcon, UserRoundIcon } from '@lucide/vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { authApi } from '@/api/auth'
import AboutDialog from '@/components/AboutDialog.vue'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useSession } from '@/composables/useSession'
import { useTheme } from '@/composables/useTheme'
import { LANGUAGES, setLanguage } from '@/i18n'
import { queryKeys } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/** The account menu: profile, theme and language (both saved in this browser), About and sign out. */
const { t, locale } = useI18n()
const router = useRouter()
const queryClient = useQueryClient()
const session = useSessionStore()
const { user } = useSession()
const { choice } = useTheme()

const themes: Array<{ value: ThemeChoice; icon: typeof SunIcon }> = [
  { value: 'light', icon: SunIcon },
  { value: 'dark', icon: MoonIcon },
  { value: 'auto', icon: MonitorIcon },
]

const aboutOpen = ref(false)

const initials = computed(() =>
  (user.value?.name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join(''),
)

function setTheme(value: unknown): void {
  if (value === 'light' || value === 'dark' || value === 'auto') {
    choice.value = value
  }
}

function chooseLanguage(value: unknown): void {
  if (value === 'en' || value === 'es') {
    setLanguage(value)
  }
}

const { mutate: logout, isPending } = useMutation({
  mutationFn: () => authApi.logout(),
  // Leave the app even if the request fails: the session is gone or unusable either way.
  onSettled: async () => {
    queryClient.clear()
    queryClient.setQueryData(queryKeys.me, null)
    session.selectCompany(null)
    await router.replace({ name: 'login' })
  },
})
</script>

<template>
  <DropdownMenu :modal="false">
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" size="icon" class="rounded-full" :aria-label="t('user.menu')">
        <Avatar>
          <AvatarFallback class="bg-muted text-xs font-medium text-foreground">{{ initials }}</AvatarFallback>
        </Avatar>
      </Button>
    </DropdownMenuTrigger>

    <DropdownMenuContent align="end" class="w-60">
      <DropdownMenuLabel class="grid gap-0.5 py-2">
        <span class="truncate text-sm font-medium text-foreground">{{ user?.name }}</span>
        <span class="truncate text-xs font-normal text-muted-foreground">{{ user?.email }}</span>
      </DropdownMenuLabel>
      <DropdownMenuSeparator />
      <DropdownMenuItem @select="router.push({ name: 'profile' })">
        <UserRoundIcon />
        {{ t('user.profile') }}
      </DropdownMenuItem>
      <DropdownMenuSeparator />
      <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">{{ t('user.theme') }}</DropdownMenuLabel>
      <DropdownMenuRadioGroup :model-value="choice" @update:model-value="setTheme">
        <DropdownMenuRadioItem v-for="theme in themes" :key="theme.value" :value="theme.value">
          <component :is="theme.icon" class="text-muted-foreground" aria-hidden="true" />
          {{ t(`user.themes.${theme.value}`) }}
        </DropdownMenuRadioItem>
      </DropdownMenuRadioGroup>
      <DropdownMenuSeparator />
      <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">{{ t('user.language') }}</DropdownMenuLabel>
      <DropdownMenuRadioGroup :model-value="locale" @update:model-value="chooseLanguage">
        <DropdownMenuRadioItem v-for="language in LANGUAGES" :key="language" :value="language" :lang="language">
          <LanguagesIcon class="text-muted-foreground" aria-hidden="true" />
          {{ t(`languages.${language}`) }}
        </DropdownMenuRadioItem>
      </DropdownMenuRadioGroup>
      <DropdownMenuSeparator />
      <DropdownMenuItem @select="aboutOpen = true">
        <InfoIcon />
        {{ t('user.about') }}
      </DropdownMenuItem>
      <DropdownMenuItem :disabled="isPending" @select="logout()">
        <LogOutIcon />
        {{ t('user.logout') }}
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <AboutDialog v-model:open="aboutOpen" />
</template>
