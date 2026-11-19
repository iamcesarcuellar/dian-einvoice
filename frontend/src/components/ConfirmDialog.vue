<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import FormError from '@/components/FormError.vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'

/** Asks before an action that cannot be undone, such as deleting or issuing, and shows why it failed. */
withDefaults(
  defineProps<{
    title: string
    description: string
    confirmLabel: string
    pending?: boolean
    error?: unknown
    /** "destructive" for deleting, "default" for other final actions. */
    variant?: 'default' | 'destructive'
  }>(),
  {
    pending: false,
    variant: 'destructive',
  },
)

const emit = defineEmits<{
  confirm: []
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md" :show-close-button="false">
      <DialogHeader>
        <DialogTitle>{{ title }}</DialogTitle>
        <DialogDescription>{{ description }}</DialogDescription>
      </DialogHeader>

      <FormError :error="error" list />

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">
          {{ t('common.cancel') }}
        </Button>
        <Button type="button" :variant="variant" :disabled="pending" @click="emit('confirm')">
          {{ confirmLabel }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
