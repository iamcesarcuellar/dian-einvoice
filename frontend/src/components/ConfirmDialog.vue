<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import FormError from '@/components/FormError.vue'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'

/** Asks before a destructive action, such as deleting, and shows why it failed. */
defineProps<{
  title: string
  description: string
  confirmLabel: string
  pending?: boolean
  error?: unknown
}>()

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

      <FormError :error="error" />

      <DialogFooter>
        <Button type="button" variant="outline" @click="open = false">
          {{ t('common.cancel') }}
        </Button>
        <Button type="button" variant="destructive" :disabled="pending" @click="emit('confirm')">
          {{ confirmLabel }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
