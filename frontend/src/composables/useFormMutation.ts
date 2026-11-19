import { useMutation } from '@tanstack/vue-query'
import { computed } from 'vue'
import { fieldErrors } from '@/api/client'
import { notify } from '@/lib/notify'

/**
 * The form pattern of the app. Submitting runs the mutation; validation messages from the API
 * come back by field in `errors` (shown by FormField next to each field) and any other problem in
 * `error` (shown by FormError). A finished save shows a brief notification (`success`).
 */
export function useFormMutation<TResult>(options: {
  mutationFn: () => Promise<TResult>
  onSuccess?: (result: TResult) => unknown
  /** The notification once it succeeds. */
  success?: string | ((result: TResult) => string)
}) {
  const mutation = useMutation({
    mutationFn: options.mutationFn,
    onSuccess: async (result: TResult) => {
      await options.onSuccess?.(result)

      const message = typeof options.success === 'function' ? options.success(result) : options.success

      if (message) {
        notify.success(message)
      }
    },
  })

  return {
    submit: () => mutation.mutate(),
    isPending: mutation.isPending,
    error: mutation.error,
    errors: computed(() => fieldErrors(mutation.error.value)),
    reset: mutation.reset,
  }
}
