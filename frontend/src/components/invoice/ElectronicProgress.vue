<script setup lang="ts">
import type { ElectronicDocument } from '@/api/types'
import { CheckIcon, LoaderCircleIcon, XIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatDateTime } from '@/lib/format'
import { cn } from '@/lib/utils'

/**
 * The five steps of the electronic document of an issued invoice, Issued, XML, Signed, Sent and
 * Accepted, as the chain moves them. The invoice page refreshes it every 2 seconds until the chain
 * ends. A rejection or a failure shows on the step where it happened.
 */
const props = defineProps<{
  electronic: ElectronicDocument
}>()

const { t } = useI18n()

type StepState = 'done' | 'current' | 'error' | 'todo'

interface Step {
  key: string
  label: string
  state: StepState
}

const steps = computed<Step[]>(() => {
  const { status, failed_step: failedStep } = props.electronic
  const labels = ['issued', 'xml', 'signed', 'sent', 'accepted']

  // How many steps are complete, and what the next one is doing.
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

  return labels.map((key, index) => {
    const state: StepState = index < done ? 'done' : index === done ? next : 'todo'
    const label = index === 4 && status === 'rejected'
      ? t('invoices.progress.rejected')
      : t(`invoices.progress.${key}`)

    return { key, label, state }
  })
})

const note = computed(() => {
  const electronic = props.electronic

  if (electronic.retrying && electronic.next_attempt_at) {
    return t('invoices.progress.retrying', { attempt: electronic.attempt, max: electronic.max_attempts, time: formatDateTime(electronic.next_attempt_at) })
  }

  if (electronic.status === 'sending') {
    return t('invoices.progress.sending', { attempt: electronic.attempt, max: electronic.max_attempts })
  }

  return electronic.final ? null : t('invoices.progress.working')
})

const circle: Record<StepState, string> = {
  done: 'bg-primary text-primary-foreground border-primary',
  current: 'border-primary text-primary bg-background',
  error: 'bg-destructive text-white border-destructive',
  todo: 'border-border text-muted-foreground bg-background',
}
</script>

<template>
  <section class="grid gap-3 rounded-lg border bg-card px-4 py-4 shadow-xs sm:px-6" :aria-label="t('invoices.progress.label')">
    <ol class="grid grid-cols-5 items-start">
      <li
        v-for="(step, index) in steps"
        :key="step.key"
        class="relative grid justify-items-center gap-1.5 text-center"
        :aria-current="step.state === 'current' ? 'step' : undefined"
      >
        <span
          v-if="index > 0"
          class="absolute top-3.5 right-1/2 h-0.5 w-full -translate-y-1/2"
          :class="step.state === 'todo' ? 'bg-border' : (step.state === 'error' ? 'bg-destructive/60' : 'bg-primary')"
          aria-hidden="true"
        />
        <span :class="cn('relative z-10 grid size-7 place-items-center rounded-full border-2 text-xs font-semibold', circle[step.state])">
          <CheckIcon v-if="step.state === 'done'" class="size-4" aria-hidden="true" />
          <LoaderCircleIcon v-else-if="step.state === 'current'" class="size-4 animate-spin" aria-hidden="true" />
          <XIcon v-else-if="step.state === 'error'" class="size-4" aria-hidden="true" />
          <span v-else aria-hidden="true">{{ index + 1 }}</span>
        </span>
        <span class="text-xs font-medium sm:text-sm" :class="step.state === 'todo' ? 'text-muted-foreground' : (step.state === 'error' ? 'text-destructive' : '')">
          {{ step.label }}
          <span class="sr-only">({{ t(`invoices.progress.${step.state}`) }})</span>
        </span>
      </li>
    </ol>
    <p v-if="note" class="text-center text-xs text-muted-foreground" role="status" aria-live="polite">{{ note }}</p>
  </section>
</template>
