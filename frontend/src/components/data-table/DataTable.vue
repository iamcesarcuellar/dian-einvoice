<script setup lang="ts" generic="T extends { id: string }">
import type { DataTableColumn } from './types'
import type { PageMeta } from '@/api/types'
import { ChevronLeftIcon, ChevronRightIcon, SearchIcon } from '@lucide/vue'
import { useDebounceFn } from '@vueuse/core'
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Table,
  TableBody,
  TableCell,
  TableEmpty,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { cn } from '@/lib/utils'

/**
 * The data table of every list: search, pagination, empty state and row actions.
 * Cells render the row value by default; a #cell-<key> slot replaces it, and #actions adds a
 * column of actions per row.
 */
defineProps<{
  columns: DataTableColumn[]
  rows: T[]
  meta?: PageMeta
  loading?: boolean
  searchPlaceholder?: string
  emptyTitle: string
  emptyDescription?: string
  /** Adds a column for the #actions slot. */
  actions?: boolean
}>()

const search = defineModel<string>('search', { required: true })
const page = defineModel<number>('page', { required: true })

const { t } = useI18n()

// The search box updates the list 300 ms after the last keystroke.
const term = ref(search.value)
const commitSearch = useDebounceFn((value: string) => {
  search.value = value
}, 300)

watch(term, (value) => {
  commitSearch(value)
})

watch(search, (value) => {
  if (value !== term.value) {
    term.value = value
  }
})

function cellClass(column: DataTableColumn): string {
  return cn(column.align === 'right' && 'text-right', column.class)
}

function cellValue(row: T, key: string): unknown {
  return (row as Record<string, unknown>)[key]
}
</script>

<template>
  <div class="grid gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="relative w-full max-w-xs">
        <SearchIcon class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          v-model="term"
          type="search"
          class="pl-8"
          :placeholder="searchPlaceholder ?? t('table.search')"
          :aria-label="searchPlaceholder ?? t('table.search')"
        />
      </div>
      <slot name="toolbar" />
    </div>

    <div class="overflow-hidden rounded-lg border bg-card shadow-xs">
      <Table>
        <TableHeader>
          <TableRow class="hover:bg-transparent">
            <TableHead v-for="column in columns" :key="column.key" :class="cellClass(column)">
              {{ column.label }}
            </TableHead>
            <TableHead v-if="actions" class="w-12">
              <span class="sr-only">{{ t('table.actions') }}</span>
            </TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          <template v-if="rows.length > 0">
            <TableRow v-for="row in rows" :key="row.id">
              <TableCell v-for="column in columns" :key="column.key" :class="cellClass(column)">
                <slot :name="`cell-${column.key}`" :row="row">
                  {{ cellValue(row, column.key) }}
                </slot>
              </TableCell>
              <TableCell v-if="actions" class="text-right">
                <slot name="actions" :row="row" />
              </TableCell>
            </TableRow>
          </template>

          <TableEmpty v-else :colspan="columns.length + (actions ? 1 : 0)" class="whitespace-normal">
            <p v-if="loading" class="text-muted-foreground">{{ t('common.loading') }}</p>
            <div v-else class="grid max-w-sm justify-items-center gap-1 text-center">
              <p class="font-medium">{{ search ? t('table.noResults') : emptyTitle }}</p>
              <p v-if="!search && emptyDescription" class="text-muted-foreground">{{ emptyDescription }}</p>
              <div v-if="!search && $slots['empty-action']" class="mt-3">
                <slot name="empty-action" />
              </div>
            </div>
          </TableEmpty>
        </TableBody>
      </Table>
    </div>

    <div v-if="meta && meta.total > 0" class="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
      <p class="tabular-nums">{{ t('table.showing', { from: meta.from ?? 0, to: meta.to ?? 0, total: meta.total }) }}</p>
      <div class="flex items-center gap-2">
        <Button variant="outline" size="sm" :disabled="meta.current_page <= 1" @click="page = meta.current_page - 1">
          <ChevronLeftIcon />
          {{ t('table.previous') }}
        </Button>
        <Button variant="outline" size="sm" :disabled="meta.current_page >= meta.last_page" @click="page = meta.current_page + 1">
          {{ t('table.next') }}
          <ChevronRightIcon />
        </Button>
      </div>
    </div>
  </div>
</template>
