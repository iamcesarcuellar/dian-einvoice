import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript'
import prettier from 'eslint-config-prettier/flat'
import pluginVue from 'eslint-plugin-vue'
import { globalIgnores } from 'eslint/config'

export default defineConfigWithVueTs(
  {
    name: 'app/files-to-lint',
    files: ['**/*.{ts,vue}'],
  },

  globalIgnores(['dist/**']),

  pluginVue.configs['flat/recommended'],
  vueTsConfigs.recommendedTypeChecked,

  {
    name: 'app/rules',
    rules: {
      // Optional props declared with TypeScript are already typed T | undefined; a default of undefined adds nothing.
      'vue/require-default-prop': 'off',
    },
  },

  {
    name: 'app/shadcn-vue',
    files: ['src/components/ui/**'],
    rules: {
      // shadcn-vue names its components with one word, such as Button or Card.
      'vue/multi-word-component-names': 'off',
    },
  },

  {
    name: 'app/shadcn-vue-chart',
    files: ['src/components/ui/chart/**'],
    rules: {
      // The shadcn-vue chart code types the data that Unovis passes to its callbacks as any.
      '@typescript-eslint/no-explicit-any': 'off',
    },
  },

  {
    name: 'app/script-setup-generic',
    files: ['src/components/SearchSelect.vue'],
    rules: {
      // Type-aware rules read generic="T extends object" as type T = object: keyof T looks like never and the assertions to T unneeded.
      '@typescript-eslint/no-redundant-type-constituents': 'off',
      '@typescript-eslint/no-unnecessary-type-assertion': 'off',
    },
  },

  {
    name: 'app/type-from-vue-file',
    files: ['src/components/ui/sidebar/SidebarMenuButton.vue'],
    rules: {
      // Type-aware rules cannot read the props type it imports from another .vue file and see an error type.
      '@typescript-eslint/no-redundant-type-constituents': 'off',
    },
  },

  // Last: it turns off the rules that only format, which is the job of Prettier.
  prettier,
)
