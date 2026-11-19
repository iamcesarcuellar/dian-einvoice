<script setup lang="ts">
import type { ApiKeyInput, CreatedApiKey } from '@/api/types'
import type { Option } from '@/lib/options'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiKeysApi } from '@/api/developers'
import FormError from '@/components/FormError.vue'
import FormField from '@/components/FormField.vue'
import SelectInput from '@/components/SelectInput.vue'
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
import { todayInColombia } from '@/lib/format'
import { invalidateTenant } from '@/lib/query'

/** Creates an API key: name, level, optional expiration and optional rate limit. */
const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{
  created: [key: CreatedApiKey]
}>()

const { t } = useI18n()

const form = ref({ name: '', level: 'read_write' as string | null, expires_at: '', rate_limit_per_minute: '' })

const levelOptions = computed<Option[]>(() => [
  { value: 'read_write', label: t('developers.keys.levels.read_write') },
  { value: 'read_only', label: t('developers.keys.levels.read_only') },
])

function toInput(): ApiKeyInput {
  const limit = form.value.rate_limit_per_minute.trim()

  return {
    name: form.value.name.trim(),
    level: (form.value.level ?? 'read_only') as ApiKeyInput['level'],
    expires_at: form.value.expires_at || null,
    rate_limit_per_minute: limit === '' ? null : Number(limit),
  }
}

const { submit, isPending, error, errors, reset } = useFormMutation({
  mutationFn: () => apiKeysApi.create(toInput()),
  success: (created) => t('developers.keys.created', { name: created.name }),
  onSuccess: async (created) => {
    await invalidateTenant('api-keys')
    open.value = false
    emit('created', created)
  },
})

watch(open, (isOpen) => {
  if (isOpen) {
    form.value = { name: '', level: 'read_write', expires_at: '', rate_limit_per_minute: '' }
    reset()
  }
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ t('developers.keys.createTitle') }}</DialogTitle>
        <DialogDescription>{{ t('developers.keys.createDescription') }}</DialogDescription>
      </DialogHeader>

      <form id="api-key-form" class="grid gap-4" novalidate @submit.prevent="submit">
        <FormError :error="error" />

        <FormField id="api-key-name" :label="t('developers.keys.name')" :hint="t('developers.keys.nameHint')" :error="errors.name?.[0]">
          <Input id="api-key-name" v-model="form.name" autocomplete="off" :aria-invalid="!!errors.name" />
        </FormField>

        <FormField id="api-key-level" :label="t('developers.keys.level')" :hint="form.level ? t(`developers.keys.levelHints.${form.level}`) : undefined" :error="errors.level?.[0]">
          <SelectInput id="api-key-level" v-model="form.level" :options="levelOptions" :invalid="!!errors.level" />
        </FormField>

        <div class="grid gap-4 sm:grid-cols-2">
          <FormField id="api-key-expires" :label="t('developers.keys.expiresAt')" :hint="t('developers.keys.expiresHint')" :error="errors.expires_at?.[0]" optional>
            <Input id="api-key-expires" v-model="form.expires_at" type="date" :min="todayInColombia()" :aria-invalid="!!errors.expires_at" />
          </FormField>

          <FormField id="api-key-limit" :label="t('developers.keys.rateLimit')" :hint="t('developers.keys.rateLimitHint')" :error="errors.rate_limit_per_minute?.[0]" optional>
            <Input id="api-key-limit" v-model="form.rate_limit_per_minute" inputmode="numeric" placeholder="120" :aria-invalid="!!errors.rate_limit_per_minute" />
          </FormField>
        </div>
      </form>

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">{{ t('common.cancel') }}</Button>
        <Button type="submit" form="api-key-form" :disabled="isPending">
          {{ isPending ? t('developers.keys.creating') : t('developers.keys.create') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
