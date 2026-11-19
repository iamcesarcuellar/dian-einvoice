<script setup lang="ts" generic="T extends { id: string }">
import type { DataTableColumn } from './types'
import type { PageMeta } from '@/api/types'
import { ChevronLeftIcon, ChevronRightIcon, RotateCwIcon, SearchIcon } from '@lucide/vue'
import { useDebounceFn } from '@vueuse/core'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import FormError from '@/components/FormError.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Table,
  TableBody,
  TableCell,
  TableEmpty,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { formatInteger } from '@/lib/format'
import { cn } from '@/lib/utils'

/**
 * The table of every list of the app, with one density: optional search and pagination, skeleton
 * rows while it loads, an empty state with an action (#empty-action), an error state with a retry,
 * and horizontal scrolling inside its card when the columns do not fit. Cells render the row value
 * by default; a #cell-<key> slot replaces it, #actions adds a column of actions per row, #toolbar
 * sits next to the search and #footer adds rows at the foot (totals).
 */
const props = withDefaults(defineProps<{
  columns: DataTableColumn[]
  rows: T[]
  meta?: PageMeta
  loading?: boolean
  /** The error of the query; with no rows to show, the table offers a retry. */
  error?: unknown
  searchPlaceholder?: string
  emptyTitle: string
  emptyDescription?: string
  /** Adds a column for the #actions slot. */
  actions?: boolean
  /** Skeleton rows while the first page loads. */
  skeletonRows?: number
}>(), {
  skeletonRows: 5,
})

const emit = defineEmits<{
  retry: []
}>()

/** Bound only by lists that search on the server; without it there is no search box. */
const search = defineModel<string>('search')
const page = defineModel<number>('page')

const { t } = useI18n()

// The search box updates the list 300 ms after the last keystroke.
const term = ref(search.value ?? '')
const commitSearch = useDebounceFn((value: string) => {
  search.value = value
}, 300)

watch(term, (value) => {
  commitSearch(value)
})

watch(search, (value) => {
  if (value !== undefined && value !== term.value) {
    term.value = value
  }
})

const columnCount = computed(() => props.columns.length + (props.actions ? 1 : 0))
const searching = computed(() => Boolean(search.value))
const showSkeleton = computed(() => props.loading && props.rows.length === 0)
const showError = computed(() => !props.loading && props.rows.length === 0 && Boolean(props.error))
const hasToolbar = computed(() => search.value !== undefined)

function cellClass(column: DataTableColumn): string {
  return cn(column.align === 'right' && 'text-right', column.class)
}

function cellValue(row: T, key: string): unknown {
  return (row as Record<string, unknown>)[key]
}

// Skeleton bars of varied widths, so the loading state reads as text rather than a grid.
const SKELETON_WIDTHS = ['w-24', 'w-32', 'w-20', 'w-28', 'w-16']
</script>

<template>
  <div class="grid gap-4">
    <div v-if="hasToolbar || $slots.toolbar" class="flex flex-wrap items-center justify-between gap-3">
      <div v-if="hasToolbar" class="relative w-full sm:max-w-xs">
        <SearchIcon class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
        <Input
          v-model="term"
          type="search"
          class="pl-8"
          :placeholder="searchPlaceholder ?? t('table.search')"
          :aria-label="searchPlaceholder ?? t('table.search')"
        />
      </div>
      <div class="flex flex-wrap items-center gap-2" :class="hasToolbar ? '' : 'ml-auto'">
        <slot name="toolbar" />
      </div>
    </div>

    <div class="overflow-x-auto rounded-lg border bg-card shadow-xs">
      <Table>
        <TableHeader>
          <TableRow class="hover:bg-transparent">
            <TableHead v-for="column in columns" :key="column.key" :class="cn('whitespace-nowrap', cellClass(column))">
              {{ column.label }}
            </TableHead>
            <TableHead v-if="actions" class="w-12">
              <span class="sr-only">{{ t('table.actions') }}</span>
            </TableHead>
          </TableRow>
        </TableHeader>

        <TableBody :aria-busy="loading || undefined">
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

          <template v-else-if="showSkeleton">
            <TableRow v-for="index in skeletonRows" :key="`skeleton-${index}`" class="hover:bg-transparent">
              <TableCell v-for="(column, columnIndex) in columns" :key="column.key" :class="cellClass(column)">
                <Skeleton :class="cn('h-4', SKELETON_WIDTHS[(index + columnIndex) % SKELETON_WIDTHS.length], column.align === 'right' && 'ml-auto')" />
              </TableCell>
              <TableCell v-if="actions" />
            </TableRow>
            <TableRow class="sr-only">
              <TableCell :colspan="columnCount">{{ t('common.loading') }}</TableCell>
            </TableRow>
          </template>

          <TableEmpty v-else-if="showError" :colspan="columnCount" class="whitespace-normal">
            <div class="grid max-w-md justify-items-center gap-3 text-center">
              <FormError :error="error" class="text-left" />
              <Button variant="outline" size="sm" @click="emit('retry')">
                <RotateCwIcon />
                {{ t('table.retry') }}
              </Button>
            </div>
          </TableEmpty>

          <TableEmpty v-else :colspan="columnCount" class="whitespace-normal">
            <div class="grid max-w-sm justify-items-center gap-1 text-center">
              <p class="font-medium text-foreground">{{ searching ? t('table.noResults') : emptyTitle }}</p>
              <p v-if="!searching && emptyDescription" class="text-muted-foreground">{{ emptyDescription }}</p>
              <div v-if="!searching && $slots['empty-action']" class="mt-3">
                <slot name="empty-action" />
              </div>
            </div>
          </TableEmpty>
        </TableBody>

        <TableFooter v-if="$slots.footer && rows.length > 0">
          <slot name="footer" />
        </TableFooter>
      </Table>
    </div>

    <div v-if="meta && meta.total > 0 && page !== undefined" class="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground">
      <p class="tabular-nums">{{ t('table.showing', { from: formatInteger(meta.from ?? 0), to: formatInteger(meta.to ?? 0), total: formatInteger(meta.total) }) }}</p>
      <div v-if="meta.last_page > 1" class="flex items-center gap-2">
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
