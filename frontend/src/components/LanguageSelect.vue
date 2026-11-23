<script setup lang="ts">
import { LanguagesIcon } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { LANGUAGES, setLanguage } from '@/i18n'

/** The language of the interface on the screens without the app frame: sign in, create an account, an invitation. */
const { t, locale } = useI18n()

function choose(value: unknown): void {
  if (value === 'en' || value === 'es') {
    setLanguage(value)
  }
}
</script>

<template>
  <Select :model-value="locale" @update:model-value="choose">
    <SelectTrigger size="sm" class="w-auto" :aria-label="t('languages.label')">
      <LanguagesIcon class="text-muted-foreground" aria-hidden="true" />
      <SelectValue />
    </SelectTrigger>
    <SelectContent position="popper" align="end">
      <SelectItem v-for="language in LANGUAGES" :key="language" :value="language" :lang="language">
        {{ t(`languages.${language}`) }}
      </SelectItem>
    </SelectContent>
  </Select>
</template>
