import { ref } from 'vue'

const open = ref(false)

/** Whether the command palette is open; Ctrl+K (Cmd+K) and the search button of the top bar toggle it. */
export function useCommandPalette() {
  return {
    open,
    show: () => {
      open.value = true
    },
    toggle: () => {
      open.value = !open.value
    },
  }
}
