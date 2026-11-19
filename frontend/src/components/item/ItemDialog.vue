<script setup lang="ts">
import type { Item, ItemInput, ItemType } from '@/api/types'
import type { Option } from '@/lib/options'
import { useQuery } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { itemsApi, taxesApi } from '@/api/resources'
import { orNull } from '@/components/company/companyForm'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import MoneyInput from '@/components/MoneyInput.vue'
import SelectInput from '@/components/SelectInput.vue'
import SwitchField from '@/components/SwitchField.vue'
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
import { Textarea } from '@/components/ui/textarea'
import { useFormMutation } from '@/composables/useFormMutation'
import { useReference } from '@/composables/useReference'
import { invalidateTenant, tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/** Value of the "Excluded (no tax)" option; the API receives null. */
const EXCLUDED = 'excluded'

const props = defineProps<{
  /** The item to edit, or null to create one. */
  item: Item | null
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
const session = useSessionStore()
const { units } = useReference()

interface ItemForm {
  code: string
  name: string
  description: string
  type: string | null
  unit_code: string | null
  unit_price: string
  tax_id: string | null
  standard_scheme: string
  standard_code: string
  is_active: boolean
}

function formFrom(item: Item | null): ItemForm {
  return {
    code: item?.code ?? '',
    name: item?.name ?? '',
    description: item?.description ?? '',
    type: item?.type ?? 'product',
    unit_code: item?.unit_code ?? '94',
    unit_price: item?.unit_price ?? '',
    tax_id: item ? (item.tax_id ?? EXCLUDED) : null,
    standard_scheme: item?.standard_scheme ?? '',
    standard_code: item?.standard_code ?? '',
    is_active: item?.is_active ?? true,
  }
}

function toInput(form: ItemForm): ItemInput {
  return {
    code: form.code.trim(),
    name: form.name.trim(),
    description: orNull(form.description),
    type: (form.type ?? 'product') as ItemType,
    unit_code: form.unit_code ?? '',
    unit_price: form.unit_price,
    tax_id: form.tax_id === EXCLUDED ? null : form.tax_id,
    standard_scheme: orNull(form.standard_scheme),
    standard_code: orNull(form.standard_code),
    is_active: form.is_active,
  }
}

const form = ref<ItemForm>(formFrom(props.item))

// Taxes to choose from: the active ones, plus the one the item has even if it was deactivated.
const { data: taxPage } = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, 'taxes', 'options')),
  queryFn: () => taxesApi.list({ per_page: 100 }),
  enabled: open,
})

const taxOptions = computed<Option[]>(() => [
  { value: EXCLUDED, label: t('items.excluded') },
  ...(taxPage.value?.data ?? [])
    .filter((tax) => tax.is_active || tax.id === props.item?.tax_id)
    .map((tax) => ({ value: tax.id, label: tax.name })),
])

const typeOptions = computed<Option[]>(() => [
  { value: 'product', label: t('items.types.product') },
  { value: 'service', label: t('items.types.service') },
])

// A new item starts with the highest active IVA rate (taxes come sorted by rate), the most common case.
function applyDefaultTax(): void {
  if (props.item === null && form.value.tax_id === null && taxPage.value) {
    form.value.tax_id = taxPage.value.data.find((tax) => tax.is_active && tax.dian_code === '01')?.id ?? EXCLUDED
  }
}

watch(taxPage, applyDefaultTax)

const { submit, isPending, error, errors, reset } = useFormMutation({
  mutationFn: () => (props.item
    ? itemsApi.update(props.item.id, toInput(form.value))
    : itemsApi.create(toInput(form.value))),
  success: (item) => t(props.item ? 'items.saved' : 'items.created', { name: item.name }),
  onSuccess: async () => {
    await invalidateTenant('items')
    open.value = false
  },
})

watch(open, (isOpen) => {
  if (isOpen) {
    form.value = formFrom(props.item)
    reset()
    applyDefaultTax()
  }
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
      <DialogHeader>
        <DialogTitle>{{ item ? t('items.form.editTitle') : t('items.form.newTitle') }}</DialogTitle>
        <DialogDescription>{{ t('items.form.description') }}</DialogDescription>
      </DialogHeader>

      <form id="item-form" class="grid gap-4" novalidate @submit.prevent="submit">
        <FormError :error="error" />

        <div class="grid gap-4 sm:grid-cols-[10rem_1fr]">
          <FormField id="item-code" :label="t('items.form.code')" :error="errors.code?.[0]">
            <Input id="item-code" v-model="form.code" autocomplete="off" :aria-invalid="!!errors.code" />
          </FormField>
          <FormField id="item-name" :label="t('items.form.name')" :error="errors.name?.[0]">
            <Input id="item-name" v-model="form.name" autocomplete="off" :aria-invalid="!!errors.name" />
          </FormField>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormField id="item-type" :label="t('items.form.type')" :error="errors.type?.[0]">
            <SelectInput id="item-type" v-model="form.type" :options="typeOptions" :invalid="!!errors.type" />
          </FormField>
          <FormField id="item-unit" :label="t('items.form.unit')" :error="errors.unit_code?.[0]">
            <SelectInput id="item-unit" v-model="form.unit_code" :options="units" :invalid="!!errors.unit_code" />
          </FormField>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormField id="item-price" :label="t('items.form.unitPrice')" :hint="t('items.form.unitPriceHint')" :error="errors.unit_price?.[0]">
            <MoneyInput id="item-price" v-model="form.unit_price" :invalid="!!errors.unit_price" />
          </FormField>
          <FormField id="item-tax" :label="t('items.form.tax')" :error="errors.tax_id?.[0]">
            <SelectInput id="item-tax" v-model="form.tax_id" :options="taxOptions" :invalid="!!errors.tax_id" />
          </FormField>
        </div>

        <FormField id="item-description" :label="t('items.form.descriptionLabel')" :error="errors.description?.[0]" optional>
          <Textarea id="item-description" v-model="form.description" rows="3" :aria-invalid="!!errors.description" />
        </FormField>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormField id="item-standard-scheme" :label="t('items.form.standardScheme')" :hint="t('items.form.standardSchemeHint')" :error="errors.standard_scheme?.[0]" optional>
            <Input id="item-standard-scheme" v-model="form.standard_scheme" autocomplete="off" :aria-invalid="!!errors.standard_scheme" />
          </FormField>
          <FormField id="item-standard-code" :label="t('items.form.standardCode')" :error="errors.standard_code?.[0]" optional>
            <Input id="item-standard-code" v-model="form.standard_code" autocomplete="off" :aria-invalid="!!errors.standard_code" />
          </FormField>
        </div>

        <SwitchField id="item-active" v-model="form.is_active" :label="t('fields.active')" :description="t('items.form.activeHint')" />
      </form>

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">{{ t('common.cancel') }}</Button>
        <Button type="submit" form="item-form" :disabled="isPending">
          {{ isPending ? t('common.saving') : (item ? t('common.save') : t('items.form.create')) }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
