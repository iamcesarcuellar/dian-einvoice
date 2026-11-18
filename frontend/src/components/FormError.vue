<script setup lang="ts">
import { CircleAlertIcon } from '@lucide/vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError } from '@/api/client'
import { Alert, AlertDescription } from '@/components/ui/alert'

const props = defineProps<{
  error: unknown
  /** Also list validation messages, for places where fields cannot show them, such as a dialog. */
  list?: boolean
}>()

const { t } = useI18n()

// Validation messages are normally shown next to their fields; this alert shows everything else.
const messages = computed<string[]>(() => {
  const error = props.error

  if (!error) {
    return []
  }

  if (error instanceof ApiError) {
    const fieldMessages = Object.values(error.fieldErrors).flat()

    if (fieldMessages.length > 0) {
      return props.list ? fieldMessages : []
    }

    return [error.problem.detail ?? error.problem.title]
  }

  return [error instanceof TypeError ? t('errors.network') : t('errors.generic')]
})
</script>

<template>
  <Alert v-if="messages.length > 0" variant="destructive" role="alert">
    <CircleAlertIcon />
    <AlertDescription>
      <p v-if="messages.length === 1">{{ messages[0] }}</p>
      <ul v-else class="grid list-disc gap-1 pl-4">
        <li v-for="message in messages" :key="message">{{ message }}</li>
      </ul>
    </AlertDescription>
  </Alert>
</template>
