<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  coreRbacApi,
  type CorePermission,
  type CoreRole,
} from '@/shared/api/core/rbac';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useToast } from '@/shared/composables/useToast';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';

const { t } = useI18n();
const toast = useToast();

const loading = ref(true);
const error = ref('');
const roles = ref<CoreRole[]>([]);
const permissions = ref<CorePermission[]>([]);
const saving = ref(false);

const showCreateRoleModal = ref(false);
const newRole = ref({
  code: '',
  name: '',
  description: '',
});

// Group permissions by module
const categories = computed(() => {
  const groups: Record<string, CorePermission[]> = {};
  for (const perm of permissions.value) {
    const mod = perm.module || 'core';
    if (!groups[mod]) groups[mod] = [];
    groups[mod].push(perm);
  }
  return groups;
});

async function loadData(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const [rolesData, permsData] = await Promise.all([
      coreRbacApi.listRoles(),
      coreRbacApi.listPermissions(),
    ]);
    roles.value = rolesData;
    permissions.value = permsData;
  } catch (caught) {
    error.value = resolveErrorMessage(caught);
  } finally {
    loading.value = false;
  }
}

function hasPermission(role: CoreRole, permCode: string): boolean {
  return role.permissions.includes(permCode);
}

async function togglePermission(role: CoreRole, permCode: string): Promise<void> {
  if (role.isSystem) return; // System roles cannot be modified

  const current = new Set(role.permissions);
  if (current.has(permCode)) {
    current.delete(permCode);
  } else {
    current.add(permCode);
  }

  const updatedPerms = Array.from(current);
  saving.value = true;
  try {
    await coreRbacApi.setRolePermissions(role.id, updatedPerms);
    role.permissions = updatedPerms;
    toast.success(t('admin.rbacMatrixUpdated', { role: role.name }));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

async function handleCreateRole(): Promise<void> {
  if (!newRole.value.code || !newRole.value.name) return;
  saving.value = true;
  try {
    const created = await coreRbacApi.createRole({
      code: newRole.value.code.toLowerCase().trim(),
      name: newRole.value.name.trim(),
      description: newRole.value.description.trim() || '',
      permissions: [],
    });
    roles.value.push(created);
    showCreateRoleModal.value = false;
    newRole.value = { code: '', name: '', description: '' };
    toast.success(t('admin.roleCreated', { name: created.name }));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  void loadData();
});
</script>

<template>
  <div class="rbac-matrix">
    <header class="rbac-matrix__header">
      <div>
        <h2 class="rbac-matrix__title">
          {{ $t('admin.rbacMatrixTitle') }}
        </h2>
        <p class="rbac-matrix__subtitle">
          {{ $t('admin.rbacMatrixSubtitle') }}
        </p>
      </div>
      <AppButton
        variant="primary"
        size="m"
        @click="showCreateRoleModal = true"
      >
        {{ $t('admin.createRole') }}
      </AppButton>
    </header>

    <AppErrorState
      v-if="error"
      :message="error"
      :action-label="$t('common.retry')"
      @action="loadData"
    />

    <AppCard
      v-else-if="loading"
      class="rbac-matrix__card"
    >
      <AppSkeleton height="400px" />
    </AppCard>

    <AppCard
      v-else
      class="rbac-matrix__card"
    >
      <div class="rbac-matrix__table-wrapper">
        <table class="rbac-matrix__table">
          <thead>
            <tr>
              <th class="rbac-matrix__col-perm">
                {{ $t('admin.permission') }}
              </th>
              <th
                v-for="role in roles"
                :key="role.id"
                class="rbac-matrix__col-role"
              >
                <div class="rbac-matrix__role-header">
                  <span class="rbac-matrix__role-name">{{ role.name }}</span>
                  <AppBadge
                    v-if="role.isSystem"
                    variant="neutral"
                    size="s"
                  >
                    {{ $t('admin.systemRole') }}
                  </AppBadge>
                  <AppBadge
                    v-else
                    variant="info"
                    size="s"
                  >
                    {{ $t('admin.customRole') }}
                  </AppBadge>
                </div>
              </th>
            </tr>
          </thead>
          <tbody>
            <template
              v-for="(perms, moduleKey) in categories"
              :key="moduleKey"
            >
              <tr class="rbac-matrix__category-row">
                <td :colspan="roles.length + 1">
                  <strong>{{ $t(`moduleNames.${moduleKey}`) }}</strong>
                </td>
              </tr>
              <tr
                v-for="perm in perms"
                :key="perm.id"
                class="rbac-matrix__row"
              >
                <td class="rbac-matrix__perm-cell">
                  <div class="rbac-matrix__perm-info">
                    <span class="rbac-matrix__perm-name">{{ perm.name }}</span>
                    <code class="rbac-matrix__perm-code">{{ perm.code }}</code>
                    <span
                      v-if="perm.description"
                      class="rbac-matrix__perm-desc"
                    >
                      {{ perm.description }}
                    </span>
                  </div>
                </td>
                <td
                  v-for="role in roles"
                  :key="role.id"
                  class="rbac-matrix__cell"
                >
                  <label class="rbac-matrix__checkbox-label">
                    <input
                      type="checkbox"
                      :checked="hasPermission(role, perm.code)"
                      :disabled="role.isSystem || saving"
                      :aria-label="`${role.name}: ${perm.name}`"
                      @change="togglePermission(role, perm.code)"
                    >
                    <span
                      class="rbac-matrix__checkbox-status"
                      :class="{ 'rbac-matrix__checkbox-status--active': hasPermission(role, perm.code) }"
                    />
                  </label>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </AppCard>

    <!-- Create Role Modal -->
    <AppDialog
      v-model="showCreateRoleModal"
      :title="$t('admin.createRole')"
    >
      <form
        class="rbac-matrix__form"
        @submit.prevent="handleCreateRole"
      >
        <AppInput
          id="rbac-new-role-code"
          v-model="newRole.code"
          :label="$t('admin.roleCode')"
          placeholder="e.g. billing_auditor"
          required
        />
        <AppInput
          id="rbac-new-role-name"
          v-model="newRole.name"
          :label="$t('admin.roleName')"
          placeholder="e.g. Аудитор биллинга"
          required
        />
        <AppInput
          id="rbac-new-role-desc"
          v-model="newRole.description"
          :label="$t('admin.roleDescription')"
          placeholder="e.g. Право на просмотр истории оплат и инвойсов"
        />
        <div class="rbac-matrix__form-actions">
          <AppButton
            variant="secondary"
            @click="showCreateRoleModal = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton
            variant="primary"
            type="submit"
            :loading="saving"
            :disabled="!newRole.code || !newRole.name"
          >
            {{ $t('admin.create') }}
          </AppButton>
        </div>
      </form>
    </AppDialog>
  </div>
</template>

<style scoped>
.rbac-matrix {
  display: grid;
  gap: var(--space-20);
}

.rbac-matrix__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-12);
}

.rbac-matrix__title {
  color: var(--text-1);
  margin: 0;
}

.rbac-matrix__subtitle {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-2);
  margin: var(--space-4) 0 0 0;
}

.rbac-matrix__card {
  padding: 0;
  overflow: hidden;
}

.rbac-matrix__table-wrapper {
  overflow-x: auto;
  max-width: 100%;
}

.rbac-matrix__table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
}

.rbac-matrix__col-perm {
  width: 38%;
  min-width: 260px;
  padding: var(--space-12) var(--space-16);
  background: var(--surface-2);
  border-bottom: 2px solid var(--border);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  text-transform: uppercase;
  color: var(--text-2);
}

.rbac-matrix__col-role {
  min-width: 130px;
  padding: var(--space-12) var(--space-16);
  background: var(--surface-2);
  border-bottom: 2px solid var(--border);
  text-align: center;
}

.rbac-matrix__role-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-4);
}

.rbac-matrix__role-name {
  font-size: var(--type-body-small-emphasis-size);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-1);
}

.rbac-matrix__category-row td {
  padding: var(--space-8) var(--space-16);
  background: var(--selected-surface);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-2);
  border-top: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
}

.rbac-matrix__row {
  border-bottom: 1px solid var(--border);
  transition: var(--theme-transition);
}

.rbac-matrix__row:hover {
  background: var(--surface-2);
}

.rbac-matrix__perm-cell {
  padding: var(--space-12) var(--space-16);
}

.rbac-matrix__perm-info {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.rbac-matrix__perm-name {
  font-size: var(--type-body-small-emphasis-size);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-1);
}

.rbac-matrix__perm-code {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--accent);
  background: var(--surface-2);
  padding: 2px var(--space-4);
  border-radius: var(--radius-control-s);
  display: inline-block;
  width: fit-content;
}

.rbac-matrix__perm-desc {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
}

.rbac-matrix__cell {
  padding: var(--space-12) var(--space-16);
  text-align: center;
  vertical-align: middle;
}

.rbac-matrix__checkbox-label {
  display: inline-flex;
  cursor: pointer;
  align-items: center;
  justify-content: center;
}

.rbac-matrix__checkbox-label input {
  width: 18px;
  height: 18px;
  cursor: pointer;
}

.rbac-matrix__checkbox-label input:disabled {
  cursor: not-allowed;
  opacity: 0.7;
}

.rbac-matrix__form {
  display: grid;
  gap: var(--space-16);
  padding: var(--space-16) 0;
}

.rbac-matrix__form-actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-12);
  margin-top: var(--space-8);
}
</style>
