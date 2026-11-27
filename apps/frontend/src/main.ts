import { createApp, watch } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './app/router';
import { i18n, initLocale } from './shared/i18n';
import { initTheme } from './app/theme';
import { initAppearance } from './app/appearance';
import { loadPlatformBranding } from './shared/branding';
import { sessionEpoch, setUnauthorizedHandler } from './core/auth/session';
import { useAuthStore } from './core/auth/authStore';
import { coreAdminSessionEpoch } from './core/admin/session';
import { clearToasts } from './shared/composables/useToast';
import { mediaController } from './shared/media/mediaController';
import './shared/styles/tokens.css';
import './shared/styles/theme.css';
import './shared/styles/fonts.css';
import './shared/styles/typography.css';
import './shared/styles/base.css';
import './shared/styles/appearance.css';

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);
app.use(i18n);

initAppearance();
initTheme();
initLocale();
mediaController.bindRouter(router);
void loadPlatformBranding().catch(() => undefined);

setUnauthorizedHandler(() => {
  useAuthStore(pinia).reset();
  mediaController.reset();
  clearToasts();
  void router.push({ name: 'login' });
});

// Any session boundary invalidates transient notifications from the previous user.
watch(sessionEpoch, () => {
  clearToasts();
  mediaController.reset();
});
watch(coreAdminSessionEpoch, () => {
  clearToasts();
  mediaController.reset();
}, { flush: 'sync' });

app.mount('#app');
