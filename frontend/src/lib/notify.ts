import { toast } from 'vue-sonner'

/**
 * The brief notification every finished action shows, at the corner of the screen. Errors that
 * belong to a form stay next to its fields; these are for the outcome of an action.
 */
export const notify = {
  success(message: string, description?: string): void {
    toast.success(message, { description })
  },

  info(message: string, description?: string): void {
    toast.info(message, { description })
  },

  warning(message: string, description?: string): void {
    toast.warning(message, { description })
  },

  error(message: string, description?: string): void {
    toast.error(message, { description })
  },
}
