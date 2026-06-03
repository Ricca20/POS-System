import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import './style.css'; // Assuming Tailwind is configured here
import apiClient from './api/client';

async function bootstrap() {
  if (window.electronAPI && window.electronAPI.getApiUrl) {
    try {
      const apiUrl = await window.electronAPI.getApiUrl();
      if (apiUrl) {
        apiClient.defaults.baseURL = apiUrl;
      }
    } catch (e) {
      console.error("Failed to fetch API URL from Electron:", e);
    }
  }

  const app = createApp(App);

  app.use(createPinia());
  app.use(router);

  app.mount('#app');
}

bootstrap();
