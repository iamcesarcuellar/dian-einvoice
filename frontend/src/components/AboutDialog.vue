<script setup lang="ts">
import { BookOpenIcon, FlaskConicalIcon, InboxIcon, ReceiptTextIcon } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { product } from '@/config/product'

/** What this product is, what is simulated, and where the local tools are. */
const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <span class="mb-2 flex size-10 items-center justify-center rounded-lg bg-primary text-primary-foreground">
          <ReceiptTextIcon class="size-5" aria-hidden="true" />
        </span>
        <DialogTitle>{{ product.name }}</DialogTitle>
        <DialogDescription>{{ t('about.summary') }}</DialogDescription>
      </DialogHeader>

      <div class="grid gap-3 text-sm">
        <p class="rounded-lg border bg-muted/50 p-3 font-medium">{{ t('about.disclaimer') }}</p>
        <ul class="grid gap-2 text-muted-foreground">
          <li class="flex gap-2.5">
            <FlaskConicalIcon class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            {{ t('about.simulated') }}
          </li>
          <li class="flex gap-2.5">
            <InboxIcon class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            {{ t('about.mailbox') }}
          </li>
          <li class="flex gap-2.5">
            <BookOpenIcon class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>
              {{ t('about.reference') }}
              <a href="/docs/api" target="_blank" rel="noopener" class="font-medium text-primary hover:underline">{{ t('about.referenceLink') }}</a>
            </span>
          </li>
        </ul>
      </div>

      <DialogFooter>
        <Button type="button" @click="open = false">{{ t('common.close') }}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
