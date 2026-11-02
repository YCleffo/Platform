<script setup lang="ts">
import { computed, ref } from 'vue';
import { useAdminResourceConfigs } from './adminConfigs';
import AdminResourceView from './AdminResourceView.vue';

const configs = useAdminResourceConfigs();
const systemConfig = computed(() => configs.value.settings!);
const flagsConfig = computed(() => configs.value.flags!);
const tab = ref<'settings' | 'flags'>('settings');
const tabs = computed(() => [
  { key: 'settings' as const, label: systemConfig.value.title },
  { key: 'flags' as const, label: flagsConfig.value.title },
]);
</script>

<template>
  <section class="admin-tabs">
    <nav class="admin-tabs__nav" :aria-label="$t('admin.settings')">
      <button v-for="item in tabs" :key="item.key" type="button" :class="{ 'admin-tabs__tab--active': tab === item.key }" @click="tab = item.key">{{ item.label }}</button>
    </nav>
    <AdminResourceView v-if="tab === 'settings'" :config="systemConfig" />
    <AdminResourceView v-else :config="flagsConfig" />
  </section>
</template>

<style scoped>
.admin-tabs { display: grid; gap: var(--space-16); }
.admin-tabs__nav { display: flex; flex-wrap: wrap; gap: var(--space-4); padding: var(--space-4); border: 1px solid var(--border); border-radius: var(--radius-control-m); background: var(--surface); }
.admin-tabs__nav button { min-height: 36px; padding: 0 var(--space-12); border-radius: var(--radius-control-s); color: var(--text-2); font-size: var(--type-body-small-emphasis-size); font-weight: var(--type-body-small-emphasis-weight); }
.admin-tabs__nav button.admin-tabs__tab--active { background: var(--accent-soft); color: var(--accent-text); }
</style>
