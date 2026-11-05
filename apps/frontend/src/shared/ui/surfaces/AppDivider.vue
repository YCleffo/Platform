<script setup lang="ts">
withDefaults(defineProps<{ vertical?: boolean }>(), { vertical: false });
</script>

<template>
  <div
    class="app-divider"
    :class="{ 'app-divider--vertical': vertical }"
    role="separator"
  />
</template>

<style scoped>
.app-divider {
  height: 1px;
  width: 100%;
  background: var(--border);
  transition: var(--theme-transition);
}

.app-divider--vertical {
  width: 1px;
  height: auto;
  align-self: stretch;
}
</style>
