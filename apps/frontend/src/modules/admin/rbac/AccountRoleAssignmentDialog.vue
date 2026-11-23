<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  coreRbacApi,
  type AccountRoleAssignment,
  type CoreRole,
} from '@/shared/api/core/rbac';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useToast } from '@/shared/composables/useToast';
import { formatDateTime } from '@/shared/formatting/date';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';

interface RoleAuditRecord {
  id: string;
  roleName: string;
  action: 'assigned' | 'revoked';
  performedBy: string;
  timestamp: string;
}

const props = defineProps<{
  open: boolean;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
}>();

const { t } = useI18n();
const toast = useToast();

const searchAccountId = ref('');
const activeAccountId = ref('');
const loading = ref(false);
const saving = ref(false);
const error = ref('');

const availableRoles = ref<CoreRole[]>([]);
const assignedRoles = ref<AccountRoleAssignment[]>([]);
const auditLog = ref<RoleAuditRecord[]>([]);

const selectedRoleId = ref('');

async function loadAvailableRoles(): Promise<void> {
  try {
    availableRoles.value = await coreRbacApi.listRoles();
  } catch {
    // Non-blocking
  }
}

async function searchAccount(): Promise<void> {
  const accountId = searchAccountId.value.trim();
  if (!accountId) return;

  loading.value = true;
  error.value = '';
  activeAccountId.value = accountId;
  try {
    const roles = await coreRbacApi.getAccountRoles(accountId);
    assignedRoles.value = roles;
    // Build audit representation
    auditLog.value = roles.map((r: AccountRoleAssignment) => ({
      id: `${r.roleId}-${r.assignedAt}`,
      roleName: r.roleName,
      action: 'assigned',
      performedBy: r.assignedBy || 'Admin System',
      timestamp: r.assignedAt,
    }));
  } catch (caught) {
    error.value = resolveErrorMessage(caught);
  } finally {
    loading.value = false;
  }
}

async function assignRole(): Promise<void> {
  if (!selectedRoleId.value || !activeAccountId.value) return;

  const role = availableRoles.value.find((r) => r.id === selectedRoleId.value);
  saving.value = true;
  try {
    const assignment = await coreRbacApi.assignAccountRole(
      activeAccountId.value,
      selectedRoleId.value,
    );
    assignedRoles.value.push(assignment);
    auditLog.value.unshift({
      id: `${assignment.roleId}-${Date.now()}`,
      roleName: assignment.roleName || role?.name || 'Role',
      action: 'assigned',
      performedBy: 'Current Administrator',
      timestamp: new Date().toISOString(),
    });
    selectedRoleId.value = '';
    toast.success(t('admin.roleAssigned'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

async function revokeRole(assignment: AccountRoleAssignment): Promise<void> {
  if (!activeAccountId.value) return;

  saving.value = true;
  try {
    await coreRbacApi.revokeAccountRole(activeAccountId.value, assignment.roleId);
    assignedRoles.value = assignedRoles.value.filter(
      (r) => r.roleId !== assignment.roleId,
    );
    auditLog.value.unshift({
      id: `${assignment.roleId}-${Date.now()}`,
      roleName: assignment.roleName,
      action: 'revoked',
      performedBy: 'Current Administrator',
      timestamp: new Date().toISOString(),
    });
    toast.success(t('admin.roleRevoked'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

const dialogOpen = computed({
  get: () => props.open,
  set: (val: boolean) => {
    if (!val) emit('close');
  },
});

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      void loadAvailableRoles();
    } else {
      searchAccountId.value = '';
      activeAccountId.value = '';
      assignedRoles.value = [];
      auditLog.value = [];
      error.value = '';
    }
  },
);
</script>

<template>
  <AppDialog
    v-model="dialogOpen"
    :title="$t('admin.manageAccountRolesTitle')"
    size="l"
  >
    <div class="role-assign">
      <!-- Search Account -->
      <form
        class="role-assign__search-form"
        @submit.prevent="searchAccount"
      >
        <AppInput
          id="role-assign-search-account"
          v-model="searchAccountId"
          :label="$t('admin.searchAccountIdLabel')"
          placeholder="UUID аккаунта (e.g. 01a204bc-f94d-7bac-...)"
          required
        />
        <AppButton
          variant="primary"
          type="submit"
          :loading="loading"
        >
          {{ $t('actions.search') }}
        </AppButton>
      </form>

      <AppErrorState
        v-if="error"
        :message="error"
      />

      <div
        v-else-if="loading"
        class="role-assign__loading"
      >
        <AppSkeleton height="150px" />
      </div>

      <template v-else-if="activeAccountId">
        <!-- Assign New Role -->
        <div class="role-assign__section">
          <h3 class="role-assign__section-title">
            {{ $t('admin.assignRoleHeading') }}
          </h3>
          <div class="role-assign__picker">
            <select
              v-model="selectedRoleId"
              class="role-assign__select"
              :aria-label="$t('admin.selectRole')"
            >
              <option
                value=""
                disabled
              >
                {{ $t('admin.selectRolePlaceholder') }}
              </option>
              <option
                v-for="role in availableRoles"
                :key="role.id"
                :value="role.id"
                :disabled="assignedRoles.some((a) => a.roleId === role.id)"
              >
                {{ role.name }} ({{ role.code }})
              </option>
            </select>
            <AppButton
              variant="primary"
              size="m"
              :disabled="!selectedRoleId || saving"
              :loading="saving"
              @click="assignRole"
            >
              {{ $t('actions.assign') }}
            </AppButton>
          </div>
        </div>

        <!-- Current Roles -->
        <div class="role-assign__section">
          <h3 class="role-assign__section-title">
            {{ $t('admin.activeAccountRoles') }}
          </h3>
          <div
            v-if="assignedRoles.length > 0"
            class="role-assign__tags"
          >
            <div
              v-for="assignment in assignedRoles"
              :key="assignment.roleId"
              class="role-assign__tag-item"
            >
              <span class="role-assign__tag-name">{{ assignment.roleName }}</span>
              <button
                type="button"
                class="role-assign__tag-remove"
                :aria-label="$t('actions.revoke')"
                :disabled="saving"
                @click="revokeRole(assignment)"
              >
                &times;
              </button>
            </div>
          </div>
          <AppEmptyState
            v-else
            :title="$t('admin.noRolesAssigned')"
            :subtitle="$t('admin.noRolesAssignedDesc')"
          />
        </div>

        <!-- Audit Log -->
        <div class="role-assign__section">
          <h3 class="role-assign__section-title">
            {{ $t('admin.roleAuditLog') }}
          </h3>
          <div
            v-if="auditLog.length > 0"
            class="role-assign__audit-list"
          >
            <div
              v-for="log in auditLog"
              :key="log.id"
              class="role-assign__audit-item"
            >
              <AppBadge
                :variant="log.action === 'assigned' ? 'success' : 'danger'"
                size="s"
              >
                {{ log.action === 'assigned' ? $t('admin.assigned') : $t('admin.revoked') }}
              </AppBadge>
              <span class="role-assign__audit-role">{{ log.roleName }}</span>
              <span class="role-assign__audit-by">{{ log.performedBy }}</span>
              <time class="role-assign__audit-time">{{ formatDateTime(log.timestamp) }}</time>
            </div>
          </div>
          <p
            v-else
            class="role-assign__empty-text"
          >
            {{ $t('admin.noAuditRecords') }}
          </p>
        </div>
      </template>

      <div class="role-assign__footer">
        <AppButton
          variant="secondary"
          @click="emit('close')"
        >
          {{ $t('common.close') }}
        </AppButton>
      </div>
    </div>
  </AppDialog>
</template>

<style scoped>
.role-assign {
  display: grid;
  gap: var(--space-20);
}

.role-assign__search-form {
  display: flex;
  gap: var(--space-12);
  align-items: flex-end;
}

.role-assign__section {
  display: grid;
  gap: var(--space-12);
  padding-top: var(--space-12);
  border-top: 1px solid var(--border);
}

.role-assign__section-title {
  font-size: var(--type-body-small-emphasis-size);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-2);
  text-transform: uppercase;
  margin: 0;
}

.role-assign__picker {
  display: flex;
  gap: var(--space-12);
  align-items: center;
}

.role-assign__select {
  flex: 1;
  min-height: 40px;
  padding: 0 var(--space-12);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  color: var(--text-1);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
}

.role-assign__tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}

.role-assign__tag-item {
  display: inline-flex;
  align-items: center;
  gap: var(--space-8);
  padding: var(--space-4) var(--space-12);
  background: var(--selected-surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-1);
}

.role-assign__tag-remove {
  background: transparent;
  border: none;
  font-size: var(--type-h2-size);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  color: var(--text-2);
  cursor: pointer;
}

.role-assign__tag-remove:hover {
  color: var(--danger);
}

.role-assign__audit-list {
  display: grid;
  gap: var(--space-8);
}

.role-assign__audit-item {
  display: flex;
  align-items: center;
  gap: var(--space-12);
  padding: var(--space-8) var(--space-12);
  background: var(--surface-2);
  border-radius: var(--radius-control-s);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.role-assign__audit-role {
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-1);
}

.role-assign__audit-by {
  color: var(--text-2);
  flex: 1;
}

.role-assign__audit-time {
  color: var(--text-2);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}

.role-assign__empty-text {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-2);
  margin: 0;
}

.role-assign__footer {
  display: flex;
  justify-content: flex-end;
  margin-top: var(--space-8);
}
</style>
