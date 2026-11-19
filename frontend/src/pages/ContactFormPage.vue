<script setup lang="ts">
import { InfoIcon } from '@lucide/vue'
import { useQuery } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { contactsApi } from '@/api/resources'
import { COLOMBIA, contactFormFrom, emptyContactForm, NIT, toContactInput } from '@/components/contact/contactForm'
import FiscalResponsibilitiesInput from '@/components/FiscalResponsibilitiesInput.vue'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import FormSection from '@/components/FormSection.vue'
import MunicipalityPicker from '@/components/MunicipalityPicker.vue'
import PageHeader from '@/components/PageHeader.vue'
import SelectInput from '@/components/SelectInput.vue'
import SwitchField from '@/components/SwitchField.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useFormMutation } from '@/composables/useFormMutation'
import { useReference } from '@/composables/useReference'
import { useSession } from '@/composables/useSession'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { invalidateTenant, tenantKey } from '@/lib/query'
import { cn, nitCheckDigit } from '@/lib/utils'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const { can } = useSession()
const { identificationTypes, organizationTypes, taxSchemes, fiscalResponsibilities } = useReference()

const contactId = computed(() => (typeof route.params.id === 'string' ? route.params.id : null))
const isNew = computed(() => contactId.value === null)

const { data: contact } = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, 'contacts', 'detail', contactId.value)),
  queryFn: () => contactsApi.get(contactId.value as string),
  enabled: computed(() => contactId.value !== null),
})

const form = ref(emptyContactForm())
const unsaved = useUnsavedChanges(form)

watch(
  contact,
  (value) => {
    if (value) {
      form.value = contactFormFrom(value)
      unsaved.reset()
    }
  },
  { immediate: true },
)

// Another company has other contacts: go back to the list.
watch(
  () => session.currentCompanyId,
  () => {
    void router.push({ name: 'contacts' })
  },
)

const readOnlyReason = computed(() => {
  if (contact.value?.is_system) {
    return t('contacts.form.systemNotice')
  }

  return can('manage-catalog') ? null : t('contacts.form.readOnly')
})

const readOnly = computed(() => readOnlyReason.value !== null)
const isNit = computed(() => form.value.identification_type === NIT)
const isPerson = computed(() => form.value.organization_type === '2')
const isColombia = computed(() => form.value.country_code.trim().toUpperCase() === COLOMBIA)

// Shown while typing a NIT; the API computes the stored one with the same algorithm.
const checkDigit = computed(() => (isNit.value ? nitCheckDigit(form.value.identification_number) : null))

const title = computed(() => (isNew.value ? t('contacts.newTitle') : (contact.value?.legal_name ?? t('common.loading'))))

const crumbs = computed(() => [{ label: t('nav.catalog') }, { label: t('nav.contacts'), to: { name: 'contacts' } }, { label: title.value }])

const { submit, isPending, error, errors } = useFormMutation({
  mutationFn: () => (contactId.value ? contactsApi.update(contactId.value, toContactInput(form.value)) : contactsApi.create(toContactInput(form.value))),
  success: (saved) => t(isNew.value ? 'contacts.created' : 'contacts.saved', { name: saved.legal_name }),
  onSuccess: async () => {
    unsaved.reset()
    await invalidateTenant('contacts')
    await router.push({ name: 'contacts' })
  },
})
</script>

<template>
  <div class="grid max-w-3xl gap-6">
    <PageHeader :title="title" :description="isNew ? t('contacts.form.newDescription') : undefined" :breadcrumbs="crumbs">
      <template #badge>
        <Badge v-if="contact?.is_system" variant="outline">{{ t('common.system') }}</Badge>
      </template>
    </PageHeader>

    <div v-if="!isNew && !contact" class="grid gap-4" aria-busy="true">
      <span class="sr-only">{{ t('common.loading') }}</span>
      <Skeleton class="h-64 rounded-xl" />
      <Skeleton class="h-48 rounded-xl" />
    </div>

    <form v-else class="grid gap-6" novalidate @submit.prevent="submit">
      <Alert v-if="readOnlyReason">
        <InfoIcon />
        <AlertDescription>{{ readOnlyReason }}</AlertDescription>
      </Alert>

      <FormError :error="error" />

      <FormSection :title="t('contacts.sections.identity')" :description="t('contacts.sections.identityDescription')">
        <div class="grid gap-4">
          <div class="grid gap-4 sm:grid-cols-2">
            <FormField id="contact-organization-type" :label="t('fields.organizationType')" :error="errors.organization_type?.[0]">
              <SelectInput
                id="contact-organization-type"
                v-model="form.organization_type"
                :options="organizationTypes"
                :disabled="readOnly"
                :invalid="!!errors.organization_type"
              />
            </FormField>
            <FormField id="contact-identification-type" :label="t('fields.identificationType')" :error="errors.identification_type?.[0]">
              <SelectInput
                id="contact-identification-type"
                v-model="form.identification_type"
                :options="identificationTypes"
                :disabled="readOnly"
                :invalid="!!errors.identification_type"
              />
            </FormField>
          </div>

          <div :class="cn('grid gap-3', isNit && 'grid-cols-[1fr_6rem]')">
            <FormField
              id="contact-identification-number"
              :label="isNit ? t('fields.nit') : t('fields.identificationNumber')"
              :hint="isNit ? t('contacts.form.nitHint') : undefined"
              :error="errors.identification_number?.[0]"
            >
              <Input
                id="contact-identification-number"
                v-model="form.identification_number"
                autocomplete="off"
                class="tabular-nums"
                :inputmode="isNit ? 'numeric' : 'text'"
                :disabled="readOnly"
                :aria-invalid="!!errors.identification_number"
              />
            </FormField>
            <FormField v-if="isNit" id="contact-check-digit" :label="t('fields.checkDigit')" :error="errors.check_digit?.[0]">
              <Input
                id="contact-check-digit"
                :model-value="checkDigit === null ? '' : String(checkDigit)"
                readonly
                tabindex="-1"
                class="bg-muted/50 text-center tabular-nums"
              />
            </FormField>
          </div>

          <FormField id="contact-legal-name" :label="isPerson ? t('fields.fullName') : t('fields.legalName')" :error="errors.legal_name?.[0]">
            <Input id="contact-legal-name" v-model="form.legal_name" autocomplete="off" :disabled="readOnly" :aria-invalid="!!errors.legal_name" />
          </FormField>

          <FormField id="contact-trade-name" :label="t('fields.tradeName')" :error="errors.trade_name?.[0]" optional>
            <Input id="contact-trade-name" v-model="form.trade_name" autocomplete="off" :disabled="readOnly" :aria-invalid="!!errors.trade_name" />
          </FormField>
        </div>
      </FormSection>

      <FormSection :title="t('contacts.sections.tax')" :description="t('contacts.sections.taxDescription')">
        <div class="grid gap-4">
          <div class="grid gap-4 sm:grid-cols-2">
            <FormField id="contact-tax-scheme" :label="t('fields.taxScheme')" :error="errors.tax_scheme?.[0]">
              <SelectInput id="contact-tax-scheme" v-model="form.tax_scheme" :options="taxSchemes" :disabled="readOnly" :invalid="!!errors.tax_scheme" />
            </FormField>
          </div>
          <FormField id="contact-fiscal" :label="t('fields.fiscalResponsibilities')" :error="errors.fiscal_responsibilities?.[0]">
            <FiscalResponsibilitiesInput
              v-model="form.fiscal_responsibilities"
              :options="fiscalResponsibilities"
              id-prefix="contact-fiscal"
              :disabled="readOnly"
            />
          </FormField>
        </div>
      </FormSection>

      <FormSection :title="t('contacts.sections.address')" :description="t('contacts.sections.addressDescription')">
        <div class="grid gap-4">
          <div class="grid gap-4 sm:grid-cols-2">
            <FormField id="contact-email" :label="t('fields.email')" :hint="t('contacts.form.emailHint')" :error="errors.email?.[0]">
              <Input id="contact-email" v-model="form.email" type="email" autocomplete="off" :disabled="readOnly" :aria-invalid="!!errors.email" />
            </FormField>
            <FormField id="contact-phone" :label="t('fields.phone')" :error="errors.phone?.[0]" optional>
              <Input id="contact-phone" v-model="form.phone" type="tel" autocomplete="off" :disabled="readOnly" :aria-invalid="!!errors.phone" />
            </FormField>
          </div>

          <div class="grid gap-4 sm:grid-cols-2">
            <FormField id="contact-country" :label="t('fields.country')" :hint="t('contacts.form.countryHint')" :error="errors.country_code?.[0]">
              <Input
                id="contact-country"
                v-model="form.country_code"
                maxlength="2"
                autocomplete="off"
                class="uppercase"
                :disabled="readOnly"
                :aria-invalid="!!errors.country_code"
              />
            </FormField>
          </div>

          <MunicipalityPicker
            v-if="isColombia"
            v-model="form.municipality_code"
            :initial="contact?.municipality ?? null"
            :error="errors.municipality_code?.[0]"
            id-prefix="contact"
            :disabled="readOnly"
          />

          <FormField id="contact-address" :label="t('fields.address')" :error="errors.address?.[0]" optional>
            <Input id="contact-address" v-model="form.address" autocomplete="off" :disabled="readOnly" :aria-invalid="!!errors.address" />
          </FormField>

          <div class="grid gap-4 sm:grid-cols-2">
            <FormField id="contact-postal-code" :label="t('fields.postalCode')" :error="errors.postal_code?.[0]" optional>
              <Input id="contact-postal-code" v-model="form.postal_code" autocomplete="off" :disabled="readOnly" :aria-invalid="!!errors.postal_code" />
            </FormField>
          </div>

          <SwitchField
            id="contact-active"
            v-model="form.is_active"
            :label="t('fields.active')"
            :description="t('contacts.form.activeHint')"
            :disabled="readOnly"
          />
        </div>

        <template v-if="!readOnly" #footer>
          <Button variant="outline" as-child>
            <RouterLink :to="{ name: 'contacts' }">{{ t('common.cancel') }}</RouterLink>
          </Button>
          <Button type="submit" :disabled="isPending">
            {{ isPending ? t('common.saving') : isNew ? t('contacts.form.create') : t('common.save') }}
          </Button>
        </template>
      </FormSection>
    </form>
  </div>
</template>
