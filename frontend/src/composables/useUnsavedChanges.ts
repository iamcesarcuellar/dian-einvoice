import type { MaybeRefOrGetter } from 'vue'
import { useEventListener } from '@vueuse/core'
import { computed, ref, toValue } from 'vue'
import { useI18n } from 'vue-i18n'
import { onBeforeRouteLeave } from 'vue-router'
import { confirmAction } from '@/composables/useConfirm'

/**
 * The warning of every form page: leaving it with changes that were not saved asks first, inside
 * the app (route change) and when the tab is closed or reloaded. The form is dirty while its state
 * differs from the last baseline: call reset() once it is loaded and right after it is saved.
 */
export function useUnsavedChanges(source: MaybeRefOrGetter<unknown>, enabled: MaybeRefOrGetter<boolean> = true) {
  const { t } = useI18n()
  const baseline = ref(JSON.stringify(toValue(source)))

  const isDirty = computed(() => toValue(enabled) && JSON.stringify(toValue(source)) !== baseline.value)

  function reset(): void {
    baseline.value = JSON.stringify(toValue(source))
  }

  onBeforeRouteLeave(async () => {
    if (!isDirty.value) {
      return true
    }

    return confirmAction({
      title: t('unsaved.title'),
      description: t('unsaved.description'),
      confirmLabel: t('unsaved.leave'),
      cancelLabel: t('unsaved.stay'),
      variant: 'destructive',
    })
  })

  useEventListener(window, 'beforeunload', (event: BeforeUnloadEvent) => {
    if (isDirty.value) {
      event.preventDefault()
    }
  })

  return { isDirty, reset }
}
