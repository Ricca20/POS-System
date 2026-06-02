import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import router from './router';
import './style.css'; // Assuming Tailwind is configured here

const app = createApp(App);

app.use(createPinia());
app.use(router);

app.mount('#app');
