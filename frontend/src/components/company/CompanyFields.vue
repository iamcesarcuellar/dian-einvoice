<script setup lang="ts">
import type { CompanyFormModel } from './companyForm'
import type { Municipality } from '@/api/types'
import { useI18n } from 'vue-i18n'
import FiscalResponsibilitiesInput from '@/components/FiscalResponsibilitiesInput.vue'
import FormField from '@/components/FormField.vue'
import MunicipalityPicker from '@/components/MunicipalityPicker.vue'
import SelectInput from '@/components/SelectInput.vue'
import { Input } from '@/components/ui/input'
import { useReference } from '@/composables/useReference'

const props = withDefaults(defineProps<{
  /** "identity": name and NIT. "tax": tax profile. "contact": address and contact details. */
  section: 'identity' | 'tax' | 'contact'
  errors?: Record<string, string[]>
  /** Prefix of the error keys when the company is nested in the payload, such as "company.". */
  errorPrefix?: string
  /** Keeps input ids unique when several forms are on the page. */
  idPrefix?: string
  /** The stored municipality, shown by the contact section. */
  municipality?: Municipality | null
  disabled?: boolean
}>(), {
  errors: () => ({}),
  errorPrefix: '',
  idPrefix: 'company',
  municipality: null,
  disabled: false,
})

const model = defineModel<CompanyFormModel>({ required: true })

const { t } = useI18n()
const { organizationTypes, taxSchemes, fiscalResponsibilities } = useReference()

function error(field: keyof CompanyFormModel): string | undefined {
  return props.errors[`${props.errorPrefix}${field}`]?.[0]
}

function id(field: string): string {
  return `${props.idPrefix}-${field}`
}

// A check digit typed for the previous NIT no longer applies.
function clearCheckDigit(): void {
  model.value.check_digit = ''
}
</script>

<template>
  <div v-if="section === 'identity'" class="grid gap-4">
    <FormField :id="id('name')" :label="t('fields.companyName')" :error="error('name')">
      <Input
        :id="id('name')"
        v-model="model.name"
        autocomplete="organization"
        required
        :disabled="disabled"
        :aria-invalid="!!error('name')"
      />
    </FormField>

    <FormField :id="id('legal-name')" :label="t('fields.legalName')" :hint="t('fields.legalNameHint')" :error="error('legal_name')" optional>
      <Input
        :id="id('legal-name')"
        v-model="model.legal_name"
        :disabled="disabled"
        :aria-invalid="!!error('legal_name')"
      />
    </FormField>

    <div class="grid gap-1.5">
      <div class="grid grid-cols-[1fr_6rem] gap-3">
        <FormField :id="id('nit')" :label="t('fields.nit')" :error="error('identification_number')">
          <Input
            :id="id('nit')"
            v-model="model.identification_number"
            inputmode="numeric"
            autocomplete="off"
            required
            class="tabular-nums"
            :disabled="disabled"
            :aria-invalid="!!error('identification_number')"
            @update:model-value="clearCheckDigit"
          />
        </FormField>
        <FormField :id="id('check-digit')" :label="t('fields.checkDigit')" :error="error('check_digit')">
          <Input
            :id="id('check-digit')"
            v-model="model.check_digit"
            inputmode="numeric"
            maxlength="1"
            autocomplete="off"
            class="tabular-nums"
            :disabled="disabled"
            :aria-invalid="!!error('check_digit')"
          />
        </FormField>
      </div>
      <p class="text-xs text-muted-foreground">{{ t('fields.nitHint') }}</p>
    </div>
  </div>

  <div v-else-if="section === 'tax'" class="grid gap-4">
    <div class="grid gap-4 sm:grid-cols-2">
      <FormField :id="id('organization-type')" :label="t('fields.organizationType')" :error="error('organization_type')">
        <SelectInput
          :id="id('organization-type')"
          v-model="model.organization_type"
          :options="organizationTypes"
          :disabled="disabled"
          :invalid="!!error('organization_type')"
        />
      </FormField>
      <FormField :id="id('tax-scheme')" :label="t('fields.taxScheme')" :error="error('tax_scheme')">
        <SelectInput
          :id="id('tax-scheme')"
          v-model="model.tax_scheme"
          :options="taxSchemes"
          :disabled="disabled"
          :invalid="!!error('tax_scheme')"
        />
      </FormField>
    </div>

    <FormField :id="id('fiscal')" :label="t('fields.fiscalResponsibilities')" :error="error('fiscal_responsibilities')">
      <FiscalResponsibilitiesInput
        v-model="model.fiscal_responsibilities"
        :options="fiscalResponsibilities"
        :id-prefix="id('fiscal')"
        :disabled="disabled"
      />
    </FormField>

    <div class="grid gap-4 sm:grid-cols-2">
      <FormField :id="id('registration')" :label="t('fields.registrationNumber')" :hint="t('fields.registrationNumberHint')" :error="error('registration_number')" optional>
        <Input
          :id="id('registration')"
          v-model="model.registration_number"
          autocomplete="off"
          :disabled="disabled"
          :aria-invalid="!!error('registration_number')"
        />
      </FormField>
    </div>
  </div>

  <div v-else class="grid gap-4">
    <FormField :id="id('address')" :label="t('fields.address')" :error="error('address')" optional>
      <Input
        :id="id('address')"
        v-model="model.address"
        autocomplete="street-address"
        :disabled="disabled"
        :aria-invalid="!!error('address')"
      />
    </FormField>

    <MunicipalityPicker
      v-model="model.municipality_code"
      :initial="municipality"
      :error="error('municipality_code')"
      :id-prefix="id('location')"
      :disabled="disabled"
    />

    <div class="grid gap-4 sm:grid-cols-2">
      <FormField :id="id('postal-code')" :label="t('fields.postalCode')" :error="error('postal_code')" optional>
        <Input
          :id="id('postal-code')"
          v-model="model.postal_code"
          inputmode="numeric"
          maxlength="6"
          autocomplete="postal-code"
          :disabled="disabled"
          :aria-invalid="!!error('postal_code')"
        />
      </FormField>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
      <FormField :id="id('email')" :label="t('fields.companyEmail')" :error="error('email')" optional>
        <Input
          :id="id('email')"
          v-model="model.email"
          type="email"
          autocomplete="email"
          :disabled="disabled"
          :aria-invalid="!!error('email')"
        />
      </FormField>
      <FormField :id="id('phone')" :label="t('fields.phone')" :error="error('phone')" optional>
        <Input
          :id="id('phone')"
          v-model="model.phone"
          type="tel"
          autocomplete="tel"
          :disabled="disabled"
          :aria-invalid="!!error('phone')"
        />
      </FormField>
    </div>
  </div>
</template>
