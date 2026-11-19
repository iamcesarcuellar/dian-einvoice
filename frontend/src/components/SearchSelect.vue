<script setup lang="ts" generic="T extends object">
import { CheckIcon, ChevronsUpDownIcon } from '@lucide/vue'
import { keepPreviousData, useQuery } from '@tanstack/vue-query'
import { refDebounced } from '@vueuse/core'
import { ComboboxInput } from 'reka-ui'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  Combobox,
  ComboboxAnchor,
  ComboboxEmpty,
  ComboboxItem,
  ComboboxItemIndicator,
  ComboboxList,
  ComboboxTrigger,
  ComboboxViewport,
} from '@/components/ui/combobox'
import { cn } from '@/lib/utils'

/**
 * A select that searches on the API while the user types. Options are cached by the query key
 * plus the search term. Used for customers, items and municipalities.
 */
const props = withDefaults(
  defineProps<{
    id?: string
    /** Cache key of the options; the search term is appended to it. */
    queryKey: unknown[]
    /** Loads the options matching a search term (an empty term loads the first ones). */
    search: (term: string) => Promise<T[]>
    /** Property that identifies an option, such as "id" or "code". */
    by: keyof T & string
    /** Text of an option, also shown in the input once it is chosen. */
    label: (option: T) => string
    placeholder?: string
    emptyText?: string
    disabled?: boolean
    invalid?: boolean
    /** Extra classes for the list, such as a minimum width. */
    listClass?: string
  }>(),
  {
    disabled: false,
    invalid: false,
  },
)

const model = defineModel<T | null>({ required: true })

const { t } = useI18n()

const term = ref('')
const debouncedTerm = refDebounced(term, 250)

const { data, isFetching } = useQuery({
  queryKey: computed(() => [...props.queryKey, debouncedTerm.value]),
  queryFn: () => props.search(debouncedTerm.value),
  enabled: computed(() => !props.disabled),
  staleTime: 30_000,
  placeholderData: keepPreviousData,
})

const options = computed(() => data.value ?? [])

function choose(value: unknown): void {
  model.value = (value ?? null) as T | null
}

function display(value: unknown): string {
  return value ? props.label(value as T) : ''
}

function keyOf(option: T): string {
  return String(option[props.by])
}
</script>

<template>
  <Combobox :model-value="model ?? undefined" :by="by" ignore-filter open-on-click :disabled="disabled" @update:model-value="choose">
    <ComboboxAnchor
      :class="
        cn(
          'flex h-9 w-full min-w-0 items-center rounded-md border border-input bg-transparent shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50',
          invalid && 'border-destructive ring-3 ring-destructive/20',
          disabled && 'cursor-not-allowed opacity-50',
        )
      "
    >
      <ComboboxInput
        :id="id"
        v-model="term"
        :display-value="display"
        :placeholder="placeholder"
        autocomplete="off"
        class="h-full min-w-0 flex-1 bg-transparent px-2.5 text-sm outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed"
      />
      <ComboboxTrigger class="flex h-full items-center px-2 text-muted-foreground">
        <ChevronsUpDownIcon />
      </ComboboxTrigger>
    </ComboboxAnchor>

    <ComboboxList :class="listClass">
      <ComboboxViewport>
        <ComboboxEmpty>{{ isFetching ? t('common.loading') : (emptyText ?? t('common.noResults')) }}</ComboboxEmpty>
        <ComboboxItem v-for="option in options" :key="keyOf(option)" :value="option">
          <slot name="option" :option="option">
            {{ label(option) }}
          </slot>
          <ComboboxItemIndicator>
            <CheckIcon />
          </ComboboxItemIndicator>
        </ComboboxItem>
      </ComboboxViewport>
    </ComboboxList>
  </Combobox>
</template>
