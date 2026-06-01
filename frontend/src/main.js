import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { createRouter, createWebHashHistory } from 'vue-router';

import App from './App.vue';
import './assets/main.css';

/**
 * SPA bootstrap.
 *
 * Pinia and vue-router are registered here so that subsequent tasks can
 * register stores and route modules without re-touching the entry file.
 * The hash history mode is used because Electron loads the SPA from a
 * `file://` or `http://127.0.0.1:8000` origin where a server-side fallback
 * to `index.html` is not always guaranteed.
 *
 * The full router (mirroring `routes/web.php`) is built in task 9.6;
 * for now we register a single placeholder route so `<router-view />`
 * has something to mount.
 */
const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    {
      path: '/',
      name: 'home',
      component: () => import('./views/HomeView.vue'),
    },
  ],
});

const app = createApp(App);
app.use(createPinia());
app.use(router);
app.mount('#app');
