<script setup lang="ts">
import type { RateInput, Tax, Withholding } from '@/api/types'
import { InfoIcon } from '@lucide/vue'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { taxesApi, withholdingsApi } from '@/api/resources'
import DecimalInput from '@/components/DecimalInput.vue'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import SelectInput from '@/components/SelectInput.vue'
import SwitchField from '@/components/SwitchField.vue'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { useFormMutation } from '@/composables/useFormMutation'
import { useReference } from '@/composables/useReference'
import { invalidateTenant } from '@/lib/query'

/** Creates or edits a tax or a withholding. Company defaults can only be switched on or off. */
const props = defineProps<{
  kind: 'taxes' | 'withholdings'
  /** The rate to edit, or null to create one. */
  rate: Tax | Withholding | null
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
const { taxTypes, withholdingTypes, reference } = useReference()

const api = computed(() => (props.kind === 'taxes' ? taxesApi : withholdingsApi))
const typeOptions = computed(() => (props.kind === 'taxes' ? taxTypes.value : withholdingTypes.value))
const isSystem = computed(() => props.rate?.is_system ?? false)

const form = ref({ dian_code: null as string | null, name: '', rate: '', is_active: true })

// ReteIVA is calculated on the IVA; ReteFuente and ReteICA on the subtotal before taxes.
const baseHint = computed(() => {
  const base = reference.value?.withholding_types.find((type) => type.code === form.value.dian_code)?.base

  return props.kind === 'withholdings' && base ? t(`rates.base.${base}`) : undefined
})

function toInput(): RateInput {
  if (isSystem.value) {
    return { is_active: form.value.is_active }
  }

  return {
    dian_code: form.value.dian_code ?? '',
    name: form.value.name.trim(),
    rate: form.value.rate,
    is_active: form.value.is_active,
  }
}

const { submit, isPending, error, errors, reset } = useFormMutation({
  mutationFn: () => (props.rate ? api.value.update(props.rate.id, toInput()) : api.value.create(toInput())),
  onSuccess: async () => {
    // Items show the name of their tax.
    await invalidateTenant(props.kind, 'items')
    open.value = false
  },
})

watch(open, (isOpen) => {
  if (isOpen) {
    form.value = {
      dian_code: props.rate?.dian_code ?? (props.kind === 'taxes' ? '01' : '06'),
      name: props.rate?.name ?? '',
      rate: props.rate?.rate ?? '',
      is_active: props.rate?.is_active ?? true,
    }
    reset()
  }
})

const title = computed(() => {
  const group = props.kind === 'taxes' ? 'taxes' : 'withholdings'

  return props.rate ? t(`${group}.editTitle`) : t(`${group}.newTitle`)
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ title }}</DialogTitle>
        <DialogDescription>{{ kind === 'taxes' ? t('taxes.formDescription') : t('withholdings.formDescription') }}</DialogDescription>
      </DialogHeader>

      <form id="rate-form" class="grid gap-4" novalidate @submit.prevent="submit">
        <Alert v-if="isSystem">
          <InfoIcon />
          <AlertDescription>{{ t('rates.form.systemNotice') }}</AlertDescription>
        </Alert>
        <FormError :error="error" />

        <FormField id="rate-type" :label="t('rates.form.type')" :hint="baseHint" :error="errors.dian_code?.[0]">
          <SelectInput id="rate-type" v-model="form.dian_code" :options="typeOptions" :disabled="isSystem" :invalid="!!errors.dian_code" />
        </FormField>

        <div class="grid gap-4 sm:grid-cols-[1fr_9rem]">
          <FormField id="rate-name" :label="t('rates.form.name')" :error="errors.name?.[0]">
            <Input id="rate-name" v-model="form.name" autocomplete="off" :disabled="isSystem" :aria-invalid="!!errors.name" />
          </FormField>
          <FormField id="rate-rate" :label="t('rates.form.rate')" :error="errors.rate?.[0]">
            <DecimalInput id="rate-rate" v-model="form.rate" :scale="4" suffix="%" :disabled="isSystem" :invalid="!!errors.rate" />
          </FormField>
        </div>

        <SwitchField id="rate-active" v-model="form.is_active" :label="t('fields.active')" :description="t('rates.form.activeHint')" />
      </form>

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">{{ t('common.cancel') }}</Button>
        <Button type="submit" form="rate-form" :disabled="isPending">
          {{ isPending ? t('common.saving') : (rate ? t('common.save') : t('rates.form.create')) }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
