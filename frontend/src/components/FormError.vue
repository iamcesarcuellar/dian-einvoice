<script setup lang="ts">
import { CircleAlertIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/api/client'
import { Alert, AlertDescription } from '@/components/ui/alert'

const props = defineProps<{
  error: unknown
}>()

const { t } = useI18n()

// Validation messages are shown next to their fields; this alert shows every other error.
const message = computed(() => {
  const error = props.error

  if (!error) {
    return null
  }

  if (error instanceof ApiError) {
    return Object.keys(error.fieldErrors).length > 0 ? null : (error.problem.detail ?? error.problem.title)
  }

  return error instanceof TypeError ? t('errors.network') : t('errors.generic')
})
</script>

<template>
  <Alert v-if="message" variant="destructive" role="alert">
    <CircleAlertIcon />
    <AlertDescription>{{ message }}</AlertDescription>
  </Alert>
</template>
