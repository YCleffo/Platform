<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAdminResourceConfigs } from './adminConfigs';
import AdminResourceView from './AdminResourceView.vue';
import RbacMatrixView from './rbac/RbacMatrixView.vue';
import AccountRoleAssignmentDialog from './rbac/AccountRoleAssignmentDialog.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';

const { t } = useI18n();
const configs = useAdminResourceConfigs();
const rolesConfig = computed(() => configs.value.roles!);
const permissionsConfig = computed(() => configs.value.permissions!);
const tab = ref<'roles' | 'permissions' | 'matrix'>('roles');
const showAssignDialog = ref(false);

const tabs = computed(() => [
  { key: 'roles' as const, label: rolesConfig.value.title },
  { key: 'permissions' as const, label: permissionsConfig.value.title },
  { key: 'matrix' as const, label: t('admin.rbacMatrixTab') },
]);
</script>

<template>
  <section class="admin-tabs">
    <div class="admin-tabs__header">
      <nav
        class="admin-tabs__nav"
        :aria-label="$t('admin.rbac')"
      >
        <button
          v-for="item in tabs"
          :key="item.key"
          type="button"
          :class="{ 'admin-tabs__tab--active': tab === item.key }"
          @click="tab = item.key"
        >
          {{ item.label }}
        </button>
      </nav>
      <AppButton
        variant="secondary"
        size="m"
        @click="showAssignDialog = true"
      >
        {{ $t('admin.assignRolesToEmployee') }}
      </AppButton>
    </div>

    <RbacMatrixView v-if="tab === 'matrix'" />
    <AdminResourceView
      v-else-if="tab === 'roles'"
      :config="rolesConfig"
    />
    <AdminResourceView
      v-else
      :config="permissionsConfig"
    />

    <AccountRoleAssignmentDialog
      :open="showAssignDialog"
      @close="showAssignDialog = false"
    />
  </section>
</template>

<style scoped>
.admin-tabs {
  display: grid;
  gap: var(--space-16);
}
.admin-tabs__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-12);
}
.admin-tabs__nav {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-4);
  padding: var(--space-4);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--surface);
}
.admin-tabs__nav button {
  min-height: 36px;
  padding: 0 var(--space-12);
  border-radius: var(--radius-control-s);
  border: none;
  background: transparent;
  color: var(--text-2);
  cursor: pointer;
  font-weight: var(--type-body-medium-emphasis-weight);
  transition: var(--theme-transition);
}
.admin-tabs__nav button:hover {
  color: var(--text-1);
}
.admin-tabs__tab--active {
  background: var(--selected-surface) !important;
  color: var(--text-1) !important;
  font-weight: var(--type-body-large-emphasis-weight) !important;
}
</style>
