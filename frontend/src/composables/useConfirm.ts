import { reactive } from 'vue'

export interface ConfirmOptions {
  title: string
  /** What is going to happen. */
  description: string
  confirmLabel: string
  cancelLabel?: string
  variant?: 'default' | 'destructive'
}

interface ConfirmState extends ConfirmOptions {
  open: boolean
  resolve: ((confirmed: boolean) => void) | null
}

const state = reactive<ConfirmState>({
  open: false,
  title: '',
  description: '',
  confirmLabel: '',
  cancelLabel: undefined,
  variant: 'default',
  resolve: null,
})

/**
 * Asks a yes or no question in the confirmation dialog of the app (ConfirmHost, mounted once in
 * App.vue) and resolves true when the user confirms. For actions that need no request of their
 * own, such as leaving a form with unsaved changes; actions that call the API use ConfirmDialog,
 * which also shows their progress and errors.
 */
export function confirmAction(options: ConfirmOptions): Promise<boolean> {
  state.resolve?.(false)

  return new Promise((resolve) => {
    Object.assign(state, { variant: 'default', cancelLabel: undefined, ...options, open: true, resolve })
  })
}

/** The state ConfirmHost renders. */
export function useConfirmState() {
  function settle(confirmed: boolean): void {
    const resolve = state.resolve

    state.open = false
    state.resolve = null
    resolve?.(confirmed)
  }

  return { state, settle }
}
