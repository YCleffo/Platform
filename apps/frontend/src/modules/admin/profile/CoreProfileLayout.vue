<script setup lang="ts">
import { RouterLink, RouterView, useRoute } from 'vue-router';
const route = useRoute();
</script>
<template>
  <section class="core-profile-layout">
    <nav :aria-label="$t('coreProfile.title')">
      <RouterLink
        :to="{ name: 'admin-profile' }"
        exact-active-class="is-current"
      >
        {{ $t('coreProfile.preferences') }}
      </RouterLink>
      <RouterLink
        :to="{ name: 'admin-profile-privacy' }"
        :class="{ 'is-current': String(route.name ?? '').startsWith('admin-profile-privacy') }"
      >
        {{ $t('corePrivacy.title') }}
      </RouterLink>
      <RouterLink
        :to="{ name: 'admin-profile-consents' }"
        exact-active-class="is-current"
      >
        {{ $t('coreProfile.consents') }}
      </RouterLink>
    </nav>
    <RouterView />
  </section>
</template>
<style scoped>
.core-profile-layout {
  display: grid;
  gap: var(--space-16);
}
.core-profile-layout nav {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}
.core-profile-layout a {
  padding: var(--space-8) var(--space-12);
  border-radius: var(--radius-control-m);
  color: var(--text-2);
  transition: var(--theme-transition);
}
.core-profile-layout .is-current {
  color: var(--link);
  background: var(--accent-soft);
}
</style>
