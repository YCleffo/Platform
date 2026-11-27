<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiError } from '@/shared/api/errors';
import { realtimeClient } from '@/shared/realtime/realtimeClient';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import { activityApi, type ActivityAttempt } from './activityApi';
import { actT } from './activityI18n';
import { loadLocalDraft, removeLocalDraft, saveLocalDraft } from './draftStorage';
import TypingArenaPlugin from './plugins/TypingArenaPlugin.vue';
import AssessmentRunnerPlugin from './plugins/AssessmentRunnerPlugin.vue';
import MathSprintPlugin from './plugins/MathSprintPlugin.vue';
import LogicLabPlugin from './plugins/LogicLabPlugin.vue';
import MemoryStudioPlugin from './plugins/MemoryStudioPlugin.vue';

const props = defineProps<{
  activityId?: string;
  attemptId?: string;
  assignmentId?: string;
  organizationId?: string;
  embedded?: boolean;
  initialConfig?: Record<string, unknown>;
}>();

const emit = defineEmits<{
  started: [attemptId: string];
  finished: [
    result: {
      attemptId: string;
      score: number;
      maxScore: number;
      percentage: number;
      passed: boolean;
      breakdown?: Array<Record<string, unknown>>;
      feedback?: string;
    },
  ];
  close: [];
}>();

const route = useRoute();
const router = useRouter();

const orgId = ref(props.organizationId ?? '');
const personId = ref('');
const attempt = ref<ActivityAttempt | null>(null);
const loading = ref(true);
const submitting = ref(false);
const errorMsg = ref('');
const statusMsg = ref('');
const saveState = ref<'idle' | 'saving' | 'saved' | 'offline' | 'conflict' | 'error'>('idle');
const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true);
const currentSubmission = ref<Record<string, unknown>>({});
const currentTelemetry = ref<Record<string, unknown>>({});
const typingHistory = ref<Array<Record<string, unknown>>>([]);
const secondsLeft = ref<number | null>(null);

let serverDeadlinePerfMs: number | null = null;
let countdownTimer: ReturnType<typeof setInterval> | null = null;
let autosaveTimer: ReturnType<typeof setTimeout> | null = null;
let draftInFlight: Promise<void> | null = null;
let expiryRefreshTriggered = false;
let unsubRealtime: (() => void) | null = null;
let tabChannel: BroadcastChannel | null = null;
let submitIdempotencyKey: string | null = null;
const tabId = typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : String(Math.random());

const resolvedAssignmentId = computed<string | undefined>(() => {
  if (props.assignmentId) return props.assignmentId;
  const q = route?.query?.assignmentId;
  return typeof q === 'string' && q !== '' ? q : undefined;
});

const resolvedAttemptId = computed<string | undefined>(() => {
  if (props.attemptId) return props.attemptId;
  const q = route?.query?.attemptId;
  return typeof q === 'string' && q !== '' ? q : undefined;
});

const isDeadlineReached = computed(() => secondsLeft.value === 0);

const isReadonly = computed(
  () =>
    attempt.value !== null &&
    (!['CREATED', 'IN_PROGRESS'].includes(attempt.value.state) || isDeadlineReached.value),
);

const formattedTimeLeft = computed(() => {
  if (secondsLeft.value === null) return null;
  const mins = Math.floor(secondsLeft.value / 60);
  const secs = secondsLeft.value % 60;
  return `${mins}:${secs < 10 ? '0' : ''}${secs}`;
});

function syncMonotonicDeadline(
  remainingSeconds?: number | null,
  expiresAt?: string | null,
  serverNow?: string,
  state?: string,
): void {
  if (state && !['CREATED', 'IN_PROGRESS'].includes(state)) {
    serverDeadlinePerfMs = null;
    secondsLeft.value = expiresAt ? 0 : null;
    return;
  }
  if (typeof remainingSeconds === 'number') {
    serverDeadlinePerfMs = performance.now() + Math.max(0, remainingSeconds) * 1000;
    secondsLeft.value = Math.max(0, remainingSeconds);
    return;
  }
  if (expiresAt) {
    const baseMs = serverNow ? Date.parse(serverNow) : Date.now();
    const diffMs = Math.max(0, Date.parse(expiresAt) - baseMs);
    serverDeadlinePerfMs = performance.now() + diffMs;
    secondsLeft.value = Math.ceil(diffMs / 1000);
    return;
  }
  serverDeadlinePerfMs = null;
  secondsLeft.value = null;
}

function activeSessionKey(oid: string, pid: string, aid: string, asgnId?: string): string {
  return `uact_active:${oid}:${pid}:${aid}:${asgnId ?? 'practice'}`;
}

function readStoredAttemptId(
  oid: string,
  pid: string,
  aid: string,
  asgnId?: string,
): string | null {
  try {
    return sessionStorage.getItem(activeSessionKey(oid, pid, aid, asgnId));
  } catch {
    return null;
  }
}

function storeActiveAttemptId(
  oid: string,
  pid: string,
  aid: string,
  attId: string,
  asgnId?: string,
): void {
  try {
    sessionStorage.setItem(activeSessionKey(oid, pid, aid, asgnId), attId);
  } catch {
    // Ignore storage quota errors
  }
}

function clearStoredAttemptId(oid: string, pid: string, aid: string, asgnId?: string): void {
  try {
    sessionStorage.removeItem(activeSessionKey(oid, pid, aid, asgnId));
  } catch {
    // Ignore storage errors
  }
}

function setupTabChannel(attId: string): void {
  tabChannel?.close();
  tabChannel = null;
  if (typeof BroadcastChannel === 'undefined') return;
  tabChannel = new BroadcastChannel(`uact_attempt_${attId}`);
  tabChannel.onmessage = (ev: MessageEvent) => {
    const msg = ev.data as { type?: string; tabId?: string; revision?: number } | null;
    if (!msg || msg.tabId === tabId) return;
    if (msg.type === 'revision_updated' || msg.type === 'attempt_submitted') {
      saveState.value = 'conflict';
      void syncAttemptStateFromServer(attId);
    }
  };
}

function emitFinishedIfFinalized(att: ActivityAttempt): void {
  if (['EVALUATED', 'COMPLETED', 'PENDING_REVIEW', 'EXPIRED'].includes(att.state)) {
    emit('finished', {
      attemptId: att.id,
      score: Number(att.rawScore ?? 0),
      maxScore: Number(att.maxScore ?? 0),
      percentage: Number(att.percentage ?? 0),
      passed: Boolean(att.passed ?? false),
      breakdown: att.evaluation?.breakdown,
      feedback: att.evaluation?.feedback,
    });
  }
}

async function resolveContext(): Promise<void> {
  if (!orgId.value || !personId.value) {
    const res = await coreAdminHttp.request<{ items: Array<{ id: string }>; personId: string }>(
      '/chat/organizations',
      { method: 'GET' },
    );
    personId.value = res.personId;
    if (!orgId.value && res.items[0]) orgId.value = res.items[0].id;
  }
}

async function syncAttemptStateFromServer(attId: string): Promise<void> {
  if (!orgId.value) return;
  const fresh = await activityApi.getAttempt(orgId.value, attId);
  attempt.value = fresh;
  syncMonotonicDeadline(fresh.remainingSeconds, fresh.expiresAt, fresh.serverNow, fresh.state);
  currentSubmission.value = { ...(fresh.answers ?? {}) };
  if (!['CREATED', 'IN_PROGRESS'].includes(fresh.state) && personId.value) {
    clearStoredAttemptId(
      orgId.value,
      personId.value,
      fresh.activityId,
      fresh.assignmentId ?? undefined,
    );
    void removeLocalDraft(orgId.value, personId.value, fresh.id);
    emitFinishedIfFinalized(fresh);
  }
}

function updateClock(): void {
  if (
    !attempt.value ||
    serverDeadlinePerfMs === null ||
    !['CREATED', 'IN_PROGRESS'].includes(attempt.value.state)
  ) {
    if (attempt.value && !attempt.value.expiresAt) {
      secondsLeft.value = null;
    }
    return;
  }
  const diff = Math.max(0, Math.ceil((serverDeadlinePerfMs - performance.now()) / 1000));
  secondsLeft.value = diff;
  if (diff === 0) {
    if (autosaveTimer) {
      clearTimeout(autosaveTimer);
      autosaveTimer = null;
    }
    if (!expiryRefreshTriggered) {
      expiryRefreshTriggered = true;
      void syncAttemptStateFromServer(attempt.value.id).catch(() => {
        // Ignore transient errors while refreshing expired attempt
      });
    }
  }
}

async function initAttempt(): Promise<void> {
  if (autosaveTimer) {
    clearTimeout(autosaveTimer);
    autosaveTimer = null;
  }
  expiryRefreshTriggered = false;
  submitIdempotencyKey = null;
  saveState.value = 'idle';
  loading.value = true;
  errorMsg.value = '';
  statusMsg.value = '';
  attempt.value = null;
  try {
    await resolveContext();
    if (!orgId.value) throw new Error('Organization context is unavailable');

    if (resolvedAttemptId.value) {
      attempt.value = await activityApi.getAttempt(orgId.value, resolvedAttemptId.value);
    } else if (props.activityId) {
      const asgnId = resolvedAssignmentId.value;
      const storedAttId = personId.value
        ? readStoredAttemptId(orgId.value, personId.value, props.activityId, asgnId)
        : null;

      if (storedAttId) {
        try {
          const existing = await activityApi.getAttempt(orgId.value, storedAttId);
          if (['CREATED', 'IN_PROGRESS'].includes(existing.state)) {
            attempt.value = existing;
          } else if (personId.value) {
            clearStoredAttemptId(orgId.value, personId.value, props.activityId, asgnId);
          }
        } catch {
          if (personId.value) {
            clearStoredAttemptId(orgId.value, personId.value, props.activityId, asgnId);
          }
        }
      }

      if (!attempt.value) {
        attempt.value = await activityApi.startAttempt(orgId.value, props.activityId, {
          assignmentId: asgnId,
          resumeActive: true,
          ...(!asgnId && props.initialConfig ? { config: props.initialConfig } : {}),
        });
      }

      if (attempt.value && ['CREATED', 'IN_PROGRESS'].includes(attempt.value.state)) {
        emit('started', attempt.value.id);
        if (personId.value) {
          storeActiveAttemptId(
            orgId.value,
            personId.value,
            attempt.value.activityId,
            attempt.value.id,
            attempt.value.assignmentId ?? asgnId,
          );
        }
        if (!props.embedded && router && route && route.query.attemptId !== attempt.value.id) {
          void router.replace({
            query: {
              ...route.query,
              attemptId: attempt.value.id,
            },
          });
        }
      }
    } else {
      throw new Error('Activity ID or Attempt ID is required');
    }

    if (attempt.value) {
      syncMonotonicDeadline(
        attempt.value.remainingSeconds,
        attempt.value.expiresAt,
        attempt.value.serverNow,
        attempt.value.state,
      );
      setupTabChannel(attempt.value.id);
    }

    if (attempt.value && personId.value) {
      if (attempt.value.state === 'IN_PROGRESS') {
        const local = await loadLocalDraft(orgId.value, personId.value, attempt.value.id);
        if (
          local &&
          local.attemptId === attempt.value.id &&
          local.revision >= attempt.value.revision
        ) {
          currentSubmission.value = { answers: local.answers };
        } else {
          currentSubmission.value = { ...(attempt.value.answers ?? {}) };
        }
      } else {
        currentSubmission.value = { ...(attempt.value.answers ?? {}) };
        clearStoredAttemptId(
          orgId.value,
          personId.value,
          attempt.value.activityId,
          attempt.value.assignmentId ?? undefined,
        );
        void removeLocalDraft(orgId.value, personId.value, attempt.value.id);
      }

      void realtimeClient.connect(orgId.value);
      unsubRealtime?.();
      unsubRealtime = realtimeClient.subscribe(`user:${personId.value}`, (ev) => {
        if (
          ev.eventType === 'activity.attempt.evaluated' &&
          String(ev.payload.attemptId) === attempt.value?.id
        ) {
          void syncAttemptStateFromServer(attempt.value!.id);
        }
      });
    }

    if (countdownTimer) clearInterval(countdownTimer);
    updateClock();
    countdownTimer = setInterval(updateClock, 1000);
    if (attempt.value?.pluginCode === 'platform-typing' && orgId.value) {
      void refreshTypingHistory();
    }
  } catch (err) {
    errorMsg.value = err instanceof Error ? err.message : 'Failed to load activity';
  } finally {
    loading.value = false;
  }
}

watch(
  () => [props.activityId, props.attemptId, props.assignmentId, route?.query?.assignmentId],
  () => {
    void initAttempt();
  },
);

function onSubmissionUpdate(sub: Record<string, unknown>, tel: Record<string, unknown>): void {
  if (
    !attempt.value ||
    isReadonly.value ||
    secondsLeft.value === 0 ||
    !orgId.value ||
    !personId.value
  ) {
    return;
  }
  currentSubmission.value = sub;
  currentTelemetry.value = tel;

  const ans = (sub.answers as Record<string, unknown> | undefined) ?? sub;
  void saveLocalDraft(orgId.value, personId.value, attempt.value.id, attempt.value.revision, ans);

  if (!isOnline.value) {
    saveState.value = 'offline';
    return;
  }

  if (autosaveTimer) clearTimeout(autosaveTimer);
  autosaveTimer = setTimeout(() => {
    void saveDraftNow();
  }, 800);
}

async function saveDraftNow(): Promise<void> {
  if (!attempt.value || isReadonly.value || secondsLeft.value === 0) return;
  if (!isOnline.value) {
    saveState.value = 'offline';
    return;
  }
  if (draftInFlight) {
    await draftInFlight;
    return;
  }
  saveState.value = 'saving';
  const run = (async () => {
    try {
      const res = await activityApi.saveDraft(orgId.value, attempt.value!.id, {
        answers:
          (currentSubmission.value.answers as Record<string, unknown>) ?? currentSubmission.value,
        telemetry: currentTelemetry.value,
        revision: attempt.value!.revision,
      });
      if (attempt.value) {
        attempt.value.revision = res.revision;
        if (res.remainingSeconds !== undefined || res.serverNow) {
          syncMonotonicDeadline(
            res.remainingSeconds,
            attempt.value.expiresAt,
            res.serverNow,
            attempt.value.state,
          );
        }
      }
      saveState.value = 'saved';
      statusMsg.value = actT('revisionSynced');
      tabChannel?.postMessage({
        type: 'revision_updated',
        revision: res.revision,
        tabId,
      });
    } catch (err) {
      if (err instanceof ApiError && err.status === 409 && attempt.value) {
        if (
          err.code === 'revision_conflict' ||
          err.code === 'attempt_expired' ||
          err.code === 'attempt_not_editable'
        ) {
          await syncAttemptStateFromServer(attempt.value.id);
          if (err.code === 'revision_conflict') {
            saveState.value = 'conflict';
            statusMsg.value = actT('multiTabConflict');
            return;
          }
        }
      }
      if (!navigator.onLine || !(err instanceof ApiError)) {
        isOnline.value = navigator.onLine;
        saveState.value = 'offline';
        return;
      }
      saveState.value = 'error';
      errorMsg.value = err instanceof Error ? err.message : 'Draft save failed';
    } finally {
      draftInFlight = null;
    }
  })();
  draftInFlight = run;
  await run;
}

async function submitNow(
  sub?: Record<string, unknown>,
  tel?: Record<string, unknown>,
): Promise<void> {
  if (!attempt.value || isReadonly.value || secondsLeft.value === 0 || submitting.value) return;
  if (autosaveTimer) {
    clearTimeout(autosaveTimer);
    autosaveTimer = null;
  }
  if (draftInFlight) {
    await draftInFlight;
  }
  if (!attempt.value || isReadonly.value || secondsLeft.value === 0) return;

  submitting.value = true;
  errorMsg.value = '';
  if (!submitIdempotencyKey) {
    submitIdempotencyKey =
      typeof crypto !== 'undefined' && crypto.randomUUID
        ? crypto.randomUUID()
        : `${attempt.value.id}-${Date.now()}`;
  }

  try {
    const finalSub = sub ?? currentSubmission.value;
    const finalTel = tel ?? currentTelemetry.value;
    attempt.value = await activityApi.submitAttempt(orgId.value, attempt.value.id, {
      submission: finalSub,
      telemetry: finalTel,
      revision: attempt.value.revision,
      idempotencyKey: submitIdempotencyKey,
    });
    syncMonotonicDeadline(
      attempt.value.remainingSeconds,
      attempt.value.expiresAt,
      attempt.value.serverNow,
      attempt.value.state,
    );
    tabChannel?.postMessage({ type: 'attempt_submitted', tabId });
    if (personId.value) {
      clearStoredAttemptId(
        orgId.value,
        personId.value,
        attempt.value.activityId,
        attempt.value.assignmentId ?? undefined,
      );
      void removeLocalDraft(orgId.value, personId.value, attempt.value.id);
    }
    if (attempt.value.pluginCode === 'platform-typing') {
      void refreshTypingHistory();
    }
    emitFinishedIfFinalized(attempt.value);
  } catch (err) {
    if (err instanceof ApiError && err.status === 409 && attempt.value) {
      if (
        err.code === 'revision_conflict' ||
        err.code === 'attempt_expired' ||
        err.code === 'attempt_already_finalized'
      ) {
        await syncAttemptStateFromServer(attempt.value.id);
        return;
      }
    }
    errorMsg.value = err instanceof Error ? err.message : 'Submit failed';
  } finally {
    submitting.value = false;
  }
}

function handleOnline(): void {
  isOnline.value = true;
  if (saveState.value === 'offline' || saveState.value === 'error') {
    void saveDraftNow();
  }
}

function handleOffline(): void {
  isOnline.value = false;
  saveState.value = 'offline';
}

async function refreshTypingHistory(): Promise<void> {
  if (!orgId.value) return;
  try {
    const res = await activityApi.analytics(orgId.value, { pluginCode: 'platform-typing' });
    typingHistory.value = res.recentAttempts.filter((r) =>
      ['EVALUATED', 'COMPLETED'].includes(String(r.state ?? '')),
    );
  } catch {
    // ignore non-fatal history load error
  }
}

async function restartTypingAttempt(nextConfig: object): Promise<void> {
  if (!attempt.value || !orgId.value || attempt.value.assignmentId) return;
  if (autosaveTimer) {
    clearTimeout(autosaveTimer);
    autosaveTimer = null;
  }
  errorMsg.value = '';
  statusMsg.value = '';
  try {
    if (personId.value) {
      clearStoredAttemptId(orgId.value, personId.value, attempt.value.activityId, undefined);
      void removeLocalDraft(orgId.value, personId.value, attempt.value.id);
    }
    const fresh = await activityApi.startAttempt(orgId.value, attempt.value.activityId, {
      resumeActive: false,
      config: nextConfig as Record<string, unknown>,
    });
    attempt.value = fresh;
    currentSubmission.value = {};
    currentTelemetry.value = {};
    if (personId.value) {
      storeActiveAttemptId(orgId.value, personId.value, fresh.activityId, fresh.id, undefined);
    }
    if (route.query.attemptId && route.query.attemptId !== fresh.id) {
      void router.replace({ query: { ...route.query, attemptId: fresh.id } });
    }
    updateClock();
  } catch (err) {
    errorMsg.value = err instanceof Error ? err.message : 'Failed to restart typing session';
  }
}

onMounted(() => {
  window.addEventListener('online', handleOnline);
  window.addEventListener('offline', handleOffline);
  void initAttempt();
});

onBeforeUnmount(() => {
  window.removeEventListener('online', handleOnline);
  window.removeEventListener('offline', handleOffline);
  if (countdownTimer) clearInterval(countdownTimer);
  if (autosaveTimer) clearTimeout(autosaveTimer);
  unsubRealtime?.();
  tabChannel?.close();
});
</script>

<template>
  <section class="uact-runner">
    <p v-if="loading">{{ actT('loadingText') }}</p>
    <p
      v-if="errorMsg"
      class="uact-err"
    >
      {{ errorMsg }}
    </p>

    <template v-if="attempt">
      <header class="uact-runner__head">
        <div class="uact-runner__title-row">
          <h1>{{ attempt.activityTitle }}</h1>
          <AppBadge variant="primary">{{ attempt.state }}</AppBadge>
          <span
            v-if="formattedTimeLeft !== null"
            class="uact-runner__timer"
            :class="{ 'is-urgent': secondsLeft !== null && secondsLeft <= 60 }"
          >
            {{ actT('timeRemaining') }}: <strong>{{ formattedTimeLeft }}</strong>
          </span>
        </div>
        <div
          v-if="!isReadonly"
          class="uact-runner__btns"
        >
          <AppButton
            size="s"
            variant="secondary"
            @click="saveDraftNow"
          >
            {{ actT('saveDraft') }}
          </AppButton>
          <AppButton
            v-if="embedded"
            size="s"
            variant="ghost"
            @click="emit('close')"
          >
            {{ actT('cancelBtn') }}
          </AppButton>
        </div>
      </header>

      <div
        v-if="attempt.state === 'PENDING_REVIEW'"
        class="uact-result"
      >
        <strong>{{ actT('pendingReviewNote') }}</strong>
      </div>

      <div
        v-else-if="attempt.state === 'EXPIRED'"
        class="uact-result uact-result--expired"
      >
        <strong>{{ actT('timeExpiredNote') }}</strong>
        <p v-if="attempt.percentage !== null">
          {{ actT('scoreSummary') }}: {{ attempt.percentage }}% ({{ attempt.rawScore }} /
          {{ attempt.maxScore }})
        </p>
      </div>

      <div
        v-else-if="
          (attempt.state === 'EVALUATED' || attempt.state === 'COMPLETED') &&
          attempt.pluginCode !== 'platform-typing'
        "
        class="uact-result"
      >
        <h2>
          {{ actT('scoreSummary') }}: {{ attempt.percentage }}% ({{ attempt.rawScore }} /
          {{ attempt.maxScore }})
        </h2>
        <p v-if="attempt.evaluation?.feedback">{{ attempt.evaluation.feedback }}</p>
      </div>

      <TypingArenaPlugin
        v-if="attempt.pluginCode === 'platform-typing'"
        :exercise="attempt.exercise"
        :initial-answers="attempt.answers"
        :readonly="isReadonly"
        :config="attempt.config"
        :attempt="attempt"
        :assigned="!!attempt.assignmentId"
        :history="typingHistory"
        @update:submission="onSubmissionUpdate"
        @submit="submitNow"
        @restart="restartTypingAttempt"
      />
      <AssessmentRunnerPlugin
        v-else-if="attempt.pluginCode === 'platform-assessment'"
        :exercise="attempt.exercise"
        :initial-answers="(attempt.answers.answers as Record<string, unknown>) ?? attempt.answers"
        :readonly="isReadonly"
        :save-state="saveState"
        :is-online="isOnline"
        :submitting="submitting"
        :evaluation="attempt.evaluation"
        @update:submission="onSubmissionUpdate"
        @submit="submitNow"
        @retry-sync="saveDraftNow"
      />
      <MathSprintPlugin
        v-else-if="attempt.pluginCode === 'platform-math-sprint'"
        :exercise="attempt.exercise"
        :initial-answers="(attempt.answers.answers as Record<string, unknown>) ?? attempt.answers"
        :readonly="isReadonly"
        @update:submission="onSubmissionUpdate"
        @submit="submitNow"
      />
      <LogicLabPlugin
        v-else-if="attempt.pluginCode === 'platform-logic'"
        :exercise="attempt.exercise"
        :initial-answers="(attempt.answers.answers as Record<string, unknown>) ?? attempt.answers"
        :readonly="isReadonly"
        :evaluation="attempt.evaluation"
        @update:submission="onSubmissionUpdate"
        @submit="submitNow"
      />
      <MemoryStudioPlugin
        v-else-if="attempt.pluginCode === 'platform-memory'"
        :exercise="attempt.exercise"
        :initial-answers="(attempt.answers.answers as Record<string, unknown>) ?? attempt.answers"
        :readonly="isReadonly"
        @update:submission="onSubmissionUpdate"
        @submit="submitNow"
      />
    </template>
  </section>
</template>

<style scoped>
.uact-runner {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 16px;
}
.uact-runner__head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}
.uact-runner__title-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.uact-runner__title-row h1 {
  margin: 0;
  font-size: 1.25rem;
}
.uact-runner__timer {
  padding: 4px 10px;
  border-radius: var(--radius-control-s);
  border: 1px solid var(--border);
  background: var(--surface);
  font-variant-numeric: tabular-nums;
}
.uact-runner__timer.is-urgent {
  border-color: var(--danger);
  color: var(--danger);
}
.uact-runner__btns {
  display: flex;
  align-items: center;
  gap: 10px;
}
.uact-result {
  padding: 14px;
  border: 1px solid var(--accent);
  border-radius: var(--radius-control-m);
  background: var(--surface);
}
.uact-result--expired {
  border-color: var(--danger);
}
.uact-err {
  color: var(--danger);
}
</style>
