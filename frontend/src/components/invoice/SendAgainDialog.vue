<script setup lang="ts">
import type { Invoice } from '@/api/types'
import { InfoIcon, SendIcon } from '@lucide/vue'
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { documentsApi } from '@/api/resources'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
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

/**
 * Send again: emails the zip of the invoice to the address in the field, written in already with
 * the current recipient or the customer email. The delivery runs in the background.
 */
const props = defineProps<{
  invoice: Invoice
}>()

const emit = defineEmits<{
  sent: [invoice: Invoice]
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()

const email = ref('')

const { submit, isPending, error, errors, reset } = useFormMutation({
  mutationFn: () => documentsApi(props.invoice.type).deliver(props.invoice.id, email.value.trim() || null),
  success: () => t('invoices.delivery.sending', { email: email.value.trim() || props.invoice.delivery?.customer_email || '' }),
  onSuccess: (invoice) => {
    emit('sent', invoice)
    open.value = false
  },
})

watch(open, (isOpen) => {
  if (isOpen) {
    email.value = props.invoice.delivery?.recipient ?? props.invoice.delivery?.customer_email ?? ''
    reset()
  }
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ t('invoices.delivery.sendTitle') }}</DialogTitle>
        <DialogDescription>{{ t('invoices.delivery.sendDescription') }}</DialogDescription>
      </DialogHeader>

      <form id="send-again-form" class="grid gap-4" novalidate @submit.prevent="submit">
        <FormError :error="error" />

        <FormField id="send-again-email" :label="t('invoices.delivery.email')" :hint="t('invoices.delivery.emailHint')" :error="errors.email?.[0]">
          <Input
            id="send-again-email"
            v-model="email"
            type="email"
            autocomplete="email"
            spellcheck="false"
            :disabled="isPending"
            :aria-invalid="!!errors.email"
          />
        </FormField>

        <Alert>
          <InfoIcon />
          <AlertDescription>{{ t('invoices.delivery.mailboxNote') }}</AlertDescription>
        </Alert>
      </form>

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">{{ t('common.cancel') }}</Button>
        <Button type="submit" form="send-again-form" :disabled="isPending || email.trim() === ''">
          <SendIcon />
          {{ isPending ? t('invoices.delivery.sending') : t('invoices.delivery.send') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
