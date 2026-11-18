<script setup lang="ts">
import type { RowAction } from './types'
import { EllipsisIcon } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'

defineProps<{
  actions: RowAction[]
}>()

const { t } = useI18n()
</script>

<template>
  <DropdownMenu v-if="actions.length > 0" :modal="false">
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" size="icon-sm" :aria-label="t('table.actions')">
        <EllipsisIcon />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-44">
      <DropdownMenuItem
        v-for="action in actions"
        :key="action.label"
        :variant="action.destructive ? 'destructive' : 'default'"
        :disabled="action.disabled"
        @select="action.onSelect()"
      >
        <component :is="action.icon" v-if="action.icon" />
        {{ action.label }}
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
