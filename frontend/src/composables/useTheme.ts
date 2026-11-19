import { useColorMode } from '@vueuse/core'
import { computed } from 'vue'

export type ThemeChoice = 'light' | 'dark' | 'auto'

/** Light, dark or the theme of the system, saved in this browser. index.html applies it before the app starts. */
const mode = useColorMode({
  storageKey: 'dian-einvoice.theme',
  initialValue: 'auto',
  disableTransition: true,
})

export function useTheme() {
  return {
    /** What the user chose. */
    choice: computed<ThemeChoice>({
      get: () => mode.store.value as ThemeChoice,
      set: (value) => {
        mode.value = value
      },
    }),
    /** The theme on screen, with auto resolved. */
    resolved: computed<'light' | 'dark'>(() => (mode.state.value === 'dark' ? 'dark' : 'light')),
  }
}
