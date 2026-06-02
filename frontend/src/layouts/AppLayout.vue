<template>
  <div class="flex h-screen bg-gray-100">
    <!-- Sidebar Placeholder -->
    <aside class="w-64 bg-white border-r hidden md:flex flex-col">
      <div class="h-16 flex items-center justify-center border-b">
        <h1 class="text-xl font-bold text-gray-800">POS Desktop</h1>
      </div>
      <nav class="flex-1 p-4 space-y-2">
        <router-link to="/" class="block px-4 py-2 rounded-md hover:bg-gray-100 text-gray-700 font-medium" active-class="bg-blue-50 text-blue-700">Dashboard</router-link>
        <router-link to="/pos" class="block px-4 py-2 rounded-md hover:bg-gray-100 text-gray-700 font-medium" active-class="bg-blue-50 text-blue-700">Point of Sale</router-link>
        <router-link to="/settings" class="block px-4 py-2 rounded-md hover:bg-gray-100 text-gray-700 font-medium" active-class="bg-blue-50 text-blue-700">Settings</router-link>
      </nav>
      <div class="p-4 border-t">
        <button @click="logout" class="w-full text-left px-4 py-2 text-red-600 hover:bg-red-50 rounded-md font-medium">Logout</button>
      </div>
    </aside>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col overflow-hidden">
      <!-- Header Placeholder -->
      <header class="h-16 bg-white border-b flex items-center px-6 justify-between md:justify-end">
        <button class="md:hidden text-gray-500">Menu</button>
        <div class="flex items-center space-x-4">
          <span class="text-sm text-gray-600 font-medium">{{ authStore.user?.username || 'User' }}</span>
        </div>
      </header>

      <!-- Page Content -->
      <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 p-6">
        <router-view></router-view>
      </main>
    </div>
  </div>
</template>

<script setup>
import { useAuthStore } from '../stores/auth';
import apiClient from '../api/client';
import { useRouter } from 'vue-router';

const authStore = useAuthStore();
const router = useRouter();

async function logout() {
  try {
    await apiClient.post('/auth/logout');
  } catch (error) {
    console.error('Logout failed on server', error);
  } finally {
    authStore.clearAuth();
    router.push({ name: 'Login' });
  }
}
</script>
