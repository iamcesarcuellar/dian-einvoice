<script setup lang="ts">
import { FlaskConicalIcon } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'
import { TONE_BADGE } from '@/lib/status'
import { cn } from '@/lib/utils'

/** Always on the top bar: everything here is a test, and the DIAN is a local simulator. */
const { t } = useI18n()
</script>

<template>
  <Tooltip>
    <TooltipTrigger as-child>
      <button
        type="button"
        :class="cn('inline-flex h-6 shrink-0 items-center gap-1.5 rounded-4xl border px-2.5 text-xs font-medium', TONE_BADGE.warning)"
        :aria-label="`${t('environment.label')}. ${t('environment.description')}`"
      >
        <FlaskConicalIcon class="size-3.5" aria-hidden="true" />
        <span class="hidden sm:inline">{{ t('environment.label') }}</span>
      </button>
    </TooltipTrigger>
    <TooltipContent side="bottom" class="max-w-72 text-pretty">
      {{ t('environment.description') }}
    </TooltipContent>
  </Tooltip>
</template>
