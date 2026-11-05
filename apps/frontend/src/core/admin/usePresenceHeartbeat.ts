import { onBeforeUnmount, onMounted, watch } from 'vue';
import { useAdminAuthStore } from './adminAuthStore';
import { useCoreAccountSettings } from './accountSettingsStore';
import { useCorePresence } from './presenceStore';
import { coreAdminSessionEpoch } from './session';

export function usePresenceHeartbeat(): void {
  const auth = useAdminAuthStore(); const settings = useCoreAccountSettings(); const presence = useCorePresence();
  function update(): void {
    presence.configure(auth.isAuthenticated && !!auth.identity?.account.personId && settings.value?.settings.privacy.show_online_status === true,
      document.visibilityState === 'visible' && navigator.onLine);
  }
  watch([() => auth.isAuthenticated, () => settings.value?.settings.privacy.show_online_status, coreAdminSessionEpoch], update, { immediate: true, flush: 'sync' });
  onMounted(() => { document.addEventListener('visibilitychange', update); window.addEventListener('online', update); window.addEventListener('offline', update); });
  onBeforeUnmount(() => { document.removeEventListener('visibilitychange', update); window.removeEventListener('online', update); window.removeEventListener('offline', update); presence.stop(); });
}
