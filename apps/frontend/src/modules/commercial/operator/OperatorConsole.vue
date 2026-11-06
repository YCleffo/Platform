<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppPasswordInput from '@/shared/ui/fields/AppPasswordInput.vue';
import AppCheckbox from '@/shared/ui/fields/AppCheckbox.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppDisclosure from '@/shared/ui/surfaces/AppDisclosure.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import { formatMoney } from '@/shared/formatting/money';
import { formatDateTime } from '@/shared/formatting/date';
import OperationDialog from '@/modules/admin/workbench/OperationDialog.vue';
import RecordView from '@/modules/admin/workbench/RecordView.vue';
import { rowsFrom, type Operation } from '@/modules/admin/workbench/form';
import { operatorOperations } from './operations';
import {
  operatorHttp,
  operatorIdentity,
  operatorEpoch,
  operatorChallenge,
  loginOperator,
  verifyOperator,
  logoutOperator,
  clearOperator,
} from './session';
import OperatorSecurity from './OperatorSecurity.vue';
const { t, te, locale } = useI18n();
const email = ref('');
const password = ref('');
const code = ref('');
const recovery = ref(false);
const error = ref('');
const busy = ref(false);
const listBusy = ref(false);
const rows = ref<Record<string, unknown>[]>([]);
const tab = ref<'requests' | 'incidents' | 'compensations'>('requests');
const offset = ref(0);
const total = ref(0);
const selected = ref<Operation | null>(null);
const target = ref<Record<string, unknown>>({});
const open = ref(false);
let generation = 0;
let controller: AbortController | undefined;
const canView = computed(() =>
  tab.value === 'compensations' ? operatorIdentity.value?.finance : operatorIdentity.value?.privacy,
);
function state(value: unknown): string {
  const key = String(value ?? '');
  return te('operator.states.' + key)
    ? t('operator.states.' + key)
    : te('workbench.options.' + key)
      ? t('workbench.options.' + key)
      : key || '—';
}
function summary(row: Record<string, unknown>): { label: string; value: string }[] {
  if (tab.value === 'compensations') {
    const amount =
      typeof row.amount_minor === 'number'
        ? row.amount_minor
        : typeof row.amount_minor === 'string' && /^\d+$/.test(row.amount_minor)
          ? Number(row.amount_minor)
          : NaN;
    return [
      {
        label: t('operator.amount'),
        value:
          Number.isSafeInteger(amount) && typeof row.currency === 'string'
            ? formatMoney(amount, row.currency, locale.value)
            : '—',
      },
      { label: t('operator.compensationState'), value: state(row.compensation_status) },
      { label: t('operator.attestationState'), value: state(row.attestation_status) },
    ];
  }
  if (tab.value === 'requests')
    return [
      { label: t('workbench.fields.requestType'), value: state(row.request_type) },
      { label: t('workbench.fields.subjectKind'), value: state(row.subject_kind) },
      {
        label: t('workbench.fields.receivedAt'),
        value: formatDateTime(
          typeof row.received_at === 'string' ? row.received_at : null,
          locale.value,
        ),
      },
    ];
  return [
    {
      label: t('workbench.fields.detectedAt'),
      value: formatDateTime(
        typeof row.detected_at === 'string' ? row.detected_at : null,
        locale.value,
      ),
    },
    {
      label: t('operator.initialDue'),
      value: formatDateTime(
        typeof row.initial_notification_due_at === 'string'
          ? row.initial_notification_due_at
          : null,
        locale.value,
      ),
    },
    {
      label: t('operator.investigationDue'),
      value: formatDateTime(
        typeof row.investigation_notification_due_at === 'string'
          ? row.investigation_notification_due_at
          : null,
        locale.value,
      ),
    },
  ];
}
function action(key: string): Operation {
  return operatorOperations.find((op) => op.key === 'commercial.' + key)!;
}
async function login(): Promise<void> {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    if (operatorChallenge.value) await verifyOperator(code.value, recovery.value);
    else await loginOperator(email.value, password.value);
  } catch (e) {
    error.value = resolveErrorMessage(e);
  } finally {
    password.value = '';
    code.value = '';
    busy.value = false;
  }
}
async function logout(): Promise<void> {
  try {
    await logoutOperator();
  } catch (e) {
    error.value = resolveErrorMessage(e);
  }
}
async function load(): Promise<void> {
  controller?.abort();
  const version = ++generation;
  const epoch = operatorEpoch.value;
  rows.value = [];
  error.value = '';
  if (!canView.value) return;
  listBusy.value = true;
  controller = new AbortController();
  const timer = setTimeout(() => controller?.abort(), 10000);
  try {
    const path =
      tab.value === 'compensations' ? '/operator/compensations' : '/operator/privacy/' + tab.value;
    const result = rowsFrom(
      await operatorHttp.get<unknown>(path, {
        query: { limit: 25, offset: offset.value },
        signal: controller.signal,
        retryTransient: false,
      }),
    );
    if (version !== generation || epoch !== operatorEpoch.value) return;
    rows.value = result.items;
    total.value = result.total;
  } catch (e) {
    if (version === generation) {
      error.value = resolveErrorMessage(e);
      if (e instanceof ApiError && [401, 403, 404].includes(e.status)) {
        rows.value = [];
        open.value = false;
      }
    }
  } finally {
    clearTimeout(timer);
    if (version === generation) listBusy.value = false;
  }
}
function start(op: Operation, row: Record<string, unknown> = {}): void {
  selected.value = op;
  target.value = {
    ...row,
    attestationId: row.attestation_id,
    externalTransactionReference: row.external_transaction_reference ?? '',
    documentReference: row.document_reference ?? '',
    documentSha256: row.document_sha256 ?? '',
  };
  open.value = true;
}
async function run(
  op: Operation,
  path: string,
  body: Record<string, unknown>,
  signal: AbortSignal,
): Promise<unknown> {
  if (
    op.key === 'commercial.intake' &&
    ((body.subjectKind === 'billing_contact' && !body.billingProfileId) ||
      (body.subjectKind === 'financial_snapshot' && !body.invoiceId && !body.receiptId))
  )
    throw new ApiError(422, 'validation_failed', t('operator.bindingRequired'));
  return operatorHttp.request<unknown>(path, {
    method: op.method,
    body: op.method === 'GET' ? undefined : body,
    signal,
    retryTransient: false,
  });
}
function page(delta: number): void {
  offset.value = Math.max(0, offset.value + delta * 25);
  void load();
}
watch(
  () => [operatorIdentity.value, tab.value],
  () => {
    offset.value = 0;
    rows.value = [];
    open.value = false;
    selected.value = null;
    target.value = {};
    if (operatorIdentity.value) {
      if (!canView.value) tab.value = operatorIdentity.value.privacy ? 'requests' : 'compensations';
      void load();
    } else {
      controller?.abort();
      generation++;
      open.value = false;
    }
  },
);
onBeforeUnmount(() => {
  generation++;
  controller?.abort();
  password.value = '';
  code.value = '';
  rows.value = [];
});
</script>
<template>
  <section class="operator-console">
    <header class="operator-console__header">
      <h1>{{ t('operator.title') }}</h1>
      <div class="operator-console__header-actions">
        <RouterLink
          v-if="operatorIdentity?.catalog"
          class="operator-console__catalog"
          :to="{ name: 'operator-catalog' }"
        >
          {{ t('operator.catalog') }}
        </RouterLink>
        <AppButton
          v-if="operatorIdentity"
          variant="secondary"
          @click="logout"
        >
          {{ t('operator.signOut') }}
        </AppButton>
      </div>
    </header>
    <AppCard v-if="!operatorIdentity">
      <h2>{{ t('operator.signIn') }}</h2>
      <p>{{ t('operator.boundary') }}</p>
      <form
        class="operator-console__login"
        @submit.prevent="login"
      >
        <template v-if="!operatorChallenge">
          <AppInput
            id="operator-email"
            v-model="email"
            type="email"
            :label="t('auth.email')"
            autocomplete="username"
            required
            :disabled="busy"
          />
          <AppPasswordInput
            id="operator-password"
            v-model="password"
            :label="t('auth.password')"
            autocomplete="current-password"
            required
            :disabled="busy"
          />
        </template>
        <template v-else>
          <AppInput
            id="operator-code"
            v-model="code"
            :label="t('operator.mfaCode')"
            required
            :disabled="busy"
          />
          <AppCheckbox
            id="operator-recovery"
            v-model="recovery"
            :label="t('operator.useRecovery')"
            :disabled="busy"
          />
        </template>
        <AppButton
          type="submit"
          :loading="busy"
        >
          {{ t('operator.signIn') }}
        </AppButton>
        <AppButton
          v-if="operatorChallenge"
          type="button"
          variant="secondary"
          :disabled="busy"
          @click="clearOperator"
        >
          {{ t('common.cancel') }}
        </AppButton>
      </form>
    </AppCard>
    <template v-else>
      <p>{{ operatorIdentity.user.email }}</p>
      <OperatorSecurity />
      <nav
        class="operator-console__tabs"
        :aria-label="t('operator.title')"
      >
        <AppButton
          v-for="item in (operatorIdentity.privacy ? ['requests', 'incidents'] : []).concat(
            operatorIdentity.finance ? ['compensations'] : [],
          )"
          :key="item"
          :variant="tab === item ? 'primary' : 'secondary'"
          @click="tab = item as typeof tab"
        >
          {{ t('operator.' + item) }}
        </AppButton>
      </nav>
      <div class="operator-console__actions">
        <AppButton
          v-if="tab === 'requests'"
          :disabled="listBusy || !!error"
          @click="start(action('intake'))"
        >
          {{ t('operator.intake') }}
        </AppButton>
        <AppButton
          v-if="tab === 'incidents'"
          :disabled="listBusy || !!error"
          @click="start(action('detect'))"
        >
          {{ t('operator.detect') }}
        </AppButton>
        <AppButton
          variant="secondary"
          :loading="listBusy"
          @click="load"
        >
          {{ t('configuration.refresh') }}
        </AppButton>
      </div>
      <p v-if="!rows.length && !listBusy && !error">{{ t('workbench.noRecords') }}</p>
      <AppCard
        v-for="row in rows"
        :key="String(row.id)"
      >
        <header class="operator-console__record-head">
          <h3>
            {{
              tab === 'compensations'
                ? row.account_name || t('operator.compensations')
                : t(tab === 'requests' ? 'operator.requestTitle' : 'operator.incidentTitle')
            }}
          </h3>
          <AppBadge
            :variant="
              ['completed', 'closed', 'approved', 'refunded'].includes(String(row.status))
                ? 'success'
                : 'warning'
            "
          >
            {{ state(row.status) }}
          </AppBadge>
        </header>
        <dl class="operator-console__summary">
          <div
            v-for="entry in summary(row)"
            :key="entry.label"
          >
            <dt>{{ entry.label }}</dt>
            <dd>{{ entry.value }}</dd>
          </div>
        </dl>
        <AppDisclosure
          class="operator-console__references"
          :label="t('operator.references')"
        >
          <RecordView :data="row" />
        </AppDisclosure>
        <div class="operator-console__actions">
          <AppButton
            v-if="tab === 'requests' && ['submitted', 'in_review'].includes(String(row.status))"
            variant="secondary"
            @click="start(action('resolve'), row)"
          >
            {{ t('operator.resolve') }}
          </AppButton>
          <template v-if="tab === 'incidents' && row.status !== 'closed'">
            <AppButton
              variant="secondary"
              @click="start(action('event'), row)"
            >
              {{ t('operator.event') }}
            </AppButton>
            <AppButton
              variant="danger"
              @click="start(action('close'), row)"
            >
              {{ t('operator.close') }}
            </AppButton>
          </template>
          <template v-if="tab === 'compensations'">
            <AppButton
              v-if="row.attestation_id"
              variant="secondary"
              @click="start(action('current'), row)"
            >
              {{ t('operator.current') }}
            </AppButton>
            <AppButton
              v-if="!row.attestation_id && row.compensation_status === 'recovery_required'"
              @click="start(action('propose'), row)"
            >
              {{ t('operator.propose') }}
            </AppButton>
            <template v-if="row.attestation_status === 'proposed'">
              <p v-if="row.proposed_by === operatorIdentity.user.id">
                {{ t('operator.secondOperator') }}
              </p>
              <AppButton
                v-else
                variant="secondary"
                @click="start(action('approve'), row)"
              >
                {{ t('operator.approve') }}
              </AppButton>
              <AppButton
                v-if="row.proposed_by !== operatorIdentity.user.id"
                variant="danger"
                @click="start(action('reject'), row)"
              >
                {{ t('operator.reject') }}
              </AppButton>
            </template>
          </template>
        </div>
      </AppCard>
      <div class="operator-console__actions">
        <AppButton
          variant="secondary"
          :disabled="offset === 0 || listBusy"
          @click="page(-1)"
        >
          {{ t('admin.previous') }}
        </AppButton>
        <span>
          {{
            t('admin.range', {
              from: total ? offset + 1 : 0,
              to: Math.min(offset + 25, total),
              total,
            })
          }}
        </span>
        <AppButton
          variant="secondary"
          :disabled="offset + 25 >= total || listBusy"
          @click="page(1)"
        >
          {{ t('admin.next') }}
        </AppButton>
      </div>
      <OperationDialog
        v-model="open"
        :operation="selected"
        :row="target"
        :run="run"
        :session-key="`${operatorEpoch}:${operatorIdentity?.privacy}:${operatorIdentity?.finance}`"
        @done="load"
      >
        <template #authorization><OperatorSecurity /></template>
      </OperationDialog>
    </template>
    <p
      v-if="error"
      class="operator-console__error"
      role="alert"
    >
      {{ error }}
    </p>
  </section>
</template>
<style scoped>
.operator-console {
  display: grid;
  gap: var(--space-20);
  min-width: 0;
  max-width: 1120px;
  width: 100%;
  margin-inline: auto;
}
.operator-console__record-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-12);
  flex-wrap: wrap;
}
.operator-console__summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(200px, 100%), 1fr));
  gap: var(--space-16);
  margin-top: var(--space-16);
}
.operator-console__summary dt {
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}
.operator-console__summary dd {
  margin-top: var(--space-4);
}
.operator-console__references {
  margin-top: var(--space-16);
  color: var(--text-2);
}
.operator-console__references :deep(.wb-record) {
  margin-top: var(--space-12);
  color: var(--text-1);
}
.operator-console__header,
.operator-console__tabs,
.operator-console__actions {
  display: flex;
  gap: var(--space-8);
  flex-wrap: wrap;
  align-items: center;
}
.operator-console__header {
  justify-content: space-between;
}
.operator-console__header-actions {
  display: flex;
  align-items: center;
  gap: var(--space-12);
  flex-wrap: wrap;
}
.operator-console__catalog {
  color: var(--accent-strong);
  font-size: var(--type-body-medium-emphasis-size);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
  text-decoration: underline;
  text-underline-offset: 3px;
}
.operator-console__login {
  display: grid;
  gap: var(--space-16);
  max-width: 480px;
  margin-top: var(--space-16);
}
.operator-console__actions {
  margin-top: var(--space-12);
}
.operator-console__error {
  color: var(--danger);
}
</style>
