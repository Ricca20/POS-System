import { defineStore } from 'pinia';
import { ref } from 'vue';
import apiClient from '../api/client';

export const useBusinessStore = defineStore('business', () => {
  const settings = ref(null);
  const locations = ref([]);
  const isLoading = ref(false);

  async function fetchSettings() {
    isLoading.value = true;
    try {
      const response = await apiClient.get('/config');
      settings.value = response.data.data;
      locations.value = response.data.data.locations || [];
    } catch (error) {
      console.error('Failed to fetch business config', error);
    } finally {
      isLoading.value = false;
    }
  }

  return { settings, locations, isLoading, fetchSettings };
});
