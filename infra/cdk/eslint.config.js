// ESLint of the CDK app: the recommended rules of ESLint and the type-checked recommended rules of
// typescript-eslint, with the types of tsconfig.json. Type checking itself is npm run typecheck.
import js from '@eslint/js'
import { defineConfig } from 'eslint/config'
import tseslint from 'typescript-eslint'

export default defineConfig(
  { ignores: ['cdk.out/'] },
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
    files: ['test/**/*.ts'],
    rules: {
      // describe() and it() of node:test return promises that the test runner awaits.
      '@typescript-eslint/no-floating-promises': ['error', {
        allowForKnownSafeCalls: [{ from: 'package', package: 'node:test', name: ['describe', 'it'] }],
      }],
    },
  },
  {
    // This file is JavaScript outside tsconfig.json: no type information for it.
    files: ['**/*.js'],
    extends: [tseslint.configs.disableTypeChecked],
  },
  {
    // The CloudFront Functions: scripts of the runtime cloudfront-js-2.0, which calls their handler.
    files: ['edge/**/*.js'],
    languageOptions: { sourceType: 'script' },
  },
)
