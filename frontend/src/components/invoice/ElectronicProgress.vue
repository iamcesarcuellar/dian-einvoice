<script setup lang="ts">
import type { ElectronicDocument, InvoiceDelivery } from '@/api/types'
import { CheckIcon, LoaderCircleIcon, MinusIcon, XIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatDateTime } from '@/lib/format'
import { cn } from '@/lib/utils'

/**
 * The six steps of an issued invoice, Issued, XML, Signed, Sent, Accepted and Delivered, as the
 * electronic document chain and then the customer delivery move them. The invoice page refreshes
 * it every 2 seconds until both end. A rejection or a failure shows on the step where it happened;
 * when the customer has no email, the last step says the delivery does not apply.
 */
const props = defineProps<{
  electronic: ElectronicDocument
  delivery: InvoiceDelivery | null
}>()

const { t } = useI18n()

type StepState = 'done' | 'current' | 'error' | 'todo' | 'skipped'

interface Step {
  key: string
  label: string
  state: StepState
}

/** The last step, which follows the delivery once the invoice is accepted. */
const deliveryStep = computed<Step>(() => {
  const status = props.delivery?.status ?? null

  if (props.electronic.status !== 'accepted') {
    return { key: 'delivered', label: t('invoices.progress.delivered'), state: 'todo' }
  }

  switch (status) {
    case 'delivered':
      return { key: 'delivered', label: t('invoices.progress.delivered'), state: 'done' }
    case 'failed':
      return { key: 'delivered', label: t('invoices.progress.deliveryFailed'), state: 'error' }
    case 'not_applicable':
      return { key: 'delivered', label: t('invoices.progress.notApplicable'), state: 'skipped' }
    default:
      return { key: 'delivered', label: t('invoices.progress.delivered'), state: 'current' }
  }
})

const steps = computed<Step[]>(() => {
  const { status, failed_step: failedStep } = props.electronic
  const labels = ['issued', 'xml', 'signed', 'sent', 'accepted']

  // How many steps of the electronic chain are complete, and what the next one is doing.
  let done = 1
  let next: StepState = 'current'

  switch (status) {
    case 'pending':
      done = 1
      break
    case 'generated':
      done = 2
      break
    case 'signed':
    case 'sending':
      done = 3
      break
    case 'accepted':
      done = 5
      break
    case 'rejected':
      done = 4
      next = 'error'
      break
    case 'failed':
      done = failedStep === 'transmit' ? 3 : failedStep === 'sign' ? 2 : 1
      next = 'error'
      break
  }

  const chain = labels.map((key, index): Step => {
    const state: StepState = index < done ? 'done' : index === done ? next : 'todo'
    const label = index === 4 && status === 'rejected' ? t('invoices.progress.rejected') : t(`invoices.progress.${key}`)

    return { key, label, state }
  })

  return [...chain, deliveryStep.value]
})

const note = computed(() => {
  const electronic = props.electronic
  const delivery = props.delivery

  if (electronic.retrying && electronic.next_attempt_at) {
    return t('invoices.progress.retrying', { attempt: electronic.attempt, max: electronic.max_attempts, time: formatDateTime(electronic.next_attempt_at) })
  }

  if (electronic.status === 'sending') {
    return t('invoices.progress.sending', { attempt: electronic.attempt, max: electronic.max_attempts })
  }

  if (!electronic.final) {
    return t('invoices.progress.working')
  }

  if (electronic.status !== 'accepted' || !delivery) {
    return null
  }

  if (delivery.status === 'not_applicable') {
    return t('invoices.progress.notApplicableNote')
  }

  if (delivery.status === 'pending' || delivery.status === null) {
    if (delivery.retrying && delivery.next_attempt_at) {
      return t('invoices.progress.deliveryRetrying', { attempt: delivery.attempt, max: delivery.max_attempts, time: formatDateTime(delivery.next_attempt_at) })
    }

    return t(`invoices.progress.delivering.${delivery.step ?? 'attached_document'}`, { attempt: Math.max(1, delivery.attempt), max: delivery.max_attempts })
  }

  return null
})

const circle: Record<StepState, string> = {
  done: 'bg-primary text-primary-foreground border-primary',
  current: 'border-primary text-primary bg-background',
  error: 'bg-destructive text-white border-destructive',
  todo: 'border-border text-muted-foreground bg-background',
  skipped: 'border-border text-muted-foreground bg-muted',
}
</script>

<template>
  <section class="grid gap-3 rounded-lg border bg-card px-4 py-4 shadow-xs sm:px-6" :aria-label="t('invoices.progress.label')">
    <ol class="grid grid-cols-6 items-start">
      <li
        v-for="(step, index) in steps"
        :key="step.key"
        class="relative grid justify-items-center gap-1.5 text-center"
        :aria-current="step.state === 'current' ? 'step' : undefined"
      >
        <span
          v-if="index > 0"
          class="absolute top-3.5 right-1/2 h-0.5 w-full -translate-y-1/2"
          :class="step.state === 'todo' ? 'bg-border' : step.state === 'error' ? 'bg-destructive/60' : step.state === 'skipped' ? 'bg-border' : 'bg-primary'"
          aria-hidden="true"
        />
        <span :class="cn('relative z-10 grid size-7 place-items-center rounded-full border-2 text-xs font-semibold', circle[step.state])">
          <CheckIcon v-if="step.state === 'done'" class="size-4" aria-hidden="true" />
          <LoaderCircleIcon v-else-if="step.state === 'current'" class="size-4 animate-spin" aria-hidden="true" />
          <XIcon v-else-if="step.state === 'error'" class="size-4" aria-hidden="true" />
          <MinusIcon v-else-if="step.state === 'skipped'" class="size-4" aria-hidden="true" />
          <span v-else aria-hidden="true">{{ index + 1 }}</span>
        </span>
        <span
          class="text-xs font-medium sm:text-sm"
          :class="step.state === 'todo' || step.state === 'skipped' ? 'text-muted-foreground' : step.state === 'error' ? 'text-destructive' : ''"
        >
          {{ step.label }}
          <span class="sr-only">({{ t(`invoices.progress.${step.state}`) }})</span>
        </span>
      </li>
    </ol>
    <p v-if="note" class="text-center text-xs text-muted-foreground" role="status" aria-live="polite">{{ note }}</p>
  </section>
</template>
