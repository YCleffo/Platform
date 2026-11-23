<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  coreServiceCredentialsApi,
  type ServiceCredentialSummary,
  type IssuedServiceCredential,
} from '@/shared/api/core/serviceCredentials';
import { useAuthStore } from '@/core/auth/authStore';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
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

interface WebhookDeliveryLog {
  id: string;
  event: string;
  targetUrl: string;
  statusCode: number;
  deliveredAt: string;
  durationMs: number;
  payload: Record<string, unknown>;
}

interface ApiEndpointDoc {
  method: 'GET' | 'POST' | 'PATCH' | 'DELETE';
  path: string;
  module: string;
  description: string;
  scopes: string[];
  exampleCurl: string;
}

const { t } = useI18n();
const toast = useToast();
const auth = useAuthStore();
const adminAuth = useAdminAuthStore();

const activeTab = ref<'credentials' | 'openapi' | 'webhooks'>('credentials');
const loading = ref(true);
const error = ref('');
const saving = ref(false);

const credentials = ref<ServiceCredentialSummary[]>([]);
const currentAccountId = computed(() => {
  return (
    auth.currentUser?.id || adminAuth.identity?.account.id || '01a204bc-f94d-7bac-8ac8-a828186dfd4b'
  );
});

// Issue modal state
const showIssueModal = ref(false);
const issueForm = ref({
  name: '',
  scopes: ['read:storage'] as string[],
  expiresInDays: 90,
});

// Rotation modal state
const showRotateModal = ref(false);
const rotatingCredential = ref<ServiceCredentialSummary | null>(null);
const rotationGraceHours = ref(24);

// Revocation modal state
const showRevokeModal = ref(false);
const revokingCredential = ref<ServiceCredentialSummary | null>(null);

// One-time secret view modal
const showSecretModal = ref(false);
const newlyIssuedSecret = ref<IssuedServiceCredential | null>(null);

const availableScopes = [
  { code: 'read:storage', label: 'Cloud Drive Read', desc: 'Read files and folder listings' },
  {
    code: 'manage:billing',
    label: 'Billing Operations',
    desc: 'Manage subscriptions, plans and invoices',
  },
  {
    code: 'education:read',
    label: 'Education Reader',
    desc: 'Read course catalog, enrollments and progress',
  },
  {
    code: 'entitlements.read',
    label: 'Entitlements Read',
    desc: 'Check customer license entitlements',
  },
  {
    code: 'usage.write',
    label: 'Usage Metering',
    desc: 'Push real-time usage consumption metrics',
  },
];

// Webhook state
const webhookUrl = ref('https://api.external-corp.example/webhooks/platform');
const webhookSecret = ref('whsec_9b2e8a7c1f4d30e5');
const deliveryLogs = ref<WebhookDeliveryLog[]>([
  {
    id: 'wh-del-01',
    event: 'storage.file_uploaded',
    targetUrl: 'https://api.external-corp.example/webhooks/platform',
    statusCode: 200,
    deliveredAt: new Date(Date.now() - 120000).toISOString(),
    durationMs: 84,
    payload: {
      fileId: 'file-01a2',
      name: 'report_q4.pdf',
      sizeBytes: 1048576,
      mimeType: 'application/pdf',
    },
  },
  {
    id: 'wh-del-02',
    event: 'subscription.renewed',
    targetUrl: 'https://api.external-corp.example/webhooks/platform',
    statusCode: 200,
    deliveredAt: new Date(Date.now() - 3600000).toISOString(),
    durationMs: 112,
    payload: {
      subscriptionId: 'sub-94b1',
      status: 'active',
      periodEnd: '2026-12-23T00:00:00Z',
    },
  },
  {
    id: 'wh-del-03',
    event: 'account.member_invited',
    targetUrl: 'https://api.external-corp.example/webhooks/platform',
    statusCode: 502,
    deliveredAt: new Date(Date.now() - 7200000).toISOString(),
    durationMs: 504,
    payload: {
      inviteId: 'inv-881c',
      email: 'engineer@corp.example',
      role: 'developer',
    },
  },
]);

// Selected payload preview
const inspectedPayload = ref<WebhookDeliveryLog | null>(null);

// OpenAPI Specs Explorer
const openApiEndpoints = ref<ApiEndpointDoc[]>([
  {
    method: 'GET',
    path: '/api/storage/files',
    module: 'Cloud Drive',
    description: 'List accessible files and directories for the authorized tenant.',
    scopes: ['read:storage'],
    exampleCurl:
      'curl -H "Authorization: Bearer sk_live_..." https://platform.local/api/storage/files?prefix=/documents',
  },
  {
    method: 'POST',
    path: '/api/storage/tickets',
    module: 'Cloud Drive',
    description: 'Request a secure direct presigned S3 upload ticket with SHA-256 verification.',
    scopes: ['read:storage'],
    exampleCurl:
      'curl -X POST -H "Authorization: Bearer sk_live_..." -H "Content-Type: application/json" -d \'{"fileName":"doc.pdf","sizeBytes":1024,"mimeType":"application/pdf","sha256":"..."}\' https://platform.local/api/storage/tickets',
  },
  {
    method: 'GET',
    path: '/api/commercial/subscriptions/current',
    module: 'Commercial Billing',
    description: 'Retrieve active tenant subscription status, renewal date, and seat limits.',
    scopes: ['manage:billing'],
    exampleCurl:
      'curl -H "Authorization: Bearer sk_live_..." https://platform.local/api/commercial/subscriptions/current',
  },
  {
    method: 'POST',
    path: '/api/commercial/usage/meter',
    module: 'Usage Metering',
    description: 'Submit metered consumption units for active tenant entitlements.',
    scopes: ['usage.write'],
    exampleCurl:
      'curl -X POST -H "Authorization: Bearer sk_live_..." -H "Content-Type: application/json" -d \'{"units":100,"metricKey":"api_calls"}\' https://platform.local/api/commercial/usage/meter',
  },
  {
    method: 'GET',
    path: '/api/education/courses',
    module: 'Education Platform',
    description: 'Fetch catalog of courses, syllabus modules, and learner enrollment records.',
    scopes: ['education:read'],
    exampleCurl:
      'curl -H "Authorization: Bearer sk_live_..." https://platform.local/api/education/courses',
  },
]);

async function loadCredentials(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    credentials.value = await coreServiceCredentialsApi.list(currentAccountId.value);
  } catch (caught) {
    error.value = resolveErrorMessage(caught);
  } finally {
    loading.value = false;
  }
}

async function handleIssueKey(): Promise<void> {
  if (!issueForm.value.name.trim()) return;
  saving.value = true;
  try {
    const created = await coreServiceCredentialsApi.issue(currentAccountId.value, {
      name: issueForm.value.name.trim(),
      scopes: issueForm.value.scopes,
      expiresInDays: Number(issueForm.value.expiresInDays) || undefined,
    });
    credentials.value.unshift(created);
    showIssueModal.value = false;
    newlyIssuedSecret.value = created;
    showSecretModal.value = true;
    issueForm.value = { name: '', scopes: ['read:storage'], expiresInDays: 90 };
    toast.success(t('developer.keyCreated'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

function openRotateModal(credential: ServiceCredentialSummary): void {
  rotatingCredential.value = credential;
  rotationGraceHours.value = 24;
  showRotateModal.value = true;
}

async function handleRotateKey(): Promise<void> {
  if (!rotatingCredential.value) return;
  saving.value = true;
  try {
    const rotated = await coreServiceCredentialsApi.rotate(
      currentAccountId.value,
      rotatingCredential.value.id,
      { gracePeriodHours: rotationGraceHours.value },
    );
    const idx = credentials.value.findIndex((c) => c.id === rotatingCredential.value!.id);
    if (idx !== -1) {
      credentials.value[idx] = rotated;
    }
    showRotateModal.value = false;
    newlyIssuedSecret.value = rotated;
    showSecretModal.value = true;
    toast.success(t('developer.keyRotated'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

function openRevokeModal(credential: ServiceCredentialSummary): void {
  revokingCredential.value = credential;
  showRevokeModal.value = true;
}

async function handleRevokeKey(): Promise<void> {
  if (!revokingCredential.value) return;
  saving.value = true;
  try {
    await coreServiceCredentialsApi.revoke(currentAccountId.value, revokingCredential.value.id);
    const target = credentials.value.find((c) => c.id === revokingCredential.value!.id);
    if (target) {
      target.status = 'revoked';
    }
    showRevokeModal.value = false;
    toast.success(t('developer.keyRevoked'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

async function copyToClipboard(textToCopy: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(textToCopy);
    toast.success(t('developer.secretCopied'));
  } catch {
    toast.info(t('developer.copied'));
  }
}

function replayWebhook(delivery: WebhookDeliveryLog): void {
  delivery.statusCode = 200;
  delivery.deliveredAt = new Date().toISOString();
  toast.success(t('developer.keyCreated'));
}

onMounted(() => {
  void loadCredentials();
});
</script>

<template>
  <div class="dev-settings">
    <header class="dev-settings__header">
      <div>
        <h1 class="dev-settings__title">
          {{ $t('developer.title') }}
        </h1>
        <p class="dev-settings__subtitle">
          {{ $t('developer.subtitle') }}
        </p>
      </div>

      <nav
        class="dev-settings__tabs"
        :aria-label="$t('developer.title')"
      >
        <button
          type="button"
          class="dev-settings__tab-btn"
          :class="{ 'dev-settings__tab-btn--active': activeTab === 'credentials' }"
          @click="activeTab = 'credentials'"
        >
          {{ $t('developer.credentials') }}
        </button>
        <button
          type="button"
          class="dev-settings__tab-btn"
          :class="{ 'dev-settings__tab-btn--active': activeTab === 'openapi' }"
          @click="activeTab = 'openapi'"
        >
          {{ $t('developer.openApi') }}
        </button>
        <button
          type="button"
          class="dev-settings__tab-btn"
          :class="{ 'dev-settings__tab-btn--active': activeTab === 'webhooks' }"
          @click="activeTab = 'webhooks'"
        >
          {{ $t('developer.webhooks') }}
        </button>
      </nav>
    </header>

    <!-- TAB 1: SERVICE CREDENTIALS -->
    <div
      v-if="activeTab === 'credentials'"
      class="dev-settings__pane"
    >
      <div class="dev-settings__actions">
        <AppButton
          variant="primary"
          size="m"
          @click="showIssueModal = true"
        >
          {{ $t('developer.createKey') }}
        </AppButton>
      </div>

      <AppErrorState
        v-if="error"
        :message="error"
        :action-label="$t('common.retry')"
        @action="loadCredentials"
      />

      <AppCard
        v-else-if="loading"
        class="dev-settings__card"
      >
        <AppSkeleton
          variant="table"
          :rows="4"
          :columns="6"
        />
      </AppCard>

      <div
        v-else-if="credentials.length > 0"
        class="dev-settings__table-wrapper"
      >
        <table class="dev-settings__table">
          <thead>
            <tr>
              <th>{{ $t('developer.keyName') }}</th>
              <th>{{ $t('developer.prefix') }}</th>
              <th>{{ $t('developer.scopes') }}</th>
              <th>{{ $t('developer.status') }}</th>
              <th>{{ $t('developer.expiresAt') }}</th>
              <th class="dev-settings__align-right">
                {{ $t('admin.actions') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="item in credentials"
              :key="item.id"
            >
              <td class="dev-settings__name-cell">
                <strong>{{ item.name }}</strong>
                <span
                  v-if="item.rotationGraceUntil"
                  class="dev-settings__grace-note"
                >
                  Grace until: {{ formatDateTime(item.rotationGraceUntil) }}
                </span>
              </td>
              <td>
                <code class="dev-settings__code">{{ item.prefix }}••••••••</code>
              </td>
              <td>
                <div class="dev-settings__scopes">
                  <AppBadge
                    v-for="scope in item.scopes"
                    :key="scope"
                    variant="neutral"
                    size="s"
                  >
                    {{ scope }}
                  </AppBadge>
                </div>
              </td>
              <td>
                <AppBadge
                  :variant="
                    item.status === 'active'
                      ? 'success'
                      : item.status === 'rotated'
                        ? 'warning'
                        : 'danger'
                  "
                  size="s"
                >
                  {{ item.status }}
                </AppBadge>
              </td>
              <td class="dev-settings__muted-cell">
                {{ item.expiresAt ? formatDateTime(item.expiresAt) : 'Never' }}
              </td>
              <td class="dev-settings__align-right">
                <div class="dev-settings__btn-group">
                  <AppButton
                    v-if="item.status === 'active'"
                    variant="secondary"
                    size="s"
                    @click="openRotateModal(item)"
                  >
                    {{ $t('developer.rotateKey') }}
                  </AppButton>
                  <AppButton
                    v-if="item.status !== 'revoked'"
                    variant="danger"
                    size="s"
                    @click="openRevokeModal(item)"
                  >
                    {{ $t('developer.revokeKey') }}
                  </AppButton>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <AppEmptyState
        v-else
        :title="$t('developer.noCredentials')"
        :subtitle="$t('developer.subtitle')"
      />
    </div>

    <!-- TAB 2: OPENAPI EXPLORER -->
    <div
      v-else-if="activeTab === 'openapi'"
      class="dev-settings__pane"
    >
      <div class="dev-settings__api-list">
        <AppCard
          v-for="ep in openApiEndpoints"
          :key="ep.path"
          class="dev-settings__endpoint-card"
        >
          <div class="dev-settings__endpoint-header">
            <div class="dev-settings__endpoint-title">
              <span
                class="dev-settings__method-pill"
                :class="`dev-settings__method-pill--${ep.method.toLowerCase()}`"
              >
                {{ ep.method }}
              </span>
              <code class="dev-settings__endpoint-path">{{ ep.path }}</code>
              <AppBadge
                variant="neutral"
                size="s"
              >
                {{ ep.module }}
              </AppBadge>
            </div>
            <AppButton
              variant="secondary"
              size="s"
              @click="copyToClipboard(ep.exampleCurl)"
            >
              cURL
            </AppButton>
          </div>
          <p class="dev-settings__endpoint-desc">
            {{ ep.description }}
          </p>
          <div class="dev-settings__endpoint-scopes">
            <span class="dev-settings__scopes-label">{{ $t('developer.scopes') }}:</span>
            <AppBadge
              v-for="s in ep.scopes"
              :key="s"
              variant="info"
              size="s"
            >
              {{ s }}
            </AppBadge>
          </div>
          <pre class="dev-settings__curl-box"><code>{{ ep.exampleCurl }}</code></pre>
        </AppCard>
      </div>
    </div>

    <!-- TAB 3: WEBHOOKS & DELIVERIES -->
    <div
      v-else-if="activeTab === 'webhooks'"
      class="dev-settings__pane"
    >
      <AppCard class="dev-settings__webhook-config">
        <h3 class="dev-settings__section-heading">
          {{ $t('developer.webhooks') }}
        </h3>
        <p class="dev-settings__section-subtitle">
          {{ $t('developer.webhooksSubtitle') }}
        </p>
        <div class="dev-settings__webhook-fields">
          <AppInput
            id="dev-webhook-url"
            v-model="webhookUrl"
            :label="$t('developer.webhookUrl')"
            readonly
          />
          <AppInput
            id="dev-webhook-secret"
            v-model="webhookSecret"
            :label="$t('developer.webhookSecret')"
            type="password"
            readonly
          />
        </div>
      </AppCard>

      <div class="dev-settings__deliveries-section">
        <h3 class="dev-settings__section-heading">
          {{ $t('developer.deliveryHistory') }}
        </h3>
        <div class="dev-settings__table-wrapper">
          <table class="dev-settings__table">
            <thead>
              <tr>
                <th>{{ $t('developer.event') }}</th>
                <th>{{ $t('developer.httpStatus') }}</th>
                <th>{{ $t('developer.lastDelivery') }}</th>
                <th>Latency</th>
                <th class="dev-settings__align-right">
                  {{ $t('admin.actions') }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="del in deliveryLogs"
                :key="del.id"
              >
                <td>
                  <code class="dev-settings__code">{{ del.event }}</code>
                </td>
                <td>
                  <AppBadge
                    :variant="del.statusCode === 200 ? 'success' : 'danger'"
                    size="s"
                  >
                    {{ del.statusCode }}
                  </AppBadge>
                </td>
                <td class="dev-settings__muted-cell">
                  {{ formatDateTime(del.deliveredAt) }}
                </td>
                <td class="dev-settings__muted-cell">{{ del.durationMs }}ms</td>
                <td class="dev-settings__align-right">
                  <div class="dev-settings__btn-group">
                    <AppButton
                      variant="secondary"
                      size="s"
                      @click="inspectedPayload = del"
                    >
                      Payload
                    </AppButton>
                    <AppButton
                      variant="primary"
                      size="s"
                      @click="replayWebhook(del)"
                    >
                      {{ $t('developer.replay') }}
                    </AppButton>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- MODAL: ISSUE KEY -->
    <AppDialog
      v-model="showIssueModal"
      :title="$t('developer.createKey')"
    >
      <form
        class="dev-settings__dialog-form"
        @submit.prevent="handleIssueKey"
      >
        <AppInput
          id="dev-issue-key-name"
          v-model="issueForm.name"
          :label="$t('developer.keyName')"
          placeholder="e.g. S3 Backup Daemon"
          required
        />

        <div class="dev-settings__scope-selector">
          <label class="dev-settings__field-label">{{ $t('developer.scopes') }}</label>
          <div class="dev-settings__scope-list">
            <label
              v-for="sc in availableScopes"
              :key="sc.code"
              class="dev-settings__scope-checkbox"
            >
              <input
                v-model="issueForm.scopes"
                type="checkbox"
                :value="sc.code"
              />
              <div class="dev-settings__scope-meta">
                <span class="dev-settings__scope-title">
                  {{ sc.label }} (
                  <code>{{ sc.code }}</code>
                  )
                </span>
                <span class="dev-settings__scope-desc">{{ sc.desc }}</span>
              </div>
            </label>
          </div>
        </div>

        <div class="dev-settings__form-actions">
          <AppButton
            variant="secondary"
            @click="showIssueModal = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton
            variant="primary"
            type="submit"
            :loading="saving"
            :disabled="!issueForm.name.trim() || issueForm.scopes.length === 0"
          >
            {{ $t('admin.create') }}
          </AppButton>
        </div>
      </form>
    </AppDialog>

    <!-- MODAL: ROTATE KEY -->
    <AppDialog
      v-model="showRotateModal"
      :title="$t('developer.rotateKey')"
    >
      <div class="dev-settings__dialog-body">
        <p class="dev-settings__warning-text">
          {{ $t('developer.rotateConfirmation') }}
        </p>
        <div class="dev-settings__field">
          <label class="dev-settings__field-label">{{ $t('developer.gracePeriod') }}</label>
          <select
            v-model="rotationGraceHours"
            class="dev-settings__select"
          >
            <option :value="1">1 hour</option>
            <option :value="12">12 hours</option>
            <option :value="24">24 hours (Recommended)</option>
            <option :value="48">48 hours</option>
          </select>
        </div>
        <div class="dev-settings__form-actions">
          <AppButton
            variant="secondary"
            @click="showRotateModal = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton
            variant="secondary"
            :loading="saving"
            @click="handleRotateKey"
          >
            {{ $t('developer.rotateKey') }}
          </AppButton>
        </div>
      </div>
    </AppDialog>

    <!-- MODAL: REVOKE KEY -->
    <AppDialog
      v-model="showRevokeModal"
      :title="$t('developer.revokeKey')"
    >
      <div class="dev-settings__dialog-body">
        <p class="dev-settings__danger-text">
          {{ $t('developer.revokeConfirmation') }}
        </p>
        <p v-if="revokingCredential">
          <strong>Key:</strong>
          {{ revokingCredential.name }} ({{ revokingCredential.prefix }}••••)
        </p>
        <div class="dev-settings__form-actions">
          <AppButton
            variant="secondary"
            @click="showRevokeModal = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton
            variant="danger"
            :loading="saving"
            @click="handleRevokeKey"
          >
            {{ $t('developer.revokeKey') }}
          </AppButton>
        </div>
      </div>
    </AppDialog>

    <!-- MODAL: ONE-TIME SECRET DISPLAY -->
    <AppDialog
      v-model="showSecretModal"
      :title="$t('developer.copySecret')"
    >
      <div
        v-if="newlyIssuedSecret"
        class="dev-settings__dialog-body"
      >
        <div class="dev-settings__secret-alert">
          <strong>⚠️ {{ $t('developer.oneTimeSecretHint') }}</strong>
        </div>
        <div class="dev-settings__secret-reveal">
          <code class="dev-settings__secret-code">{{ newlyIssuedSecret.secretToken }}</code>
          <AppButton
            variant="primary"
            size="m"
            @click="copyToClipboard(newlyIssuedSecret.secretToken)"
          >
            {{ $t('developer.copySecret') }}
          </AppButton>
        </div>
        <div class="dev-settings__form-actions">
          <AppButton
            variant="secondary"
            @click="showSecretModal = false"
          >
            {{ $t('common.close') }}
          </AppButton>
        </div>
      </div>
    </AppDialog>

    <!-- MODAL: WEBHOOK PAYLOAD INSPECT -->
    <AppDialog
      :model-value="inspectedPayload !== null"
      title="Webhook Payload Preview"
      size="l"
      @update:model-value="if (!$event) inspectedPayload = null;"
    >
      <div
        v-if="inspectedPayload"
        class="dev-settings__dialog-body"
      >
        <p>
          <strong>Event:</strong>
          <code>{{ inspectedPayload.event }}</code>
        </p>
        <p>
          <strong>Delivered To:</strong>
          {{ inspectedPayload.targetUrl }}
        </p>
        <pre
          class="dev-settings__curl-box"
        ><code>{{ JSON.stringify(inspectedPayload.payload, null, 2) }}</code></pre>
        <div class="dev-settings__form-actions">
          <AppButton
            variant="secondary"
            @click="inspectedPayload = null"
          >
            {{ $t('common.close') }}
          </AppButton>
        </div>
      </div>
    </AppDialog>
  </div>
</template>

<style scoped>
.dev-settings {
  display: grid;
  gap: var(--space-20);
  min-width: 0;
  max-width: 100%;
  overflow-x: hidden;
}

.dev-settings__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  flex-wrap: wrap;
  gap: var(--space-16);
  border-bottom: 1px solid var(--border);
  padding-bottom: var(--space-16);
  min-width: 0;
}

.dev-settings__title {
  font-size: var(--type-h2-size);
  letter-spacing: var(--type-h2-tracking);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  color: var(--text-1);
  margin: 0;
  overflow-wrap: break-word;
}

.dev-settings__subtitle {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-2);
  margin: var(--space-4) 0 0 0;
  overflow-wrap: break-word;
}

.dev-settings__tabs {
  display: flex;
  gap: var(--space-8);
  flex-wrap: wrap;
  max-width: 100%;
}

.dev-settings__tab-btn {
  min-height: 38px;
  padding: 0 var(--space-16);
  background: transparent;
  border: 1px solid transparent;
  border-radius: var(--radius-control-m);
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  cursor: pointer;
  transition: var(--theme-transition);
}

.dev-settings__tab-btn:hover {
  color: var(--text-1);
  background: var(--surface-hover);
}

.dev-settings__tab-btn--active {
  background: var(--selected-surface) !important;
  color: var(--text-1) !important;
  font-weight: var(--type-body-large-emphasis-weight) !important;
  border-color: var(--border) !important;
}

.dev-settings__pane {
  display: grid;
  gap: var(--space-16);
}

.dev-settings__actions {
  display: flex;
  justify-content: flex-end;
}

.dev-settings__table-wrapper {
  overflow-x: auto;
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
  background: var(--surface);
}

.dev-settings__table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
}

.dev-settings__table th {
  padding: var(--space-12) var(--space-16);
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
  text-transform: uppercase;
}

.dev-settings__table td {
  padding: var(--space-12) var(--space-16);
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.dev-settings__table tr:last-child td {
  border-bottom: none;
}

.dev-settings__name-cell {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.dev-settings__grace-note {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--warning);
}

.dev-settings__code {
  font-family: monospace;
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  background: var(--surface-2);
  padding: 2px 6px;
  border-radius: var(--radius-control-s);
}

.dev-settings__scopes {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-4);
}

.dev-settings__muted-cell {
  color: var(--text-2);
}

.dev-settings__align-right {
  text-align: right;
}

.dev-settings__btn-group {
  display: inline-flex;
  gap: var(--space-8);
  justify-content: flex-end;
}

/* OpenAPI Explorer */
.dev-settings__api-list {
  display: grid;
  gap: var(--space-16);
}

.dev-settings__endpoint-card {
  display: grid;
  gap: var(--space-12);
  padding: var(--space-16);
}

.dev-settings__endpoint-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-8);
}

.dev-settings__endpoint-title {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  flex-wrap: wrap;
}

.dev-settings__method-pill {
  padding: 2px 8px;
  border-radius: var(--radius-control-s);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  text-transform: uppercase;
  color: var(--color-white);
}

.dev-settings__method-pill--get {
  background: var(--color-success-500);
}

.dev-settings__method-pill--post {
  background: var(--color-primary-500);
}

.dev-settings__method-pill--patch {
  background: var(--color-warning-500);
}

.dev-settings__method-pill--delete {
  background: var(--color-danger-500);
}

.dev-settings__endpoint-path {
  font-family: monospace;
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  color: var(--text-1);
}

.dev-settings__endpoint-desc {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-2);
  margin: 0;
}

.dev-settings__endpoint-scopes {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}

.dev-settings__scopes-label {
  color: var(--text-2);
  font-weight: var(--type-body-caption-weight);
}

.dev-settings__curl-box {
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-s);
  padding: var(--space-12);
  margin: 0;
  overflow-x: auto;
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-1);
}

/* Webhooks */
.dev-settings__webhook-config {
  display: grid;
  gap: var(--space-12);
  padding: var(--space-16);
}

.dev-settings__section-heading {
  font-size: var(--type-h3-size);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  margin: 0;
  color: var(--text-1);
}

.dev-settings__section-subtitle {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-2);
  margin: 0;
}

.dev-settings__webhook-fields {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: var(--space-16);
}

@media (max-width: 768px) {
  .dev-settings__webhook-fields {
    grid-template-columns: 1fr;
  }
}

.dev-settings__deliveries-section {
  display: grid;
  gap: var(--space-12);
}

/* Dialog Forms */
.dev-settings__dialog-form,
.dev-settings__dialog-body {
  display: grid;
  gap: var(--space-16);
  padding: var(--space-16) 0;
}

.dev-settings__field {
  display: grid;
  gap: var(--space-4);
}

.dev-settings__field-label {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-1);
}

.dev-settings__select {
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

.dev-settings__scope-selector {
  display: grid;
  gap: var(--space-8);
}

.dev-settings__scope-list {
  display: grid;
  gap: var(--space-8);
}

.dev-settings__scope-checkbox {
  display: flex;
  gap: var(--space-12);
  align-items: flex-start;
  padding: var(--space-8);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-s);
  cursor: pointer;
}

.dev-settings__scope-checkbox input {
  margin-top: 4px;
}

.dev-settings__scope-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.dev-settings__scope-title {
  font-size: var(--type-body-small-emphasis-size);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
}

.dev-settings__scope-desc {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
}

.dev-settings__warning-text {
  color: var(--warning);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  margin: 0;
}

.dev-settings__danger-text {
  color: var(--danger);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  margin: 0;
}

.dev-settings__secret-alert {
  background: var(--warning-soft);
  border: 1px solid var(--warning);
  padding: var(--space-12);
  border-radius: var(--radius-control-m);
  color: var(--text-1);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.dev-settings__secret-reveal {
  display: flex;
  gap: var(--space-12);
  align-items: center;
  background: var(--surface-2);
  padding: var(--space-12);
  border-radius: var(--radius-control-m);
}

.dev-settings__secret-code {
  flex: 1;
  font-family: monospace;
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  word-break: break-all;
  color: var(--accent);
}

.dev-settings__form-actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-12);
  margin-top: var(--space-8);
}
</style>
