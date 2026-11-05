<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { coreAdminSessionEpoch } from '@/core/admin/session';
import {
  privacyOperator,
  allowedTransitions,
  type OperatorAction,
  type OperatorCommand,
  type ProcessingBlock,
} from '@/shared/api/core/privacyOperator';
import {
  responseKind,
  type OwnPrivacyRequest,
  type PrivacyResponse,
  type PrivacyDeadline,
} from '@/shared/api/core/privacy';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { ApiError } from '@/shared/api/errors';
import { formatDateTime } from '@/shared/formatting/date';
import { useAdminOperation } from '../useAdminOperation';
import PrivacyOperationDialog from './PrivacyOperationDialog.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppJsonResponseDialog from '@/shared/ui/overlays/AppJsonResponseDialog.vue';
import '../profile/privacy.css';
const route = useRoute();
const auth = useAdminAuthStore();
const { t, te, locale } = useI18n();
const reads = useAdminOperation();
const actions = useAdminOperation();
const row = ref<OwnPrivacyRequest | null>(null);
const blocks = ref<ProcessingBlock[]>([]);
const purposes = ref<string[]>([]);
const loading = ref(false);
const error = ref<unknown>(null);
const busy = ref(false);
const actionError = ref<unknown>(null);
const uncertain = ref(false);
const notice = ref<string | null>(null);
const executionId = ref<string | null>(null);
const operation = ref<OperatorAction | null>(null);
const dialogOpen = computed({
  get: () => operation.value !== null,
  set: (value: boolean) => {
    if (!value) operation.value = null;
  },
});
const deadlineCode = ref('');
const blockId = ref('');
const response = ref<PrivacyResponse | null>(null);
const preview = ref(false);
const handed = ref(false);
let responseController: AbortController | null = null;
let objectUrl: string | null = null;
let releaseTimer: ReturnType<typeof setTimeout> | undefined;
const canManage = computed(() => auth.can('core.privacy.requests.manage'));
const terminal = computed(() =>
  ['completed', 'partially_completed', 'cancelled', 'rejected'].includes(row.value?.status ?? ''),
);
const canAct = computed(
  () => canManage.value && !busy.value && !loading.value && !uncertain.value && !error.value,
);
const correctionConfirmed = computed(() =>
  row.value?.deadlines.some((d) => d.code === 'confirmed_correction'),
);
function label(group: string, value: string) {
  const key = `corePrivacy.${group}_${value}`;
  return te(key) ? t(key) : value;
}
function releaseUrl() {
  clearTimeout(releaseTimer);
  if (objectUrl) URL.revokeObjectURL(objectUrl);
  objectUrl = null;
}
function clear() {
  responseController?.abort();
  responseController = null;
  response.value = null;
  preview.value = false;
  handed.value = false;
  releaseUrl();
}
async function load(preserve = false, explicit = false) {
  if (!auth.can('core.privacy.requests.read')) return;
  const request = reads.begin();
  const id = String(route.params.id ?? '');
  loading.value = true;
  error.value = null;
  if (!preserve) {
    actions.begin();
    clear();
    row.value = null;
    blocks.value = [];
    purposes.value = [];
    operation.value = null;
    actionError.value = null;
    uncertain.value = false;
    notice.value = null;
    executionId.value = null;
    busy.value = false;
  }
  try {
    const [next, nextBlocks, nextPurposes] = await Promise.all([
      privacyOperator.get(id),
      privacyOperator.blocks(id),
      privacyOperator.options(),
    ]);
    if (!reads.current(request)) return;
    row.value = next;
    blocks.value = nextBlocks;
    purposes.value = nextPurposes;
    if (explicit) {
      uncertain.value = false;
      operation.value = null;
      actionError.value = null;
    }
  } catch (caught) {
    if (reads.current(request)) {
      error.value = caught;
      if (caught instanceof ApiError && [403, 404].includes(caught.status)) {
        row.value = null;
        blocks.value = [];
        purposes.value = [];
        clear();
        operation.value = null;
      }
    }
  } finally {
    if (reads.current(request)) loading.value = false;
  }
}
function open(kind: OperatorAction, code = '', id = '') {
  if (!canAct.value) return;
  actionError.value = null;
  deadlineCode.value = code;
  blockId.value = id;
  operation.value = kind;
}
const extendable = (deadline: PrivacyDeadline) =>
  !terminal.value &&
  !deadline.satisfiedAt &&
  !deadline.extendedDueAt &&
  ['access_response', 'processing_information_response', 'processing_termination'].includes(
    deadline.code,
  );
async function execute(command: OperatorCommand) {
  if (!canAct.value || !row.value) return;
  const request = actions.begin();
  const currentRow = row.value;
  busy.value = true;
  actionError.value = null;
  notice.value = null;
  executionId.value = null;
  try {
    let resultId: string | null = null;
    switch (command.kind) {
      case 'transition':
        await privacyOperator.transition(currentRow.id, command.toStatus, command.reasonCode);
        break;
      case 'extend':
        await privacyOperator.extend(currentRow.id, command.deadlineCode, command.reasonCode);
        break;
      case 'confirm':
        await privacyOperator.confirm(currentRow.id);
        break;
      case 'correct':
        resultId = await privacyOperator.correct(
          currentRow.id,
          command.contributorCode,
          command.resourceId,
          command.changes,
        );
        break;
      case 'block':
        await privacyOperator.block(currentRow.id, command.input);
        break;
      case 'release':
        await privacyOperator.release(command.blockId, command.reasonCode);
        break;
      case 'terminate':
        resultId = (await privacyOperator.terminate(currentRow.id)).executionId;
        break;
      case 'response':
        responseController = new AbortController();
        {
          const document = await privacyOperator.response(currentRow, responseController.signal);
          if (!actions.current(request)) return;
          response.value = document;
          preview.value = true;
        }
        break;
    }
    if (!actions.current(request)) return;
    executionId.value = resultId;
    notice.value = 'privacyOperator.saved';
    operation.value = null;
    await load(true);
  } catch (caught) {
    if (actions.current(request)) {
      actionError.value = caught;
      uncertain.value = !(
        caught instanceof ApiError &&
        caught.status >= 400 &&
        caught.status < 500
      );
      if (caught instanceof ApiError && [403, 404].includes(caught.status)) {
        operation.value = null;
        await load(true);
      }
    }
  } finally {
    if (actions.current(request)) {
      busy.value = false;
      responseController = null;
    }
  }
}
function download() {
  if (!response.value || !row.value) return;
  releaseUrl();
  objectUrl = URL.createObjectURL(
    new Blob([response.value.raw], { type: 'application/json;charset=utf-8' }),
  );
  const link = document.createElement('a');
  link.href = objectUrl;
  link.download = `platform-privacy-${row.value.id}.json`;
  document.body.append(link);
  link.click();
  link.remove();
  handed.value = true;
  releaseTimer = setTimeout(releaseUrl, 1000);
}
watch(
  () => route.params.id,
  () => {
    void load();
  },
  { immediate: true },
);
watch(
  coreAdminSessionEpoch,
  () => {
    reads.begin();
    actions.begin();
    clear();
    row.value = null;
    blocks.value = [];
    purposes.value = [];
    operation.value = null;
    notice.value = null;
    executionId.value = null;
    actionError.value = null;
    error.value = null;
  },
  { flush: 'sync' },
);
onBeforeUnmount(clear);
</script>
<template>
  <section class="core-privacy">
    <RouterLink :to="{ name: 'admin-privacy' }">{{ $t('privacyOperator.back') }}</RouterLink>
    <header class="core-privacy__head">
      <h2>{{ row ? label('type', row.requestType) : $t('corePrivacy.details') }}</h2>
      <AppButton
        variant="secondary"
        :loading="loading"
        :disabled="loading || busy"
        @click="load(true, true)"
      >
        {{ $t('corePrivacy.refresh') }}
      </AppButton>
    </header>
    <p
      v-if="!auth.can('core.privacy.requests.read')"
      role="alert"
    >
      {{ $t('errors.accessDenied') }}
    </p>
    <p
      v-if="loading && !row"
      role="status"
    >
      {{ $t('common.loading') }}
    </p>
    <AppCard v-if="error">
      <p role="alert">{{ resolveErrorMessage(error) }}</p>
    </AppCard>
    <AppCard v-if="notice">
      <p role="status">{{ $t(notice) }}</p>
      <p v-if="executionId">{{ $t('privacyOperator.execution') }}: {{ executionId }}</p>
    </AppCard>
    <AppCard v-if="uncertain">
      <p role="alert">{{ $t('privacyOperator.uncertain') }}</p>
    </AppCard>
    <AppCard v-if="actionError && !operation">
      <p role="alert">{{ resolveErrorMessage(actionError) }}</p>
    </AppCard>
    <template v-if="row">
      <AppCard>
        <div class="core-privacy__head">
          <AppBadge>{{ label('status', row.status) }}</AppBadge>
          <AppButton
            v-if="canManage && allowedTransitions(row.status).length"
            variant="secondary"
            :disabled="!canAct"
            @click="open('transition')"
          >
            {{ $t('privacyOperator.action_transition') }}
          </AppButton>
        </div>
        <dl>
          <div>
            <dt>{{ $t('corePrivacy.reference') }}</dt>
            <dd>{{ row.id }}</dd>
          </div>
          <div>
            <dt>{{ $t('privacyOperator.subjectReference') }}</dt>
            <dd>{{ row.subjectPersonId }}</dd>
          </div>
          <div>
            <dt>{{ $t('corePrivacy.submittedAt') }}</dt>
            <dd>{{ formatDateTime(row.submittedAt, locale) }}</dd>
          </div>
          <div v-if="row.completedAt">
            <dt>{{ $t('corePrivacy.completedAt') }}</dt>
            <dd>{{ formatDateTime(row.completedAt, locale) }}</dd>
          </div>
          <div v-if="row.resolutionCode">
            <dt>{{ $t('corePrivacy.resolutionCode') }}</dt>
            <dd>{{ row.resolutionCode }}</dd>
          </div>
        </dl>
        <p v-if="terminal">{{ $t('privacyOperator.terminalHint') }}</p>
      </AppCard>
      <AppCard>
        <h3>{{ $t('corePrivacy.deadlines') }}</h3>
        <p v-if="row.deadlines.length === 0">{{ $t('privacyOperator.noDeadline') }}</p>
        <ul class="core-privacy__deadlines">
          <li
            v-for="deadline in row.deadlines"
            :key="deadline.code"
          >
            <h3>{{ label('deadline', deadline.code) }}</h3>
            <p>
              {{ $t('corePrivacy.dueAt') }}: {{ formatDateTime(deadline.effectiveDueAt, locale) }}
            </p>
            <p v-if="deadline.extendedDueAt">
              {{ $t('corePrivacy.extendedFrom') }}: {{ formatDateTime(deadline.dueAt, locale) }}
            </p>
            <p v-if="deadline.satisfiedAt">
              {{ $t('corePrivacy.satisfiedAt') }}:
              {{ formatDateTime(deadline.satisfiedAt, locale) }}
            </p>
            <AppBadge
              v-if="deadline.overdue"
              variant="danger"
            >
              {{ $t('corePrivacy.overdue') }}
            </AppBadge>
            <AppButton
              v-if="canManage && extendable(deadline)"
              variant="secondary"
              :disabled="!canAct"
              @click="open('extend', deadline.code)"
            >
              {{ $t('privacyOperator.action_extend') }}
            </AppButton>
          </li>
        </ul>
      </AppCard>
      <AppCard v-if="row.requestType === 'correction' && !terminal">
        <h3>{{ $t('privacyOperator.correction') }}</h3>
        <p>{{ $t('privacyOperator.correctionHint') }}</p>
        <div class="core-privacy__actions">
          <AppButton
            v-if="
              canManage && !correctionConfirmed && ['in_review', 'in_progress'].includes(row.status)
            "
            :disabled="!canAct"
            @click="open('confirm')"
          >
            {{ $t('privacyOperator.action_confirm') }}
          </AppButton>
          <AppButton
            v-if="canManage && correctionConfirmed && row.status === 'in_progress'"
            :disabled="!canAct"
            @click="open('correct')"
          >
            {{ $t('privacyOperator.action_correct') }}
          </AppButton>
        </div>
      </AppCard>
      <AppCard v-if="row.requestType === 'processing_termination' && !terminal">
        <h3>{{ $t('privacyOperator.action_terminate') }}</h3>
        <p>{{ $t('privacyOperator.hint_terminate') }}</p>
        <AppButton
          v-if="canManage"
          variant="danger"
          :disabled="!canAct || row.status !== 'in_progress'"
          @click="open('terminate')"
        >
          {{ $t('privacyOperator.action_terminate') }}
        </AppButton>
      </AppCard>
      <AppCard v-if="responseKind(row)">
        <h3>{{ $t('privacyOperator.response') }}</h3>
        <p>
          {{
            $t(
              response
                ? 'corePrivacy.receivedHint'
                : row.status === 'in_progress'
                  ? 'corePrivacy.oneTimeWarning'
                  : terminal
                    ? row.status === 'completed'
                      ? 'corePrivacy.completedResponseHint'
                      : 'corePrivacy.closedHint'
                    : 'corePrivacy.waitingHint',
            )
          }}
        </p>
        <AppButton
          v-if="response"
          variant="secondary"
          @click="preview = true"
        >
          {{ $t('corePrivacy.preview') }}
        </AppButton>
        <AppButton
          v-else-if="canManage && row.status === 'in_progress' && row.intakeChannel"
          :disabled="!canAct"
          @click="open('response')"
        >
          {{ $t('privacyOperator.action_response') }}
        </AppButton>
        <p v-else-if="!row.intakeChannel">{{ $t('privacyOperator.onlineDelivery') }}</p>
      </AppCard>
      <AppCard>
        <div class="core-privacy__head">
          <h3>{{ $t('privacyOperator.blocks') }}</h3>
          <AppButton
            v-if="canManage && !terminal"
            variant="secondary"
            :disabled="!canAct"
            @click="open('block')"
          >
            {{ $t('privacyOperator.action_block') }}
          </AppButton>
        </div>
        <p>{{ $t('privacyOperator.blocksHint') }}</p>
        <p v-if="blocks.length === 0">{{ $t('privacyOperator.noBlocks') }}</p>
        <div
          v-for="block in blocks"
          :key="block.id"
          class="privacy-operator__block"
        >
          <h3>{{ $t('privacyOperator.scope_' + block.scope_type) }}</h3>
          <p>
            {{ block.reason_code }}
            <span v-if="block.purpose_code">· {{ block.purpose_code }}</span>
          </p>
          <p v-if="block.resource_type">{{ block.resource_type }} · {{ block.resource_id }}</p>
          <p>
            {{ formatDateTime(block.started_at, locale) }}
            <span v-if="block.expires_at">
              · {{ $t('privacyOperator.expires') }}: {{ formatDateTime(block.expires_at, locale) }}
            </span>
          </p>
          <AppButton
            v-if="canManage"
            variant="secondary"
            :disabled="!canAct"
            @click="open('release', '', block.id)"
          >
            {{ $t('privacyOperator.action_release') }}
          </AppButton>
        </div>
      </AppCard>
      <PrivacyOperationDialog
        v-if="operation"
        :key="row.id + operation"
        v-model="dialogOpen"
        :kind="operation"
        :row="row"
        :purposes="purposes"
        :deadline-code="deadlineCode"
        :block-id="blockId"
        :busy="busy"
        :uncertain="uncertain"
        :error="actionError"
        @submit="execute"
      />
    </template>
    <AppJsonResponseDialog
      v-model="preview"
      :title="$t('privacyOperator.response')"
      :description="$t('corePrivacy.receivedHint')"
      :data="response?.document"
    >
      <template #actions>
        <AppButton
          :disabled="!response"
          @click="download"
        >
          {{ $t('corePrivacy.download') }}
        </AppButton>
        <p
          v-if="handed"
          role="status"
        >
          {{ $t('corePrivacy.downloadStarted') }}
        </p>
      </template>
    </AppJsonResponseDialog>
  </section>
</template>
<style scoped>
.privacy-operator__block {
  display: grid;
  gap: var(--space-8);
  padding-top: var(--space-16);
  border-top: 1px solid var(--border);
}
</style>
