<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  platformAdminApi,
  type PlatformOrganization,
  type PlatformAccountSummary,
  type PlatformSuspiciousSession,
  type ServiceHealthItem,
  type OrganizationStatus,
} from '@/shared/api/core/platformAdmin';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useToast } from '@/shared/composables/useToast';
import { formatDateTime } from '@/shared/formatting/date';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';

const { t } = useI18n();
const toast = useToast();

const activeTab = ref<'organizations' | 'accounts' | 'sessions' | 'health'>('organizations');
const loading = ref(true);
const error = ref('');
const saving = ref(false);

// Organizations state
const organizations = ref<PlatformOrganization[]>([]);
const showCreateOrgModal = ref(false);
const newOrg = ref({ name: '', slug: '' });
const showStatusModal = ref(false);
const editingOrg = ref<PlatformOrganization | null>(null);
const selectedStatus = ref<OrganizationStatus>('active');

// Accounts moderation state
const accounts = ref<PlatformAccountSummary[]>([]);
const showModerateModal = ref(false);
const moderatingAccount = ref<PlatformAccountSummary | null>(null);
const moderationAction = ref<'approve' | 'disable' | 'enable' | 'archive'>('approve');
const moderationReason = ref('');

// Suspicious sessions state
const sessions = ref<PlatformSuspiciousSession[]>([]);

// Services health state
const healthItems = ref<ServiceHealthItem[]>([]);
const refreshingHealth = ref(false);

async function loadOrganizations(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    organizations.value = await platformAdminApi.listOrganizations();
  } catch (caught) {
    error.value = resolveErrorMessage(caught);
  } finally {
    loading.value = false;
  }
}

async function loadAccounts(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    accounts.value = await platformAdminApi.listAccounts();
  } catch (caught) {
    error.value = resolveErrorMessage(caught);
  } finally {
    loading.value = false;
  }
}

async function loadSessions(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    sessions.value = await platformAdminApi.listSuspiciousSessions();
  } catch (caught) {
    error.value = resolveErrorMessage(caught);
  } finally {
    loading.value = false;
  }
}

async function loadHealth(): Promise<void> {
  refreshingHealth.value = true;
  try {
    healthItems.value = await platformAdminApi.getServicesHealth();
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    refreshingHealth.value = false;
  }
}

function handleTabChange(tab: 'organizations' | 'accounts' | 'sessions' | 'health'): void {
  activeTab.value = tab;
  if (tab === 'organizations') void loadOrganizations();
  else if (tab === 'accounts') void loadAccounts();
  else if (tab === 'sessions') void loadSessions();
  else if (tab === 'health') void loadHealth();
}

async function handleCreateOrg(): Promise<void> {
  if (!newOrg.value.name.trim() || !newOrg.value.slug.trim()) return;
  saving.value = true;
  try {
    const created = await platformAdminApi.createOrganization({
      name: newOrg.value.name.trim(),
      slug: newOrg.value.slug.toLowerCase().trim(),
    });
    organizations.value.unshift(created);
    showCreateOrgModal.value = false;
    newOrg.value = { name: '', slug: '' };
    toast.success(t('platformAdmin.orgCreated'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

function openStatusModal(org: PlatformOrganization): void {
  editingOrg.value = org;
  selectedStatus.value = org.status;
  showStatusModal.value = true;
}

async function handleUpdateOrgStatus(): Promise<void> {
  if (!editingOrg.value) return;
  saving.value = true;
  try {
    const updated = await platformAdminApi.updateOrganization(editingOrg.value.id, {
      status: selectedStatus.value,
    });
    const idx = organizations.value.findIndex((o) => o.id === editingOrg.value!.id);
    if (idx !== -1) {
      organizations.value[idx] = updated;
    }
    showStatusModal.value = false;
    toast.success(t('platformAdmin.orgUpdated'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

function openModerationModal(
  account: PlatformAccountSummary,
  action: 'approve' | 'disable' | 'enable' | 'archive',
): void {
  moderatingAccount.value = account;
  moderationAction.value = action;
  moderationReason.value = '';
  showModerateModal.value = true;
}

async function handleModerateAccount(): Promise<void> {
  if (!moderatingAccount.value) return;
  saving.value = true;
  try {
    const updated = await platformAdminApi.moderateAccount(moderatingAccount.value.id, {
      action: moderationAction.value,
      reason: moderationReason.value.trim() || undefined,
    });
    const idx = accounts.value.findIndex((a) => a.id === moderatingAccount.value!.id);
    if (idx !== -1) {
      accounts.value[idx] = updated;
    }
    showModerateModal.value = false;
    toast.success(t('platformAdmin.statusUpdated'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

async function handleRevokeSession(session: PlatformSuspiciousSession): Promise<void> {
  saving.value = true;
  try {
    await platformAdminApi.revokeSession(session.id);
    sessions.value = sessions.value.filter((s) => s.id !== session.id);
    toast.success(t('platformAdmin.sessionRevoked'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  void loadOrganizations();
});
</script>

<template>
  <div class="superadmin">
    <header class="superadmin__header">
      <div class="superadmin__intro">
        <h1 class="superadmin__title">
          {{ $t('platformAdmin.title') }}
        </h1>
        <p class="superadmin__subtitle">
          {{ $t('platformAdmin.subtitle') }}
        </p>
      </div>

      <nav
        class="superadmin__tabs"
        :aria-label="$t('platformAdmin.title')"
      >
        <button
          type="button"
          class="superadmin__tab-btn"
          :class="{ 'superadmin__tab-btn--active': activeTab === 'organizations' }"
          @click="handleTabChange('organizations')"
        >
          {{ $t('platformAdmin.organizations') }}
        </button>
        <button
          type="button"
          class="superadmin__tab-btn"
          :class="{ 'superadmin__tab-btn--active': activeTab === 'accounts' }"
          @click="handleTabChange('accounts')"
        >
          {{ $t('platformAdmin.moderation') }}
        </button>
        <button
          type="button"
          class="superadmin__tab-btn"
          :class="{ 'superadmin__tab-btn--active': activeTab === 'sessions' }"
          @click="handleTabChange('sessions')"
        >
          {{ $t('platformAdmin.suspiciousSessions') }}
        </button>
        <button
          type="button"
          class="superadmin__tab-btn"
          :class="{ 'superadmin__tab-btn--active': activeTab === 'health' }"
          @click="handleTabChange('health')"
        >
          {{ $t('platformAdmin.healthStatus') }}
        </button>
      </nav>
    </header>

    <!-- TAB 1: ORGANIZATIONS -->
    <div
      v-if="activeTab === 'organizations'"
      class="superadmin__pane"
    >
      <div class="superadmin__actions">
        <AppButton
          variant="primary"
          size="m"
          @click="showCreateOrgModal = true"
        >
          {{ $t('platformAdmin.createOrg') }}
        </AppButton>
      </div>

      <AppErrorState
        v-if="error"
        :message="error"
        :action-label="$t('common.retry')"
        @action="loadOrganizations"
      />

      <AppCard
        v-else-if="loading"
        class="superadmin__card"
      >
        <AppSkeleton
          variant="table"
          :rows="5"
          :columns="6"
        />
      </AppCard>

      <div
        v-else-if="organizations.length > 0"
        class="superadmin__table-wrapper"
      >
        <table class="superadmin__table">
          <thead>
            <tr>
              <th>{{ $t('platformAdmin.orgName') }}</th>
              <th>{{ $t('platformAdmin.orgSlug') }}</th>
              <th>{{ $t('developer.status') }}</th>
              <th>Members / Accounts</th>
              <th>Created</th>
              <th class="superadmin__align-right">
                {{ $t('admin.actions') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="org in organizations"
              :key="org.id"
            >
              <td>
                <strong>{{ org.name }}</strong>
              </td>
              <td>
                <code class="superadmin__code">{{ org.slug }}</code>
              </td>
              <td>
                <AppBadge
                  :variant="
                    org.status === 'active'
                      ? 'success'
                      : org.status === 'suspended'
                        ? 'warning'
                        : 'danger'
                  "
                  size="s"
                >
                  {{ org.status }}
                </AppBadge>
              </td>
              <td class="superadmin__muted-cell">
                {{ org.memberCount }} members ({{ org.accountCount }} accounts)
              </td>
              <td class="superadmin__muted-cell">
                {{ formatDateTime(org.createdAt) }}
              </td>
              <td class="superadmin__align-right">
                <AppButton
                  variant="secondary"
                  size="s"
                  @click="openStatusModal(org)"
                >
                  {{ $t('platformAdmin.changeStatus') }}
                </AppButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AppEmptyState
        v-else
        :title="$t('common.empty')"
        :description="$t('platformAdmin.subtitle')"
      />
    </div>

    <!-- TAB 2: ACCOUNTS MODERATION -->
    <div
      v-else-if="activeTab === 'accounts'"
      class="superadmin__pane"
    >
      <AppErrorState
        v-if="error"
        :message="error"
        :action-label="$t('common.retry')"
        @action="loadAccounts"
      />

      <AppCard
        v-else-if="loading"
        class="superadmin__card"
      >
        <AppSkeleton
          variant="table"
          :rows="5"
          :columns="6"
        />
      </AppCard>

      <div
        v-else-if="accounts.length > 0"
        class="superadmin__table-wrapper"
      >
        <table class="superadmin__table">
          <thead>
            <tr>
              <th>{{ $t('platformAdmin.accountEmail') }}</th>
              <th>Organization</th>
              <th>{{ $t('developer.status') }}</th>
              <th>{{ $t('platformAdmin.reason') }}</th>
              <th class="superadmin__align-right">
                {{ $t('admin.actions') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="acc in accounts"
              :key="acc.id"
            >
              <td>
                <div class="superadmin__account-meta">
                  <strong>{{ acc.displayName }}</strong>
                  <span class="superadmin__muted-cell">{{ acc.email }}</span>
                </div>
              </td>
              <td>{{ acc.organizationName }}</td>
              <td>
                <AppBadge
                  :variant="
                    acc.moderationStatus === 'active'
                      ? 'success'
                      : acc.moderationStatus === 'pending'
                        ? 'warning'
                        : 'danger'
                  "
                  size="s"
                >
                  {{ acc.moderationStatus }}
                </AppBadge>
              </td>
              <td class="superadmin__muted-cell">
                {{ acc.moderationReason || '—' }}
              </td>
              <td class="superadmin__align-right">
                <div class="superadmin__btn-group">
                  <AppButton
                    v-if="acc.moderationStatus !== 'active'"
                    variant="secondary"
                    size="s"
                    @click="openModerationModal(acc, 'approve')"
                  >
                    {{ $t('platformAdmin.approve') }}
                  </AppButton>
                  <AppButton
                    v-if="acc.moderationStatus === 'active'"
                    variant="danger"
                    size="s"
                    @click="openModerationModal(acc, 'disable')"
                  >
                    {{ $t('platformAdmin.disable') }}
                  </AppButton>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AppEmptyState
        v-else
        :title="$t('common.empty')"
        :description="$t('platformAdmin.subtitle')"
      />
    </div>

    <!-- TAB 3: SUSPICIOUS SESSIONS -->
    <div
      v-else-if="activeTab === 'sessions'"
      class="superadmin__pane"
    >
      <AppErrorState
        v-if="error"
        :message="error"
        :action-label="$t('common.retry')"
        @action="loadSessions"
      />

      <AppCard
        v-else-if="loading"
        class="superadmin__card"
      >
        <AppSkeleton
          variant="table"
          :rows="5"
          :columns="6"
        />
      </AppCard>

      <div
        v-else-if="sessions.length > 0"
        class="superadmin__table-wrapper"
      >
        <table class="superadmin__table">
          <thead>
            <tr>
              <th>User / Email</th>
              <th>IP & Location</th>
              <th>Device / Agent</th>
              <th>{{ $t('platformAdmin.riskScore') }}</th>
              <th>Reason</th>
              <th class="superadmin__align-right">
                {{ $t('admin.actions') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="sess in sessions"
              :key="sess.id"
            >
              <td>
                <strong>{{ sess.accountEmail }}</strong>
              </td>
              <td>
                <code class="superadmin__code">{{ sess.ipAddress }}</code>
                <span class="superadmin__muted-cell">({{ sess.location }})</span>
              </td>
              <td class="superadmin__muted-cell">
                {{ sess.userAgent }}
              </td>
              <td>
                <AppBadge
                  :variant="
                    sess.riskScore === 'high'
                      ? 'danger'
                      : sess.riskScore === 'medium'
                        ? 'warning'
                        : 'neutral'
                  "
                  size="s"
                >
                  {{
                    sess.riskScore === 'high'
                      ? $t('platformAdmin.riskHigh')
                      : sess.riskScore === 'medium'
                        ? $t('platformAdmin.riskMedium')
                        : $t('platformAdmin.riskLow')
                  }}
                </AppBadge>
              </td>
              <td class="superadmin__muted-cell">
                {{ sess.suspiciousReason }}
              </td>
              <td class="superadmin__align-right">
                <AppButton
                  variant="danger"
                  size="s"
                  :loading="saving"
                  @click="handleRevokeSession(sess)"
                >
                  {{ $t('platformAdmin.revokeSession') }}
                </AppButton>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AppEmptyState
        v-else
        :title="$t('platformAdmin.noSuspiciousSessions')"
        :description="$t('platformAdmin.subtitle')"
      />
    </div>

    <!-- TAB 4: SYSTEM HEALTH -->
    <div
      v-else-if="activeTab === 'health'"
      class="superadmin__pane"
    >
      <div class="superadmin__actions">
        <AppButton
          variant="secondary"
          size="m"
          :loading="refreshingHealth"
          @click="loadHealth"
        >
          {{ $t('platformAdmin.refreshHealth') }}
        </AppButton>
      </div>

      <div class="superadmin__health-grid">
        <AppCard
          v-for="svc in healthItems"
          :key="svc.name"
          class="superadmin__health-card"
        >
          <div class="superadmin__health-header">
            <h3 class="superadmin__health-title">
              {{ svc.name }}
            </h3>
            <AppBadge
              :variant="
                svc.status === 'healthy'
                  ? 'success'
                  : svc.status === 'degraded'
                    ? 'warning'
                    : 'danger'
              "
              size="s"
            >
              {{
                svc.status === 'healthy'
                  ? $t('platformAdmin.healthy')
                  : svc.status === 'degraded'
                    ? $t('platformAdmin.degraded')
                    : $t('platformAdmin.unhealthy')
              }}
            </AppBadge>
          </div>
          <p
            v-if="svc.details"
            class="superadmin__health-desc"
          >
            {{ svc.details }}
          </p>
          <div class="superadmin__health-footer">
            <span class="superadmin__latency-tag">
              {{ $t('platformAdmin.latency', { ms: svc.latencyMs }) }}
            </span>
            <span class="superadmin__muted-cell">
              Checked: {{ formatDateTime(svc.lastCheckedAt) }}
            </span>
          </div>
        </AppCard>
      </div>
    </div>

    <!-- MODAL: CREATE ORGANIZATION -->
    <AppDialog
      v-model="showCreateOrgModal"
      :title="$t('platformAdmin.createOrg')"
    >
      <form
        class="superadmin__dialog-form"
        @submit.prevent="handleCreateOrg"
      >
        <AppInput
          id="superadmin-new-org-name"
          v-model="newOrg.name"
          :label="$t('platformAdmin.orgName')"
          placeholder="Acme Global Inc"
          required
        />
        <AppInput
          id="superadmin-new-org-slug"
          v-model="newOrg.slug"
          :label="$t('platformAdmin.orgSlug')"
          placeholder="acme-global"
          required
        />
        <div class="superadmin__form-actions">
          <AppButton
            variant="secondary"
            @click="showCreateOrgModal = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton
            variant="primary"
            type="submit"
            :loading="saving"
            :disabled="!newOrg.name.trim() || !newOrg.slug.trim()"
          >
            {{ $t('admin.create') }}
          </AppButton>
        </div>
      </form>
    </AppDialog>

    <!-- MODAL: UPDATE ORG STATUS -->
    <AppDialog
      v-model="showStatusModal"
      :title="$t('platformAdmin.changeStatus')"
    >
      <div
        v-if="editingOrg"
        class="superadmin__dialog-form"
      >
        <p>
          <strong>Organization:</strong>
          {{ editingOrg.name }}
        </p>
        <div class="superadmin__field">
          <label class="superadmin__field-label">{{ $t('developer.status') }}</label>
          <select
            v-model="selectedStatus"
            class="superadmin__select"
          >
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="archived">Archived</option>
          </select>
        </div>
        <div class="superadmin__form-actions">
          <AppButton
            variant="secondary"
            @click="showStatusModal = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton
            variant="primary"
            :loading="saving"
            @click="handleUpdateOrgStatus"
          >
            {{ $t('common.save') }}
          </AppButton>
        </div>
      </div>
    </AppDialog>

    <!-- MODAL: MODERATE ACCOUNT -->
    <AppDialog
      v-model="showModerateModal"
      :title="$t('platformAdmin.moderation')"
    >
      <div
        v-if="moderatingAccount"
        class="superadmin__dialog-form"
      >
        <p>
          <strong>Account:</strong>
          {{ moderatingAccount.email }}
        </p>
        <AppInput
          id="superadmin-moderate-reason"
          v-model="moderationReason"
          :label="$t('platformAdmin.reasonPrompt')"
          placeholder="e.g. Terms of Service violation / Verified ID"
        />
        <div class="superadmin__form-actions">
          <AppButton
            variant="secondary"
            @click="showModerateModal = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton
            variant="primary"
            :loading="saving"
            @click="handleModerateAccount"
          >
            {{ $t('common.submit') }}
          </AppButton>
        </div>
      </div>
    </AppDialog>
  </div>
</template>

<style scoped>
.superadmin {
  display: grid;
  gap: var(--space-20);
  min-width: 0;
  max-width: 100%;
  overflow-x: hidden;
}

.superadmin__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  flex-wrap: wrap;
  gap: var(--space-16);
  border-bottom: 1px solid var(--border);
  padding-bottom: var(--space-16);
  min-width: 0;
}

.superadmin__intro {
  min-width: 0;
  max-width: 100%;
}

.superadmin__title {
  font-size: var(--type-h2-size);
  letter-spacing: var(--type-h2-tracking);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  color: var(--text-1);
  margin: 0;
  overflow-wrap: break-word;
}

.superadmin__subtitle {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-2);
  margin: var(--space-4) 0 0 0;
  overflow-wrap: break-word;
}

.superadmin__tabs {
  display: flex;
  gap: var(--space-8);
  flex-wrap: wrap;
  max-width: 100%;
}

.superadmin__tab-btn {
  min-height: 38px;
  padding: 0 var(--space-12);
  background: transparent;
  border: 1px solid transparent;
  border-radius: var(--radius-control-m);
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  cursor: pointer;
  transition: var(--theme-transition);
  max-width: 100%;
  white-space: normal;
  text-align: center;
}

.superadmin__tab-btn:hover {
  color: var(--text-1);
  background: var(--surface-hover);
}

.superadmin__tab-btn--active {
  background: var(--selected-surface) !important;
  color: var(--text-1) !important;
  font-weight: var(--type-body-large-emphasis-weight) !important;
  border-color: var(--border) !important;
}

.superadmin__pane {
  display: grid;
  gap: var(--space-16);
  min-width: 0;
  max-width: 100%;
}

.superadmin__actions {
  display: flex;
  justify-content: flex-end;
}

.superadmin__table-wrapper {
  overflow-x: auto;
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
  background: var(--surface);
  max-width: 100%;
}

.superadmin__table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
}

.superadmin__table th {
  padding: var(--space-12) var(--space-16);
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
  text-transform: uppercase;
}

.superadmin__table td {
  padding: var(--space-12) var(--space-16);
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.superadmin__table tr:last-child td {
  border-bottom: none;
}

.superadmin__code {
  font-family: var(--font-mono);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  background: var(--surface-2);
  padding: 2px 6px;
  border-radius: var(--radius-control-s);
}

.superadmin__muted-cell {
  color: var(--text-2);
}

.superadmin__align-right {
  text-align: right;
}

.superadmin__btn-group {
  display: inline-flex;
  gap: var(--space-8);
  justify-content: flex-end;
}

.superadmin__account-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

/* Health cards */
.superadmin__health-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr));
  gap: var(--space-16);
  min-width: 0;
  max-width: 100%;
}

.superadmin__health-card {
  display: grid;
  gap: var(--space-12);
  padding: var(--space-16);
}

.superadmin__health-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.superadmin__health-title {
  font-size: var(--type-h3-size);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  margin: 0;
  color: var(--text-1);
}

.superadmin__health-desc {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-2);
  margin: 0;
}

.superadmin__health-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}

.superadmin__latency-tag {
  color: var(--accent);
}

/* Dialog */
.superadmin__dialog-form {
  display: grid;
  gap: var(--space-16);
  padding: var(--space-16) 0;
}

.superadmin__field {
  display: grid;
  gap: var(--space-4);
}

.superadmin__field-label {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-1);
}

.superadmin__select {
  min-height: 40px;
  padding: 0 var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  color: var(--text-1);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
}

.superadmin__form-actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-12);
  margin-top: var(--space-8);
}
</style>
