import { useMutation } from '@tanstack/vue-query'
import { computed } from 'vue'
import { fieldErrors } from '@/api/client'

/**
 * The form pattern of the app. Submitting runs the mutation; validation messages from the API
 * come back by field in `errors` (shown by FormField) and any other problem in `error`
 * (shown by FormError).
 */
export function useFormMutation<TResult>(options: {
  mutationFn: () => Promise<TResult>
  onSuccess?: (result: TResult) => unknown
}) {
  const mutation = useMutation({
    mutationFn: options.mutationFn,
    onSuccess: options.onSuccess,
  })

  return {
    submit: () => mutation.mutate(),
    isPending: mutation.isPending,
    error: mutation.error,
    errors: computed(() => fieldErrors(mutation.error.value)),
    reset: mutation.reset,
  }
}
