<script setup lang="ts">
withDefaults(defineProps<{ sticky?: boolean }>(), { sticky: true });
</script>

<template>
  <header
    class="app-header"
    :class="{ 'app-header--sticky': sticky }"
  >
    <div class="app-header__inner">
      <div class="app-header__leading">
        <slot name="leading" />
      </div>
      <div class="app-header__center">
        <slot name="center" />
      </div>
      <div class="app-header__actions">
        <slot name="actions" />
      </div>
    </div>
  </header>
</template>

<style scoped>
.app-header {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  transition: var(--theme-transition);
}

.app-header--sticky {
  position: sticky;
  top: 0;
  z-index: 800;
}

.app-header__inner {
  display: flex;
  align-items: center;
  gap: var(--space-12);
  min-height: 60px;
  padding: var(--space-8) var(--page-pad-x);
}

.app-header__leading {
  display: flex;
  align-items: center;
  min-width: 0;
  flex: 0 0 auto;
}

.app-header__center {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

.app-header__actions {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  flex: 0 0 auto;
}

@media (min-width: 768px) {
  .app-header__inner {
    min-height: 68px;
    gap: var(--space-24);
    padding: var(--space-12) var(--space-32);
  }

  .app-header__center {
    justify-content: flex-start;
  }
}
</style>
