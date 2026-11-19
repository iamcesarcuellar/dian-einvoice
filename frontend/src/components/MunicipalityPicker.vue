<script setup lang="ts">
import type { Municipality } from '@/api/types'
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { referenceApi } from '@/api/reference'
import FormField from '@/components/FormField.vue'
import SearchSelect from '@/components/SearchSelect.vue'
import SelectInput from '@/components/SelectInput.vue'
import { useReference } from '@/composables/useReference'

/**
 * Department and municipality (DANE DIVIPOLA). The department filters the municipalities, which
 * are searched by name on the API as the user types.
 */
const props = withDefaults(
  defineProps<{
    /** The municipality already stored, to show it with its department. */
    initial?: Municipality | null
    error?: string
    idPrefix?: string
    disabled?: boolean
  }>(),
  {
    initial: null,
    idPrefix: 'location',
    disabled: false,
  },
)

/** DANE code of the chosen municipality. */
const model = defineModel<string | null>({ required: true })

const { t } = useI18n()
const { departments } = useReference()

const department = ref<string | null>(props.initial?.department_code ?? null)
const selected = ref<Municipality | null>(props.initial)

watch(
  () => props.initial,
  (municipality) => {
    selected.value = municipality
    department.value = municipality?.department_code ?? department.value
  },
)

// A municipality belongs to its department: choosing another department clears it.
function chooseDepartment(code: string | null): void {
  if (code === department.value) {
    return
  }

  department.value = code
  selected.value = null
  model.value = null
}

function chooseMunicipality(municipality: Municipality | null): void {
  selected.value = municipality
  model.value = municipality?.code ?? null
}

async function searchMunicipalities(term: string): Promise<Municipality[]> {
  const page = await referenceApi.municipalities({
    department: department.value ?? undefined,
    search: term || undefined,
    per_page: 100,
  })

  return page.data
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
      <SearchSelect
        :id="`${idPrefix}-municipality`"
        :model-value="selected"
        :query-key="['municipalities', department]"
        :search="searchMunicipalities"
        by="code"
        :label="(municipality) => municipality.name"
        :placeholder="department ? t('fields.searchMunicipality') : t('fields.chooseDepartmentFirst')"
        :empty-text="t('fields.noMunicipalities')"
        :disabled="disabled || department === null"
        :invalid="!!error"
        @update:model-value="chooseMunicipality"
      />
    </FormField>
  </div>
</template>
