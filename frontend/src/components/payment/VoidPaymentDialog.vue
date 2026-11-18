<script setup lang="ts">
import type { Payment } from '@/api/types'
import { BanIcon } from '@lucide/vue'
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { fieldErrors } from '@/api/client'
import { paymentsApi } from '@/api/resources'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Textarea } from '@/components/ui/textarea'
import { ALL_DOCUMENT_RESOURCES } from '@/lib/documents'
import { formatMoney } from '@/lib/format'
import { invalidateTenant, tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/** Voids a payment with its reason; its invoices get their balance back. */
const props = defineProps<{
  payment: Payment
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
const session = useSessionStore()
const queryClient = useQueryClient()
const reason = ref('')

watch(open, (isOpen) => {
  if (isOpen) {
    reason.value = ''
    voiding.reset()
  }
})

const voiding = useMutation({
  mutationFn: () => paymentsApi.void(props.payment.id, reason.value.trim()),
  onSuccess: async (updated) => {
    queryClient.setQueryData(tenantKey(session.currentCompanyId, 'payments', 'detail', updated.id), updated)
    await invalidateTenant('payments', 'receivables', 'open-invoices', ...ALL_DOCUMENT_RESOURCES)
    open.value = false
  },
})

const errors = computed(() => fieldErrors(voiding.error.value))
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ t('payments.void.title', { number: payment.full_number }) }}</DialogTitle>
        <DialogDescription>{{ t('payments.void.description', { amount: formatMoney(payment.amount), count: payment.allocations?.length ?? 0 }) }}</DialogDescription>
      </DialogHeader>

      <form id="void-payment-form" class="grid gap-4" novalidate @submit.prevent="voiding.mutate()">
        <FormError v-if="!errors.reason" :error="voiding.error.value" />
        <FormField id="void-reason" :label="t('payments.void.reason')" :hint="t('payments.void.reasonHint')" :error="errors.reason?.[0]">
          <Textarea id="void-reason" v-model="reason" rows="3" maxlength="500" :disabled="voiding.isPending.value" :aria-invalid="!!errors.reason" />
        </FormField>
      </form>

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">{{ t('common.cancel') }}</Button>
        <Button type="submit" form="void-payment-form" variant="destructive" :disabled="voiding.isPending.value || reason.trim().length < 5">
          <BanIcon />
          {{ voiding.isPending.value ? t('payments.void.voiding') : t('payments.void.confirm') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
