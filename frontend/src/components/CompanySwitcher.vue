<script setup lang="ts">
import { Building2Icon, CheckIcon, ChevronsUpDownIcon, PlusIcon } from '@lucide/vue'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NewCompanyDialog from '@/components/company/NewCompanyDialog.vue'
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
import { formatNit } from '@/lib/utils'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const session = useSessionStore()
const { companies, currentCompany } = useSession()

const newCompanyOpen = ref(false)
</script>

<template>
  <DropdownMenu :modal="false">
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" class="h-11 max-w-80 justify-start gap-3 px-2" :aria-label="t('companies.switcher.trigger')">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-md border bg-background text-muted-foreground">
          <Building2Icon class="size-4" />
        </span>
        <span v-if="currentCompany" class="grid min-w-0 text-left leading-tight">
          <span class="truncate font-medium">{{ currentCompany.name }}</span>
          <span class="truncate text-xs font-normal text-muted-foreground tabular-nums">
            {{ t('companies.nit', { nit: formatNit(currentCompany.identification_number, currentCompany.check_digit) }) }}
          </span>
        </span>
        <ChevronsUpDownIcon class="ml-1 text-muted-foreground" />
      </Button>
    </DropdownMenuTrigger>

    <DropdownMenuContent align="start" class="w-72">
      <DropdownMenuLabel>{{ t('companies.switcher.label') }}</DropdownMenuLabel>
      <DropdownMenuItem
        v-for="company in companies"
        :key="company.id"
        class="gap-3"
        @select="session.selectCompany(company.id)"
      >
        <span class="grid min-w-0 flex-1 leading-tight">
          <span class="truncate">{{ company.name }}</span>
          <span v-if="company.role" class="truncate text-xs text-muted-foreground">{{ t(`companies.roles.${company.role}`) }}</span>
        </span>
        <CheckIcon v-if="company.id === currentCompany?.id" class="text-primary" />
      </DropdownMenuItem>
      <DropdownMenuSeparator />
      <DropdownMenuItem @select="newCompanyOpen = true">
        <PlusIcon />
        {{ t('companies.new.action') }}
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <NewCompanyDialog v-model:open="newCompanyOpen" />
</template>
