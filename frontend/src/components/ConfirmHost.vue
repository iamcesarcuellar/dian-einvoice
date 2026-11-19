<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { useConfirmState } from '@/composables/useConfirm'

/** The dialog of confirmAction, mounted once for the whole app. */
const { t } = useI18n()
const { state, settle } = useConfirmState()

function onOpenChange(open: boolean): void {
  if (!open) {
    settle(false)
  }
}
</script>

<template>
  <Dialog :open="state.open" @update:open="onOpenChange">
    <DialogContent class="sm:max-w-md" :show-close-button="false">
      <DialogHeader>
        <DialogTitle>{{ state.title }}</DialogTitle>
        <DialogDescription>{{ state.description }}</DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button type="button" variant="outline" @click="settle(false)">
          {{ state.cancelLabel ?? t('common.cancel') }}
        </Button>
        <Button type="button" :variant="state.variant" @click="settle(true)">
          {{ state.confirmLabel }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
