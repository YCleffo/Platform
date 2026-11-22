<script setup lang="ts">
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, RouterView, useRoute } from 'vue-router';
const route = useRoute();
const { locale } = useI18n();
const tabs = ref<HTMLElement | null>(null);
function revealCurrentTab(): void {
  const nav = tabs.value;
  const active = nav?.querySelector<HTMLElement>('[aria-current="page"]');
  if (!nav || !active) return;
  const outer = nav.getBoundingClientRect();
  const inner = active.getBoundingClientRect();
  if (inner.left < outer.left) nav.scrollLeft -= outer.left - inner.left;
  else if (inner.right > outer.right) nav.scrollLeft += inner.right - outer.right;
}
watch([() => route.name, locale], revealCurrentTab, { flush: 'post' });
watch(
  tabs,
  (nav, _, onCleanup) => {
    if (!nav) return;
    revealCurrentTab();
    if (typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(revealCurrentTab);
    observer.observe(nav);
    onCleanup(() => observer.disconnect());
  },
  { flush: 'post' },
);
</script>

<template>
  <section class="core-security">
    <nav
      ref="tabs"
      class="core-security__tabs"
      :aria-label="$t('admin.security')"
    >
      <RouterLink
        :to="{ name: 'admin-security' }"
        exact-active-class="is-current"
      >
        {{ $t('security.passwordTitle') }}
      </RouterLink>
      <RouterLink
        :to="{ name: 'admin-security-mfa' }"
        exact-active-class="is-current"
      >
        {{ $t('security.mfaTitle') }}
      </RouterLink>
      <RouterLink
        :to="{ name: 'admin-security-sessions' }"
        exact-active-class="is-current"
      >
        {{ $t('coreSecurity.sessionsTitle') }}
      </RouterLink>
      <RouterLink
        :to="{ name: 'admin-security-passkeys' }"
        exact-active-class="is-current"
      >
        {{ $t('coreCredentials.passkeysTitle') }}
      </RouterLink>
      <RouterLink
        :to="{ name: 'admin-security-identities' }"
        exact-active-class="is-current"
      >
        {{ $t('coreCredentials.identitiesTitle') }}
      </RouterLink>
    </nav>
    <RouterView />
  </section>
</template>

<style scoped>
.core-security {
  display: grid;
  gap: var(--space-16);
}
.core-security__tabs {
  display: flex;
  flex-wrap: nowrap;
  gap: var(--space-8);
  overflow-x: auto;
  min-width: 0;
  scrollbar-width: thin;
}
.core-security__tabs a {
  display: inline-flex;
  align-items: center;
  flex-shrink: 0;
  min-height: var(--button-height-m);
  white-space: nowrap;
  padding: var(--space-8) var(--space-12);
  border-radius: var(--radius-control-m);
  color: var(--text-2);
  transition: var(--theme-transition);
}
.core-security__tabs .is-current {
  color: var(--link);
  background: var(--accent-soft);
}
</style>
