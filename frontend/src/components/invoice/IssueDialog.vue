<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import { ArrowRightIcon, CircleAlertIcon, ClipboardListIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/api/client'
import FormError from '@/components/FormError.vue'
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

/**
 * Confirms issuing a draft. When the API answers 422 because company, DIAN, numbering or
 * customer data is missing, it lists what is missing with a link to the screen where each item is
 * completed; any other problem with the draft is listed below.
 */
const props = defineProps<{
  numbering: string
  customerId: string | null
  pending: boolean
  error: unknown
}>()

const emit = defineEmits<{
  confirm: []
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()

/** Prefix of the error keys the API uses for missing data, and the screen that completes it. */
const screens = computed<Record<string, { label: string, to: RouteLocationRaw } | null>>(() => ({
  company: { label: t('invoices.issue.screens.company'), to: { name: 'company-settings' } },
  dian: { label: t('invoices.issue.screens.dian'), to: { name: 'dian-settings' } },
  numbering: { label: t('invoices.issue.screens.numbering'), to: { name: 'numbering' } },
  customer: props.customerId
    ? { label: t('invoices.issue.screens.customer'), to: { name: 'contact-edit', params: { id: props.customerId } } }
    : null,
}))

interface Missing {
  key: string
  message: string
  link: { label: string, to: RouteLocationRaw } | null
}

const fieldErrors = computed(() => (props.error instanceof ApiError ? props.error.fieldErrors : {}))

const missing = computed<Missing[]>(() => Object.entries(fieldErrors.value)
  .filter(([key]) => key.split('.')[0] in screens.value)
  .map(([key, messages]) => ({
    key,
    message: messages[0] ?? key,
    // The certificate lives on the DIAN settings screen too, in its own card.
    link: key === 'dian.certificate'
      ? { label: t('invoices.issue.screens.certificate'), to: { name: 'dian-settings' } }
      : screens.value[key.split('.')[0] as string] ?? null,
  })))

/** Other validation messages, about the draft itself. */
const others = computed(() => Object.entries(fieldErrors.value)
  .filter(([key]) => !(key.split('.')[0] in screens.value))
  .flatMap(([, messages]) => messages))

const hasFieldErrors = computed(() => Object.keys(fieldErrors.value).length > 0)
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg" :show-close-button="false">
      <DialogHeader>
        <DialogTitle>{{ t('invoices.editor.issueTitle') }}</DialogTitle>
        <DialogDescription>{{ t('invoices.editor.issueDescription', { numbering }) }}</DialogDescription>
      </DialogHeader>

      <!-- Problems that are not field validation, such as a conflict or a network error. -->
      <FormError v-if="!hasFieldErrors" :error="error" />

      <div v-if="missing.length > 0" class="grid gap-3 rounded-lg border p-4" role="alert">
        <p class="flex items-center gap-2 font-medium">
          <ClipboardListIcon class="size-4 text-muted-foreground" />
          {{ t('invoices.issue.missingTitle') }}
        </p>
        <ul class="grid gap-2">
          <li v-for="item in missing" :key="item.key" class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <span>{{ item.message }}</span>
            <RouterLink
              v-if="item.link"
              :to="item.link.to"
              class="inline-flex items-center gap-1 text-sm font-medium whitespace-nowrap text-primary underline-offset-4 hover:underline"
              @click="open = false"
            >
              {{ item.link.label }}
              <ArrowRightIcon class="size-3.5" />
            </RouterLink>
          </li>
        </ul>
      </div>

      <Alert v-if="others.length > 0" variant="destructive" role="alert">
        <CircleAlertIcon />
        <AlertDescription>
          <p v-if="others.length === 1">{{ others[0] }}</p>
          <ul v-else class="grid list-disc gap-1 pl-4">
            <li v-for="message in others" :key="message">{{ message }}</li>
          </ul>
        </AlertDescription>
      </Alert>

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">
          {{ t('common.cancel') }}
        </Button>
        <Button type="button" :disabled="pending" @click="emit('confirm')">
          {{ pending ? t('invoices.editor.issuing') : t('invoices.editor.issue') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
