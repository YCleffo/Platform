import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './app/router';
import { i18n } from './shared/i18n';
import { initTheme } from './app/theme';
import { setUnauthorizedHandler } from './core/auth/session';
import { useAuthStore } from './core/auth/authStore';
import './shared/styles/tokens.css';
import './shared/styles/theme.css';
import './shared/styles/typography.css';
import './shared/styles/base.css';

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);
app.use(i18n);

initTheme();
document.documentElement.lang = String(i18n.global.locale.value);

setUnauthorizedHandler(() => {
  const auth = useAuthStore();
  auth.reset();
  void router.push({ name: 'login' });
});

app.mount('#app');
