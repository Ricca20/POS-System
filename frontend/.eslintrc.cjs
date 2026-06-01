'use strict';

/**
 * ESLint configuration for the Vue 3 SPA.
 *
 * Uses the legacy `.eslintrc.cjs` format paired with eslint 8.x to match
 * the electron/ shell and avoid the flat-config migration. `prettier` is
 * applied last so it disables stylistic rules that would otherwise fight
 * with the formatter.
 */

module.exports = {
  root: true,
  env: {
    browser: true,
    node: true,
    es2022: true,
  },
  extends: [
    'eslint:recommended',
    'plugin:vue/vue3-recommended',
    'prettier',
  ],
  parserOptions: {
    ecmaVersion: 2022,
    sourceType: 'module',
  },
  ignorePatterns: ['node_modules/', 'dist/', '.vite/', 'coverage/'],
  rules: {
    'no-console': 'off',
    'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
    'vue/multi-word-component-names': 'off',
  },
};
