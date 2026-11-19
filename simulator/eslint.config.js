// ESLint of the simulator: the recommended rules of ESLint and the type-checked recommended rules of
// typescript-eslint, with the types of tsconfig.json. Type checking itself is npm run typecheck.
import js from '@eslint/js'
import { defineConfig } from 'eslint/config'
import tseslint from 'typescript-eslint'

export default defineConfig(
  js.configs.recommended,
  tseslint.configs.recommendedTypeChecked,
  {
    languageOptions: {
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
  },
  {
    // This file is JavaScript outside tsconfig.json: no type information for it.
    files: ['**/*.js'],
    extends: [tseslint.configs.disableTypeChecked],
  },
)
