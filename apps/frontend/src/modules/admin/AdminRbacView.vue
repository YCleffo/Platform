<script setup lang="ts">
import { computed, ref } from 'vue';
import { useAdminResourceConfigs } from './adminConfigs';
import AdminResourceView from './AdminResourceView.vue';

const configs = useAdminResourceConfigs();
const rolesConfig = computed(() => configs.value.roles!);
const permissionsConfig = computed(() => configs.value.permissions!);
const tab = ref<'roles' | 'permissions'>('roles');
const tabs = computed(() => [
  { key: 'roles' as const, label: rolesConfig.value.title },
  { key: 'permissions' as const, label: permissionsConfig.value.title },
]);
</script>

<template>
  <section class="admin-tabs">
    <nav class="admin-tabs__nav" :aria-label="$t('admin.rbac')">
      <button v-for="item in tabs" :key="item.key" type="button" :class="{ 'admin-tabs__tab--active': tab === item.key }" @click="tab = item.key">{{ item.label }}</button>
    </nav>
    <AdminResourceView v-if="tab === 'roles'" :config="rolesConfig" />
    <AdminResourceView v-else :config="permissionsConfig" />
  </section>
</template>

<style scoped>
.admin-tabs { display: grid; gap: var(--space-16); }
.admin-tabs__nav { display: flex; flex-wrap: wrap; gap: var(--space-4); padding: var(--space-4); border: 1px solid var(--border); border-radius: var(--radius-control-m); background: var(--surface); }
.admin-tabs__nav button { min-height: 36px; padding: 0 var(--space-12); border-radius: var(--radius-control-s); color: var(--text-2); font-size: var(--type-body-small-emphasis-size); font-weight: var(--type-body-small-emphasis-weight); }
.admin-tabs__nav button.admin-tabs__tab--active { background: var(--accent-soft); color: var(--accent-text); }
</style>
