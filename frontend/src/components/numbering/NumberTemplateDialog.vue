<script setup lang="ts">
import type { NumberingType, NumberTemplate, NumberTemplateInput } from '@/api/types'
import type { Option } from '@/lib/options'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { numberTemplatesApi } from '@/api/resources'
import { orNull } from '@/components/company/companyForm'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import SelectInput from '@/components/SelectInput.vue'
import SwitchField from '@/components/SwitchField.vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { useFormMutation } from '@/composables/useFormMutation'
import { invalidateTenant } from '@/lib/query'

/**
 * Creates or edits a numbering range. The technical key is write-only: it is typed here but
 * never shown; leaving it empty keeps the stored one.
 */
const props = defineProps<{
  template: NumberTemplate | null
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()

interface TemplateForm {
  document_type: string | null
  prefix: string
  from_number: string
  to_number: string
  next_number: string
  resolution_number: string
  resolution_date: string
  valid_from: string
  valid_to: string
  technical_key: string
  is_default: boolean
  is_active: boolean
}

function formFrom(template: NumberTemplate | null): TemplateForm {
  return {
    document_type: template?.document_type ?? 'invoice',
    prefix: template?.prefix ?? '',
    from_number: template ? String(template.from_number) : '1',
    to_number: template ? String(template.to_number) : '',
    next_number: template ? String(template.next_number) : '',
    resolution_number: template?.resolution_number ?? '',
    resolution_date: template?.resolution_date ?? '',
    valid_from: template?.valid_from ?? '',
    valid_to: template?.valid_to ?? '',
    technical_key: '',
    is_default: template?.is_default ?? true,
    is_active: template?.is_active ?? true,
  }
}

const form = ref<TemplateForm>(formFrom(props.template))

const documentTypes = computed<Option[]>(() =>
  (['invoice', 'credit_note', 'debit_note', 'payment'] as const).map((type) => ({
    value: type,
    label: t(`numbering.documentTypes.${type}`),
  })),
)

const isInvoice = computed(() => form.value.document_type === 'invoice')

function toNumber(value: string): number {
  return Number(value.replace(/\D/g, ''))
}

function toInput(): NumberTemplateInput {
  const input: NumberTemplateInput = {
    document_type: (form.value.document_type ?? 'invoice') as NumberingType,
    prefix: orNull(form.value.prefix)?.toUpperCase() ?? null,
    from_number: toNumber(form.value.from_number),
    to_number: toNumber(form.value.to_number),
    // A new range starts at its first number unless another one is typed.
    next_number: form.value.next_number.trim() === '' ? toNumber(form.value.from_number) : toNumber(form.value.next_number),
    resolution_number: orNull(form.value.resolution_number),
    resolution_date: orNull(form.value.resolution_date),
    valid_from: orNull(form.value.valid_from),
    valid_to: orNull(form.value.valid_to),
    is_default: form.value.is_default,
    is_active: form.value.is_active,
  }

  // Only send a key when one was typed, so the stored one is kept otherwise.
  if (form.value.technical_key.trim() !== '') {
    input.technical_key = form.value.technical_key.trim()
  }

  return input
}

const { submit, isPending, error, errors, reset } = useFormMutation({
  mutationFn: () => (props.template ? numberTemplatesApi.update(props.template.id, toInput()) : numberTemplatesApi.create(toInput())),
  success: () => t(props.template ? 'numbering.saved' : 'numbering.created'),
  onSuccess: async () => {
    await invalidateTenant('number-templates')
    open.value = false
  },
})

watch(open, (isOpen) => {
  if (isOpen) {
    form.value = formFrom(props.template)
    reset()
  }
})

const keyHint = computed(() =>
  props.template?.technical_key_set
    ? t('numbering.form.technicalKeyStored', { last4: props.template.technical_key_last4 ?? '' })
    : t('numbering.form.technicalKeyHint'),
)
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
      <DialogHeader>
        <DialogTitle>{{ template ? t('numbering.editTitle') : t('numbering.newTitle') }}</DialogTitle>
        <DialogDescription>{{ t('numbering.formDescription') }}</DialogDescription>
      </DialogHeader>

      <form id="numbering-form" class="grid gap-4" novalidate @submit.prevent="submit">
        <FormError :error="error" />

        <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
          <FormField id="numbering-type" :label="t('numbering.form.documentType')" :error="errors.document_type?.[0]">
            <SelectInput id="numbering-type" v-model="form.document_type" :options="documentTypes" :invalid="!!errors.document_type" />
          </FormField>
          <FormField id="numbering-prefix" :label="t('numbering.form.prefix')" :error="errors.prefix?.[0]" optional>
            <Input id="numbering-prefix" v-model="form.prefix" maxlength="4" autocomplete="off" class="uppercase" :aria-invalid="!!errors.prefix" />
          </FormField>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
          <FormField id="numbering-from" :label="t('numbering.form.from')" :error="errors.from_number?.[0]">
            <Input
              id="numbering-from"
              v-model="form.from_number"
              inputmode="numeric"
              autocomplete="off"
              class="tabular-nums"
              :aria-invalid="!!errors.from_number"
            />
          </FormField>
          <FormField id="numbering-to" :label="t('numbering.form.to')" :error="errors.to_number?.[0]">
            <Input id="numbering-to" v-model="form.to_number" inputmode="numeric" autocomplete="off" class="tabular-nums" :aria-invalid="!!errors.to_number" />
          </FormField>
          <FormField id="numbering-next" :label="t('numbering.form.next')" :hint="t('numbering.form.nextHint')" :error="errors.next_number?.[0]">
            <Input
              id="numbering-next"
              v-model="form.next_number"
              inputmode="numeric"
              autocomplete="off"
              class="tabular-nums"
              :aria-invalid="!!errors.next_number"
            />
          </FormField>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormField
            id="numbering-resolution"
            :label="t('numbering.form.resolution')"
            :hint="isInvoice ? t('numbering.form.requiredForInvoices') : undefined"
            :error="errors.resolution_number?.[0]"
            :optional="!isInvoice"
          >
            <Input id="numbering-resolution" v-model="form.resolution_number" autocomplete="off" :aria-invalid="!!errors.resolution_number" />
          </FormField>
          <FormField
            id="numbering-resolution-date"
            :label="t('numbering.form.resolutionDate')"
            :hint="isInvoice ? t('numbering.form.resolutionDateHint') : undefined"
            :error="errors.resolution_date?.[0]"
            :optional="!isInvoice"
          >
            <Input id="numbering-resolution-date" v-model="form.resolution_date" type="date" :aria-invalid="!!errors.resolution_date" />
          </FormField>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormField id="numbering-valid-from" :label="t('numbering.form.validFrom')" :error="errors.valid_from?.[0]" :optional="!isInvoice">
            <Input id="numbering-valid-from" v-model="form.valid_from" type="date" :aria-invalid="!!errors.valid_from" />
          </FormField>
          <FormField id="numbering-valid-to" :label="t('numbering.form.validTo')" :error="errors.valid_to?.[0]" :optional="!isInvoice">
            <Input id="numbering-valid-to" v-model="form.valid_to" type="date" :aria-invalid="!!errors.valid_to" />
          </FormField>
        </div>

        <FormField id="numbering-key" :label="t('numbering.form.technicalKey')" :hint="keyHint" :error="errors.technical_key?.[0]" :optional="!isInvoice">
          <Input
            id="numbering-key"
            v-model="form.technical_key"
            type="password"
            autocomplete="new-password"
            :placeholder="template?.technical_key_set ? t('numbering.form.technicalKeyPlaceholder') : ''"
            :aria-invalid="!!errors.technical_key"
          />
        </FormField>

        <div class="grid gap-4 sm:grid-cols-2">
          <SwitchField
            id="numbering-default"
            v-model="form.is_default"
            :label="t('numbering.form.isDefault')"
            :description="t('numbering.form.isDefaultHint')"
          />
          <SwitchField id="numbering-active" v-model="form.is_active" :label="t('fields.active')" :description="t('numbering.form.activeHint')" />
        </div>
      </form>

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">{{ t('common.cancel') }}</Button>
        <Button type="submit" form="numbering-form" :disabled="isPending">
          {{ isPending ? t('common.saving') : template ? t('common.save') : t('numbering.form.create') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
