import js from '@eslint/js'
import globals from 'globals'
import react from 'eslint-plugin-react'
import reactHooks from 'eslint-plugin-react-hooks'

/**
 * Lint config for the dashboard SPA. Its job is to catch the class of bug that shipped once
 * already: a free identifier that was never imported (`useConfirm` in ProductEditor/ShippingPanel).
 * Rollup silently treats those as globals, so `no-undef` is the only thing standing between a typo
 * and a white screen — `npm run build` runs this first.
 *
 * Two deliberate narrowings:
 * - Only `react/jsx-uses-vars` is taken from eslint-plugin-react. Core `no-unused-vars` cannot see
 *   JSX references, and this codebase imports dozens of components/icons used solely as elements;
 *   without this rule every one of them is a false positive. The rest of the plugin's recommended
 *   set (notably react/prop-types) is noise for a codebase that does not use prop-types.
 * - Only the two classic hooks rules are enabled. eslint-plugin-react-hooks v7's `recommended`
 *   bundles the React Compiler rules (purity, immutability, set-state-in-effect, …), which is a
 *   separate, much larger conversation than "don't ship undefined identifiers".
 */
export default [
  { ignores: ['node_modules/**', 'public/**'] },

  // Browser code.
  {
    files: ['src/**/*.{js,jsx}'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: globals.browser,
      parserOptions: { ecmaFeatures: { jsx: true } },
    },
    plugins: { react, 'react-hooks': reactHooks },
    settings: { react: { version: 'detect' } },
    rules: {
      ...js.configs.recommended.rules,
      'react/jsx-uses-vars': 'error',
      'react-hooks/rules-of-hooks': 'error',
      'react-hooks/exhaustive-deps': 'warn',
      'no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
    },
  },

  // Build config — Node, not browser.
  {
    files: ['vite.config.js', 'eslint.config.js'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: globals.node,
    },
    rules: js.configs.recommended.rules,
  },
]
