'use strict';

/**
 * ESLint configuration for the Electron shell.
 *
 * Uses the legacy `.eslintrc.cjs` format paired with eslint 8.x so that
 * `npm run lint` works out-of-the-box without requiring the flat-config
 * migration.
 */

module.exports = {
  root: true,
  env: {
    node: true,
    es2022: true,
  },
  extends: ['eslint:recommended'],
  parserOptions: {
    ecmaVersion: 2022,
    sourceType: 'script',
  },
  ignorePatterns: ['node_modules/', 'dist/', 'out/'],
  rules: {
    'no-console': 'off',
    'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
  },
};
