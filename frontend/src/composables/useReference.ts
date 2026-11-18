import type { CodeOption } from '@/api/types'
import type { Option } from '@/lib/options'
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { referenceApi } from '@/api/reference'
import { queryKeys } from '@/lib/query'

/**
 * Reference lists as select options. DIAN codes are named through translation keys
 * (reference.<group>.<code>); departments keep their own names.
 */
export function useReference() {
  const { t } = useI18n()
  const { data } = useQuery({
    queryKey: queryKeys.reference,
    queryFn: referenceApi.get,
    staleTime: Number.POSITIVE_INFINITY,
  })

  function options(list: CodeOption[] | undefined, group: string, withCode = false): Option[] {
    return (list ?? []).map((entry) => {
      const label = t(`reference.${group}.${entry.code}`)

      return { value: entry.code, label: withCode ? `${entry.code} · ${label}` : label }
    })
  }

  /** Name of one code, such as label('units', 'KGM'). */
  function label(group: string, code: string | null | undefined): string {
    return code ? t(`reference.${group}.${code}`) : ''
  }

  return {
    reference: data,
    label,
    identificationTypes: computed(() => options(data.value?.identification_types, 'identificationTypes')),
    organizationTypes: computed(() => options(data.value?.organization_types, 'organizationTypes')),
    fiscalResponsibilities: computed(() => options(data.value?.fiscal_responsibilities, 'fiscalResponsibilities', true)),
    taxSchemes: computed(() => options(data.value?.tax_schemes, 'taxSchemes', true)),
    taxTypes: computed(() => options(data.value?.tax_types, 'taxTypes')),
    withholdingTypes: computed(() => options(data.value?.withholding_types, 'withholdingTypes')),
    units: computed(() => options(data.value?.units, 'units')),
    paymentForms: computed(() => options(data.value?.payment_forms, 'paymentForms')),
    paymentMeans: computed(() => options(data.value?.payment_means, 'paymentMeans')),
    // Concepts of the notes, with their code: "2 · Void of the electronic invoice".
    creditNoteConcepts: computed(() => options(data.value?.credit_note_concepts, 'creditNoteConcepts', true)),
    debitNoteConcepts: computed(() => options(data.value?.debit_note_concepts, 'debitNoteConcepts', true)),
    departments: computed<Option[]>(() => (data.value?.departments ?? []).map((department) => ({
      value: department.code,
      label: department.name,
    }))),
  }
}
