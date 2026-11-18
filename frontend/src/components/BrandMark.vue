<script setup lang="ts">
import { ReceiptTextIcon } from '@lucide/vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<template>
  <div class="flex items-center gap-2.5">
    <span class="flex size-7 items-center justify-center rounded-md bg-primary text-primary-foreground">
      <ReceiptTextIcon class="size-4" aria-hidden="true" />
    </span>
    <span class="text-[15px] font-semibold tracking-tight text-foreground">{{ t('app.name') }}</span>
  </div>
</template>
