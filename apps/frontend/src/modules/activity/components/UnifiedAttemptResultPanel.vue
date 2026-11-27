<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import { ApiError } from '@/shared/api/errors';
import { activityApi, type ActivityAttempt } from '../activityApi';
import { actT } from '../activityI18n';

const props = defineProps<{
  organizationId: string;
  attempt: ActivityAttempt;
  canReview?: boolean;
}>();

const emit = defineEmits<{
  updated: [attempt: ActivityAttempt];
}>();

interface QuestionDraft {
  pointsEarned: number;
  comment: string;
}

const drafts = reactive<Record<string, QuestionDraft>>({});
const reviewNotes = ref('');
const saving = ref(false);
const errorMsg = ref('');
const statusMsg = ref('');

const breakdown = computed<Array<Record<string, unknown>>>(() => {
  const b = props.attempt.evaluation?.breakdown;
  return Array.isArray(b) ? b : [];
});

watch(
  () => props.attempt,
  (att) => {
    reviewNotes.value = att.reviewNotes ?? '';
    errorMsg.value = '';
    const list = Array.isArray(att.evaluation?.breakdown) ? att.evaluation.breakdown : [];
    for (const key of Object.keys(drafts)) {
      delete drafts[key];
    }
    for (const item of list) {
      const qid = String(item.questionId ?? '');
      if (!qid) continue;
      drafts[qid] = {
        pointsEarned: Number(item.pointsEarned ?? 0),
        comment: typeof item.comment === 'string' ? item.comment : '',
      };
    }
  },
  { immediate: true },
);

const isPendingReview = computed(() => props.attempt.state === 'PENDING_REVIEW');
const isPendingPublication = computed(
  () => !props.canReview && props.attempt.resultsPublished === false && props.attempt.state === 'EVALUATED',
);

const stateTone = computed<'ok' | 'warn' | 'danger' | 'neutral'>(() => {
  if (props.attempt.state === 'PENDING_REVIEW') return 'warn';
  if (props.attempt.passed === true) return 'ok';
  if (props.attempt.passed === false) return 'danger';
  return 'neutral';
});

function formatAnswer(val: unknown): string {
  if (val === null || val === undefined || val === '') return '—';
  if (typeof val === 'boolean') return val ? 'true' : 'false';
  if (Array.isArray(val)) return val.join(', ');
  if (typeof val === 'object') return JSON.stringify(val);
  return String(val);
}

function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleString();
  } catch {
    return iso;
  }
}

async function submitReview(publish: boolean): Promise<void> {
  if (!props.organizationId) return;
  saving.value = true;
  errorMsg.value = '';
  statusMsg.value = '';
  try {
    const questionReviews = breakdown.value
      .map((item) => {
        const qid = String(item.questionId ?? '');
        if (!qid || !drafts[qid]) return null;
        return {
          questionId: qid,
          pointsEarned: Number(drafts[qid].pointsEarned ?? 0),
          comment: drafts[qid].comment.trim() || undefined,
        };
      })
      .filter((x): x is NonNullable<typeof x> => x !== null);

    const updated = await activityApi.reviewAttempt(props.organizationId, props.attempt.id, {
      questionReviews: questionReviews.length > 0 ? questionReviews : undefined,
      rawScore:
        questionReviews.length === 0 ? Number(props.attempt.rawScore ?? 0) : undefined,
      reviewNotes: reviewNotes.value.trim(),
      publish,
    });
    statusMsg.value = actT('saveReviewSuccess');
    emit('updated', updated);
  } catch (err) {
    errorMsg.value = err instanceof ApiError ? err.message : actT('loadError');
  } finally {
    saving.value = false;
  }
}

async function togglePublish(publish: boolean): Promise<void> {
  if (!props.organizationId) return;
  saving.value = true;
  errorMsg.value = '';
  statusMsg.value = '';
  try {
    const updated = await activityApi.publishAttemptResults(
      props.organizationId,
      props.attempt.id,
      publish,
    );
    emit('updated', updated);
  } catch (err) {
    errorMsg.value = err instanceof ApiError ? err.message : actT('loadError');
  } finally {
    saving.value = false;
  }
}

async function regrade(): Promise<void> {
  if (!props.organizationId) return;
  saving.value = true;
  errorMsg.value = '';
  statusMsg.value = '';
  try {
    const updated = await activityApi.regradeAttempt(props.organizationId, props.attempt.id);
    emit('updated', updated);
  } catch (err) {
    errorMsg.value = err instanceof ApiError ? err.message : actT('loadError');
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <div class="uact-result-panel">
    <header class="uact-result-panel__header">
      <div>
        <h2 class="uact-result-panel__title">{{ attempt.activityTitle }}</h2>
        <p class="uact-result-panel__meta">
          {{ formatDate(attempt.submittedAt || attempt.startedAt) }}
        </p>
      </div>
      <div class="uact-result-panel__badges">
        <AppBadge :tone="stateTone">
          {{
            isPendingReview
              ? actT('manualReviewBadge')
              : attempt.passed
                ? actT('passedBadge')
                : actT('failedBadge')
          }}
        </AppBadge>
        <AppBadge
          v-if="canReview"
          :tone="attempt.resultsPublished ? 'ok' : 'neutral'"
        >
          {{ attempt.resultsPublished ? actT('publishedBadge') : actT('unpublishedBadge') }}
        </AppBadge>
      </div>
    </header>

    <p
      v-if="isPendingReview"
      class="uact-result-panel__notice uact-result-panel__notice--warn"
    >
      {{ actT('pendingReviewNote') }}
    </p>
    <p
      v-else-if="isPendingPublication"
      class="uact-result-panel__notice"
    >
      {{ actT('pendingPublicationNote') }}
    </p>

    <div
      v-if="!isPendingPublication"
      class="uact-result-panel__stats"
    >
      <div class="uact-stat-card">
        <span class="uact-stat-card__value">
          {{ attempt.rawScore ?? 0 }} / {{ attempt.maxScore ?? 0 }}
        </span>
        <span class="uact-stat-card__label">{{ actT('activityPointsLabel') }}</span>
      </div>
      <div class="uact-stat-card">
        <span class="uact-stat-card__value">{{ attempt.percentage ?? 0 }}%</span>
        <span class="uact-stat-card__label">
          {{ actT('completionPercentLabel') }}
          ({{ actT('passingThresholdLabel') }}: {{ attempt.passingScorePercent ?? 60 }}%)
        </span>
      </div>
    </div>

    <p
      v-if="!attempt.canRevealAnswers && !canReview && !isPendingPublication"
      class="uact-result-panel__notice"
    >
      {{ actT('answersHiddenNote') }}
    </p>

    <div
      v-if="breakdown.length > 0 && !isPendingPublication"
      class="uact-result-panel__breakdown"
    >
      <article
        v-for="(item, idx) in breakdown"
        :key="String(item.questionId || idx)"
        class="uact-breakdown-item"
        :class="{
          'uact-breakdown-item--correct': item.correct === true,
          'uact-breakdown-item--incorrect': item.correct === false,
          'uact-breakdown-item--pending': item.pendingReview === true,
        }"
      >
        <div class="uact-breakdown-item__top">
          <div class="uact-breakdown-item__q">
            <span class="uact-breakdown-item__num">#{{ idx + 1 }}</span>
            <span>{{ item.prompt || item.questionId }}</span>
            <AppBadge
              v-if="item.partial"
              tone="warn"
            >
              {{ actT('partialCreditBadge') }}
            </AppBadge>
            <AppBadge
              v-if="item.pendingReview"
              tone="warn"
            >
              {{ actT('manualReviewBadge') }}
            </AppBadge>
          </div>
          <strong class="uact-breakdown-item__pts">
            {{ item.pointsEarned ?? 0 }} / {{ item.maxPoints ?? 1 }} {{ actT('pointsLabel') }}
          </strong>
        </div>

        <div class="uact-breakdown-item__answers">
          <div>
            <span class="uact-muted">{{ actT('studentAnswerLabel') }}:</span>
            <span>{{ formatAnswer(item.actual) }}</span>
          </div>
          <div v-if="item.expected !== undefined">
            <span class="uact-muted">{{ actT('correctAnswerLabel') }}:</span>
            <span>{{ formatAnswer(item.expected) }}</span>
          </div>
          <p
            v-if="item.explanation"
            class="uact-breakdown-item__exp"
          >
            {{ actT('explanationLabel') }}: {{ item.explanation }}
          </p>
          <p
            v-if="item.comment && !canReview"
            class="uact-breakdown-item__comment"
          >
            {{ actT('teacherCommentLabel') }}: {{ item.comment }}
          </p>
        </div>

        <div
          v-if="canReview && item.questionId && drafts[String(item.questionId)]"
          class="uact-breakdown-item__review-row"
        >
          <label class="uact-field">
            <span>{{ actT('questionScoreLabel') }} (0–{{ item.maxPoints ?? 1 }})</span>
            <input
              v-model.number="drafts[String(item.questionId)]!.pointsEarned"
              type="number"
              step="0.5"
              min="0"
              :max="Number(item.maxPoints ?? 100)"
              class="uact-input uact-input--sm"
            />
          </label>
          <label class="uact-field uact-field--grow">
            <span>{{ actT('teacherCommentLabel') }}</span>
            <input
              v-model="drafts[String(item.questionId)]!.comment"
              type="text"
              class="uact-input"
            />
          </label>
        </div>
      </article>
    </div>

    <div
      v-if="attempt.reviewNotes && !canReview && !isPendingPublication"
      class="uact-result-panel__feedback"
    >
      <strong>{{ actT('overallFeedbackLabel') }}:</strong>
      <p>{{ attempt.reviewNotes }}</p>
    </div>

    <section
      v-if="canReview"
      class="uact-result-panel__grader"
    >
      <label class="uact-field">
        <span>{{ actT('overallFeedbackLabel') }}</span>
        <textarea
          v-model="reviewNotes"
          rows="3"
          class="uact-textarea"
        />
      </label>

      <p
        v-if="errorMsg"
        class="uact-error"
      >
        {{ errorMsg }}
      </p>
      <p
        v-if="statusMsg"
        class="uact-success"
      >
        {{ statusMsg }}
      </p>

      <div class="uact-result-panel__actions">
        <AppButton
          :disabled="saving"
          variant="primary"
          @click="void submitReview(true)"
        >
          {{ actT('saveAndPublish') }}
        </AppButton>
        <AppButton
          :disabled="saving"
          variant="secondary"
          @click="void submitReview(false)"
        >
          {{ actT('saveDraftReview') }}
        </AppButton>
        <AppButton
          v-if="attempt.state === 'EVALUATED' || attempt.state === 'COMPLETED'"
          :disabled="saving"
          variant="secondary"
          @click="void togglePublish(!attempt.resultsPublished)"
        >
          {{ attempt.resultsPublished ? actT('unpublishResultsBtn') : actT('publishResultsBtn') }}
        </AppButton>
        <AppButton
          :disabled="saving"
          variant="ghost"
          @click="void regrade()"
        >
          {{ actT('regradeBtn') }}
        </AppButton>
      </div>
    </section>

    <section
      v-if="attempt.resultHistory && attempt.resultHistory.length > 0"
      class="uact-result-panel__history"
    >
      <h3>{{ actT('resultHistoryTitle') }}</h3>
      <ul class="uact-history-list">
        <li
          v-for="ev in attempt.resultHistory"
          :key="ev.id"
          class="uact-history-item"
        >
          <span class="uact-history-item__reason">{{ ev.reason }}</span>
          <span class="uact-muted">
            {{ ev.fromState ? `${ev.fromState} → ${ev.toState}` : ev.toState }}
          </span>
          <span
            v-if="ev.metadata && ev.metadata.newScore !== undefined"
            class="uact-history-item__score"
          >
            {{ ev.metadata.previousScore ?? '—' }} → {{ ev.metadata.newScore }}
          </span>
          <span class="uact-muted">{{ formatDate(ev.createdAt) }}</span>
        </li>
      </ul>
    </section>
  </div>
</template>

<style scoped>
.uact-result-panel {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  color: var(--text-1);
}

.uact-result-panel__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  flex-wrap: wrap;
}

.uact-result-panel__title {
  margin: 0;
  font-size: 1.25rem;
}

.uact-result-panel__meta {
  margin: 0.25rem 0 0;
  color: var(--text-2);
  font-size: 0.875rem;
}

.uact-result-panel__badges {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.uact-result-panel__notice {
  margin: 0;
  padding: 0.75rem 1rem;
  border-radius: 0.5rem;
  background: var(--surface-2);
  border: 1px solid var(--border);
  font-size: 0.9rem;
}

.uact-result-panel__notice--warn {
  border-color: var(--warn, #d97706);
}

.uact-result-panel__stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 0.75rem;
}

.uact-stat-card {
  padding: 0.875rem 1rem;
  border-radius: 0.5rem;
  background: var(--surface-2);
  border: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.uact-stat-card__value {
  font-size: 1.35rem;
  font-weight: 700;
}

.uact-stat-card__label {
  font-size: 0.8rem;
  color: var(--text-2);
}

.uact-result-panel__breakdown {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.uact-breakdown-item {
  padding: 1rem;
  border-radius: 0.5rem;
  background: var(--surface-1);
  border: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.uact-breakdown-item--correct {
  border-left: 4px solid var(--ok, #16a34a);
}

.uact-breakdown-item--incorrect {
  border-left: 4px solid var(--danger, #dc2626);
}

.uact-breakdown-item--pending {
  border-left: 4px solid var(--warn, #d97706);
}

.uact-breakdown-item__top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.uact-breakdown-item__q {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-weight: 600;
  flex-wrap: wrap;
}

.uact-breakdown-item__num {
  color: var(--text-2);
}

.uact-breakdown-item__answers {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.9rem;
}

.uact-breakdown-item__exp,
.uact-breakdown-item__comment {
  margin: 0.25rem 0 0;
  font-size: 0.875rem;
  color: var(--text-2);
}

.uact-breakdown-item__review-row {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  padding-top: 0.5rem;
  border-top: 1px dashed var(--border);
}

.uact-result-panel__grader {
  padding: 1rem;
  border-radius: 0.5rem;
  background: var(--surface-2);
  border: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.uact-result-panel__actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.uact-field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.85rem;
}

.uact-field--grow {
  flex: 1;
  min-width: 220px;
}

.uact-input,
.uact-textarea {
  padding: 0.5rem 0.65rem;
  border-radius: 0.375rem;
  border: 1px solid var(--border);
  background: var(--surface-1);
  color: var(--text-1);
  font: inherit;
}

.uact-input--sm {
  width: 110px;
}

.uact-result-panel__history h3 {
  margin: 0 0 0.5rem;
  font-size: 1rem;
}

.uact-history-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.uact-history-item {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  font-size: 0.85rem;
  padding: 0.4rem 0.65rem;
  border-radius: 0.375rem;
  background: var(--surface-2);
}

.uact-history-item__reason {
  font-weight: 600;
}

.uact-muted {
  color: var(--text-2);
}

.uact-error {
  margin: 0;
  color: var(--danger, #dc2626);
  font-size: 0.875rem;
}

.uact-success {
  margin: 0;
  color: var(--ok, #16a34a);
  font-size: 0.875rem;
}
</style>
