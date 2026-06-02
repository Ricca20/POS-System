<template>
  <form @submit.prevent="handleLogin" class="space-y-6">
    <div>
      <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
      <div class="mt-1">
        <input v-model="username" id="username" name="username" type="text" required class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" />
      </div>
    </div>

    <div>
      <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
      <div class="mt-1">
        <input v-model="password" id="password" name="password" type="password" required class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" />
      </div>
    </div>

    <div v-if="errorMsg" class="text-sm text-red-600 bg-red-50 p-2 rounded">
      {{ errorMsg }}
    </div>

    <div>
      <button type="submit" :disabled="isLoading" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50">
        <span v-if="isLoading">Signing in...</span>
        <span v-else>Sign in</span>
      </button>
    </div>
  </form>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import apiClient from '../api/client';
import { useAuthStore } from '../stores/auth';
import { useBusinessStore } from '../stores/business';

const username = ref('');
const password = ref('');
const isLoading = ref(false);
const errorMsg = ref('');

const router = useRouter();
const authStore = useAuthStore();
const businessStore = useBusinessStore();

async function handleLogin() {
  isLoading.value = true;
  errorMsg.value = '';
  try {
    const response = await apiClient.post('/auth/login', {
      username: username.value,
      password: password.value,
    });
    
    authStore.setAuth(response.data.access_token, response.data.user);
    
    // Fetch initial business configs
    await businessStore.fetchSettings();

    router.push({ name: 'Dashboard' });
  } catch (error) {
    if (error.response && error.response.data.message) {
      errorMsg.value = error.response.data.message;
    } else {
      errorMsg.value = 'Failed to connect to the server.';
    }
  } finally {
    isLoading.value = false;
  }
}
</script>
