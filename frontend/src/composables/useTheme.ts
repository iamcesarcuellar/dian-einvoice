import { useColorMode } from '@vueuse/core'
import { computed } from 'vue'

export type ThemeChoice = 'light' | 'dark' | 'auto'

/** Light, dark or the theme of the system, saved in this browser. index.html applies it before the app starts. */
// store holds the choice (auto included) and state the theme it resolves to.
const { store, state } = useColorMode({
  storageKey: 'dian-einvoice.theme',
  initialValue: 'auto',
  disableTransition: true,
})

export function useTheme() {
  return {
    /** What the user chose. */
    choice: computed<ThemeChoice>({
      get: () => store.value,
      set: (value) => {
        store.value = value
      },
    }),
    /** The theme on screen, with auto resolved. */
    resolved: computed<'light' | 'dark'>(() => (state.value === 'dark' ? 'dark' : 'light')),
  }
}
