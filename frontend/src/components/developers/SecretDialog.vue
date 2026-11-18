<script setup lang="ts">
import { TriangleAlertIcon } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
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
import CopyButton from './CopyButton.vue'

/** Shows a new API key or webhook secret, the only time it can be seen, with a copy button. */
defineProps<{
  title: string
  description: string
  label: string
  value: string
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-xl" @interact-outside.prevent>
      <DialogHeader>
        <DialogTitle>{{ title }}</DialogTitle>
        <DialogDescription>{{ description }}</DialogDescription>
      </DialogHeader>

      <div class="grid gap-2">
        <p class="text-xs font-medium text-muted-foreground">{{ label }}</p>
        <div class="flex items-start gap-2">
          <code class="min-w-0 flex-1 rounded-md border bg-muted/40 px-3 py-2 font-mono text-sm break-all select-all">{{ value }}</code>
          <CopyButton :value="value" />
        </div>
      </div>

      <Alert>
        <TriangleAlertIcon />
        <AlertDescription>{{ t('developers.shownOnce') }}</AlertDescription>
      </Alert>

      <DialogFooter>
        <Button type="button" @click="open = false">{{ t('developers.savedIt') }}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
