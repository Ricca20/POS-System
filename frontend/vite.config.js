import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

/**
 * Vite configuration for the POS Desktop SPA.
 *
 * - `@` is aliased to `src/` to match the layered architecture in the design doc
 *   (api/, stores/, views/, components/, modules/).
 * - The dev server binds to 5173 on loopback only; the production build is
 *   loaded by Electron from the bundled `dist/` directory.
 * - The `test` block configures vitest with a happy-dom environment so Vue
 *   component tests can run without a real browser.
 */
export default defineConfig({
  base: './',
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
  },
  preview: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: true,
  },
  test: {
    environment: 'happy-dom',
    globals: true,
    include: ['src/**/*.test.{js,ts,vue}', 'tests/**/*.test.{js,ts}'],
    coverage: {
      reporter: ['text', 'html'],
    },
  },
});
