<script setup lang="ts">
import type { Municipality } from '@/api/types'
import { CheckIcon, ChevronsUpDownIcon } from '@lucide/vue'
import { keepPreviousData, useQuery } from '@tanstack/vue-query'
import { refDebounced } from '@vueuse/core'
import { ComboboxInput } from 'reka-ui'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { referenceApi } from '@/api/reference'
import FormField from '@/components/FormField.vue'
import SelectInput from '@/components/SelectInput.vue'
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
import { useReference } from '@/composables/useReference'
import { cn } from '@/lib/utils'

/**
 * Department and municipality (DANE DIVIPOLA). The department filters the municipalities, which
 * are searched by name on the API as the user types.
 */
const props = withDefaults(defineProps<{
  /** The municipality already stored, to show it with its department. */
  initial?: Municipality | null
  error?: string
  idPrefix?: string
  disabled?: boolean
}>(), {
  initial: null,
  idPrefix: 'location',
  disabled: false,
})

/** DANE code of the chosen municipality. */
const model = defineModel<string | null>({ required: true })

const { t } = useI18n()
const { departments } = useReference()

const department = ref<string | null>(props.initial?.department_code ?? null)
const selected = ref<Municipality | null>(props.initial)
const searchTerm = ref('')
const debouncedSearch = refDebounced(searchTerm, 250)

watch(() => props.initial, (municipality) => {
  selected.value = municipality
  department.value = municipality?.department_code ?? department.value
})

const { data } = useQuery({
  queryKey: computed(() => ['municipalities', department.value, debouncedSearch.value]),
  queryFn: () => referenceApi.municipalities({
    department: department.value ?? undefined,
    search: debouncedSearch.value || undefined,
    per_page: 100,
  }),
  enabled: computed(() => department.value !== null),
  staleTime: Number.POSITIVE_INFINITY,
  placeholderData: keepPreviousData,
})

const municipalities = computed(() => data.value?.data ?? [])
const locked = computed(() => props.disabled || department.value === null)

// A municipality belongs to its department: choosing another department clears it.
function chooseDepartment(code: string | null): void {
  if (code === department.value) {
    return
  }

  department.value = code
  selected.value = null
  searchTerm.value = ''
  model.value = null
}

function chooseMunicipality(value: unknown): void {
  const municipality = (value ?? null) as Municipality | null

  selected.value = municipality
  model.value = municipality?.code ?? null
}

function displayName(value: unknown): string {
  return (value as Municipality | null | undefined)?.name ?? ''
}
</script>

<template>
  <div class="grid gap-4 sm:grid-cols-2">
    <FormField :id="`${idPrefix}-department`" :label="t('fields.department')">
      <SelectInput
        :id="`${idPrefix}-department`"
        :model-value="department"
        :options="departments"
        :placeholder="t('fields.chooseDepartment')"
        :disabled="disabled"
        @update:model-value="chooseDepartment"
      />
    </FormField>

    <FormField :id="`${idPrefix}-municipality`" :label="t('fields.municipality')" :error="error">
      <Combobox
        :model-value="selected ?? undefined"
        by="code"
        ignore-filter
        open-on-click
        :disabled="locked"
        @update:model-value="chooseMunicipality"
      >
        <ComboboxAnchor
          :class="cn(
            'flex h-9 w-full items-center rounded-md border border-input bg-transparent shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/50',
            error && 'border-destructive ring-3 ring-destructive/20',
            locked && 'cursor-not-allowed opacity-50',
          )"
        >
          <ComboboxInput
            :id="`${idPrefix}-municipality`"
            v-model="searchTerm"
            :display-value="displayName"
            :placeholder="department ? t('fields.searchMunicipality') : t('fields.chooseDepartmentFirst')"
            class="h-full min-w-0 flex-1 bg-transparent px-2.5 text-sm outline-none placeholder:text-muted-foreground disabled:cursor-not-allowed"
          />
          <ComboboxTrigger class="flex h-full items-center px-2 text-muted-foreground">
            <ChevronsUpDownIcon />
          </ComboboxTrigger>
        </ComboboxAnchor>

        <ComboboxList>
          <ComboboxViewport>
            <ComboboxEmpty>{{ t('fields.noMunicipalities') }}</ComboboxEmpty>
            <ComboboxItem v-for="municipality in municipalities" :key="municipality.code" :value="municipality">
              {{ municipality.name }}
              <ComboboxItemIndicator>
                <CheckIcon />
              </ComboboxItemIndicator>
            </ComboboxItem>
          </ComboboxViewport>
        </ComboboxList>
      </Combobox>
    </FormField>
  </div>
</template>
