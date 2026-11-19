<script setup lang="ts">
import type { WebhookEventType, WebhookSubscription, WebhookSubscriptionInput, WebhookSubscriptionWithSecret } from '@/api/types'
import { useQuery } from '@tanstack/vue-query'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { webhooksApi } from '@/api/developers'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import SwitchField from '@/components/SwitchField.vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useFormMutation } from '@/composables/useFormMutation'
import { invalidateTenant, tenantKey } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/** Creates or edits a webhook subscription: URL, description, the events it receives and whether it is on. */
const props = defineProps<{
  /** The subscription to edit, or null to create one. */
  subscription: WebhookSubscription | null
  /** A URL to start a new subscription with, such as the local receiver. */
  suggestedUrl?: string
}>()

const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{
  created: [subscription: WebhookSubscriptionWithSecret]
}>()

const { t } = useI18n()
const session = useSessionStore()

const { data: eventTypes } = useQuery({
  queryKey: computed(() => tenantKey(session.currentCompanyId, 'webhook-event-types')),
  queryFn: () => webhooksApi.eventTypes(),
  staleTime: Infinity,
})

/** Event types by resource, in the order of the API: invoice, credit_note, debit_note, payment. */
const groups = computed(() => {
  const byResource = new Map<string, WebhookEventType[]>()

  for (const type of eventTypes.value ?? []) {
    const resource = type.type.split('.')[0] ?? type.type
    byResource.set(resource, [...(byResource.get(resource) ?? []), type])
  }

  return [...byResource.entries()].map(([resource, types]) => ({ resource, types }))
})

const form = ref({ url: '', description: '', events: [] as string[], is_active: true })

const allSelected = computed(() => (eventTypes.value?.length ?? 0) > 0 && form.value.events.length === eventTypes.value?.length)

function toggle(type: string, checked: boolean | 'indeterminate'): void {
  form.value.events = checked === true
    ? [...new Set([...form.value.events, type])]
    : form.value.events.filter((event) => event !== type)
}

function toggleAll(): void {
  form.value.events = allSelected.value ? [] : (eventTypes.value ?? []).map((type) => type.type)
}

function toInput(): WebhookSubscriptionInput {
  return {
    url: form.value.url.trim(),
    description: form.value.description.trim() || null,
    events: form.value.events,
    is_active: form.value.is_active,
  }
}

const { submit, isPending, error, errors, reset } = useFormMutation({
  mutationFn: async () => (props.subscription
    ? { updated: await webhooksApi.update(props.subscription.id, toInput()), created: null }
    : { updated: null, created: await webhooksApi.create(toInput()) }),
  success: ({ created }) => t(created ? 'developers.webhooks.created' : 'developers.webhooks.saved'),
  onSuccess: async ({ created }) => {
    await invalidateTenant('webhooks')
    open.value = false

    if (created) {
      emit('created', created)
    }
  },
})

watch(open, (isOpen) => {
  if (isOpen) {
    form.value = {
      url: props.subscription?.url ?? '',
      description: props.subscription?.description ?? '',
      events: [...(props.subscription?.events ?? (eventTypes.value ?? []).map((type) => type.type))],
      is_active: props.subscription?.is_active ?? true,
    }
    reset()
  }
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-2xl">
      <DialogHeader>
        <DialogTitle>{{ subscription ? t('developers.webhooks.editTitle') : t('developers.webhooks.createTitle') }}</DialogTitle>
        <DialogDescription>{{ t('developers.webhooks.formDescription') }}</DialogDescription>
      </DialogHeader>

      <form id="webhook-form" class="grid max-h-[65vh] gap-4 overflow-y-auto pr-1" novalidate @submit.prevent="submit">
        <FormError :error="error" />

        <FormField id="webhook-url" :label="t('developers.webhooks.url')" :hint="t('developers.webhooks.urlHint')" :error="errors.url?.[0]">
          <Input id="webhook-url" v-model="form.url" type="url" autocomplete="off" spellcheck="false" class="font-mono" placeholder="https://" :aria-invalid="!!errors.url" />
        </FormField>
        <div v-if="suggestedUrl && form.url !== suggestedUrl" class="-mt-2">
          <Button type="button" variant="link" size="xs" class="h-auto px-0" @click="form.url = suggestedUrl">
            {{ t('developers.webhooks.useReceiver') }}
          </Button>
        </div>

        <FormField id="webhook-description" :label="t('developers.webhooks.descriptionLabel')" :error="errors.description?.[0]" optional>
          <Input id="webhook-description" v-model="form.description" autocomplete="off" :aria-invalid="!!errors.description" />
        </FormField>

        <fieldset class="grid gap-3">
          <div class="flex items-center justify-between gap-3">
            <legend class="text-sm font-medium">{{ t('developers.webhooks.events') }}</legend>
            <Button type="button" variant="ghost" size="xs" @click="toggleAll">
              {{ allSelected ? t('developers.webhooks.selectNone') : t('developers.webhooks.selectAll') }}
            </Button>
          </div>
          <p v-if="errors.events" class="text-xs text-destructive">{{ errors.events[0] }}</p>

          <div class="grid gap-4 sm:grid-cols-2">
            <div v-for="group in groups" :key="group.resource" class="grid content-start gap-2">
              <p class="text-xs font-medium text-muted-foreground">{{ t(`developers.webhooks.resources.${group.resource}`) }}</p>
              <div v-for="type in group.types" :key="type.type" class="flex items-start gap-2">
                <Checkbox
                  :id="`webhook-event-${type.type}`"
                  :model-value="form.events.includes(type.type)"
                  class="mt-0.5"
                  @update:model-value="(checked) => toggle(type.type, checked)"
                />
                <Label :for="`webhook-event-${type.type}`" class="grid gap-0.5 font-normal">
                  <span class="font-mono text-xs">{{ type.type }}</span>
                  <span class="text-xs text-muted-foreground">{{ type.description }}</span>
                </Label>
              </div>
            </div>
          </div>
        </fieldset>

        <SwitchField id="webhook-active" v-model="form.is_active" :label="t('fields.active')" :description="t('developers.webhooks.activeHint')" />
      </form>

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">{{ t('common.cancel') }}</Button>
        <Button type="submit" form="webhook-form" :disabled="isPending">
          {{ isPending ? t('common.saving') : (subscription ? t('common.save') : t('developers.webhooks.create')) }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
