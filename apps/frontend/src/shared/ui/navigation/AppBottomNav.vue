<script setup lang="ts">
import type { Component } from 'vue';
import { useRoute } from 'vue-router';

export interface BottomNavItem {
  label: string;
  icon: Component;
  routeName: string;
}

defineProps<{ items: BottomNavItem[] }>();

const route = useRoute();
</script>

<template>
  <nav class="app-bottom-nav" aria-label="Primary">
    <ul class="app-bottom-nav__list">
      <li v-for="item in items" :key="item.routeName" class="app-bottom-nav__item">
        <RouterLink
          class="app-bottom-nav__link"
          :class="{ 'app-bottom-nav__link--active': route.name === item.routeName }"
          :to="{ name: item.routeName }"
          :aria-current="route.name === item.routeName ? 'page' : undefined"
        >
          <span class="app-bottom-nav__icon" aria-hidden="true">
            <component :is="item.icon" :size="22" />
          </span>
          <span class="app-bottom-nav__label">{{ item.label }}</span>
        </RouterLink>
      </li>
    </ul>
  </nav>
</template>

<style scoped>
.app-bottom-nav {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 900;
  background: var(--surface);
  border-top: 1px solid var(--border);
  padding-bottom: env(safe-area-inset-bottom);
}

.app-bottom-nav__list {
  display: flex;
  height: var(--bottom-nav-h);
}

.app-bottom-nav__item {
  flex: 1;
  min-width: 0;
}

.app-bottom-nav__link {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--space-4);
  height: 100%;
  color: var(--text-3);
  transition: color 120ms ease;
}

.app-bottom-nav__link--active {
  color: var(--accent);
}

.app-bottom-nav__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 28px;
  border-radius: var(--radius-badge-pill);
}

.app-bottom-nav__link--active .app-bottom-nav__icon {
  background: var(--accent-soft);
}

.app-bottom-nav__label {
  font-size: 11px;
  line-height: 14px;
  font-weight: 500;
}

@media (min-width: 768px) {
  .app-bottom-nav {
    display: none;
  }
}
</style>
