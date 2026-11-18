<script setup lang="ts">
import { LogOutIcon } from '@lucide/vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { authApi } from '@/api/auth'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useSession } from '@/composables/useSession'
import { queryKeys } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const queryClient = useQueryClient()
const session = useSessionStore()
const { user } = useSession()

const initials = computed(() =>
  (user.value?.name ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join(''),
)

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
      <DropdownMenuItem :disabled="isPending" @select="logout()">
        <LogOutIcon />
        {{ t('user.logout') }}
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
