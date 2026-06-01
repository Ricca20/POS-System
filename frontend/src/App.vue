<script setup>
// Root component for the POS Desktop SPA.
//
// Intentionally minimal for task 1.2. The full layout (top bar with
// connectivity indicator, navigation, router view, toast container) is
// added in subsequent tasks (9.x).
</script>

<template>
  <div id="pos-root" class="min-h-screen bg-slate-50 text-slate-900">
    <main class="flex min-h-screen items-center justify-center p-6">
      <div class="rounded-lg border border-slate-200 bg-white px-8 py-6 shadow-sm">
        <h1 class="text-xl font-semibold">POS Desktop</h1>
        <p class="mt-1 text-sm text-slate-500">Vue 3 + Vite + Pinia skeleton ready.</p>
        <div class="mt-4">
          <router-view />
        </div>
      </div>
    </main>
  </div>
</template>
