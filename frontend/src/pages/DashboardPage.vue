<script setup lang="ts">
import { InboxIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useSession } from '@/composables/useSession'
import { formatNit } from '@/lib/utils'

const { t } = useI18n()
const { user, currentCompany } = useSession()

const greetingKey = computed(() => {
  const hour = new Date().getHours()

  if (hour < 12) {
    return 'dashboard.greeting.morning'
  }

  return hour < 18 ? 'dashboard.greeting.afternoon' : 'dashboard.greeting.evening'
})

const firstName = computed(() => user.value?.name.trim().split(/\s+/)[0] ?? '')
</script>

<template>
  <div class="grid gap-8">
    <header class="grid gap-1">
      <h1 class="text-2xl font-semibold tracking-tight">{{ t(greetingKey, { name: firstName }) }}</h1>
      <p v-if="currentCompany" class="text-muted-foreground">
        {{ t('dashboard.workingIn', { company: currentCompany.name }) }}
      </p>
    </header>

    <Card v-if="currentCompany">
      <CardHeader class="flex items-start justify-between gap-4">
        <div class="grid gap-1">
          <CardTitle>{{ t('dashboard.company.title') }}</CardTitle>
          <CardDescription>{{ currentCompany.legal_name }}</CardDescription>
        </div>
        <Button variant="outline" size="sm" as-child>
          <RouterLink :to="{ name: 'company-settings' }">{{ t('dashboard.company.edit') }}</RouterLink>
        </Button>
      </CardHeader>
      <CardContent>
        <dl class="grid gap-6 sm:grid-cols-3">
          <div class="grid gap-1">
            <dt class="text-xs text-muted-foreground">{{ t('dashboard.company.nit') }}</dt>
            <dd class="font-medium tabular-nums">
              {{ formatNit(currentCompany.identification_number, currentCompany.check_digit) }}
            </dd>
          </div>
          <div class="grid gap-1">
            <dt class="text-xs text-muted-foreground">{{ t('dashboard.company.city') }}</dt>
            <dd class="font-medium">{{ currentCompany.municipality?.name ?? t('dashboard.company.notSet') }}</dd>
          </div>
          <div class="grid gap-1">
            <dt class="text-xs text-muted-foreground">{{ t('dashboard.company.role') }}</dt>
            <dd>
              <Badge v-if="currentCompany.role" variant="secondary">{{ t(`companies.roles.${currentCompany.role}`) }}</Badge>
            </dd>
          </div>
        </dl>
      </CardContent>
    </Card>

    <Card>
      <CardContent class="flex flex-col items-center gap-3 py-12 text-center">
        <span class="flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground">
          <InboxIcon class="size-5" />
        </span>
        <div class="grid gap-1">
          <p class="font-medium">{{ t('dashboard.empty.title') }}</p>
          <p class="max-w-sm text-muted-foreground">{{ t('dashboard.empty.description') }}</p>
        </div>
      </CardContent>
    </Card>
  </div>
</template>
