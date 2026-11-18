<script setup lang="ts">
import type { CompanyFormModel } from './companyForm'
import { useI18n } from 'vue-i18n'
import FormField from '@/components/FormField.vue'
import { Input } from '@/components/ui/input'

const props = withDefaults(defineProps<{
  /** "identity": name, legal name and NIT. "contact": email, phone, address and city. */
  section: 'identity' | 'contact'
  errors?: Record<string, string[]>
  /** Prefix of the error keys when the company is nested in the payload, such as "company.". */
  errorPrefix?: string
  /** Keeps input ids unique when several forms are on the page. */
  idPrefix?: string
  disabled?: boolean
}>(), {
  errors: () => ({}),
  errorPrefix: '',
  idPrefix: 'company',
  disabled: false,
})

const model = defineModel<CompanyFormModel>({ required: true })

const { t } = useI18n()

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

  <div v-else class="grid gap-4">
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

    <FormField :id="id('address')" :label="t('fields.address')" :error="error('address')" optional>
      <Input
        :id="id('address')"
        v-model="model.address"
        autocomplete="street-address"
        :disabled="disabled"
        :aria-invalid="!!error('address')"
      />
    </FormField>

    <div class="grid gap-4 sm:grid-cols-2">
      <FormField :id="id('city')" :label="t('fields.city')" :error="error('city')" optional>
        <Input
          :id="id('city')"
          v-model="model.city"
          autocomplete="address-level2"
          :disabled="disabled"
          :aria-invalid="!!error('city')"
        />
      </FormField>
    </div>
  </div>
</template>
