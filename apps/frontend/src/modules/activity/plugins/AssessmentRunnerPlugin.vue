<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import { actT } from '../activityI18n';

interface QuestionView {
  id: string;
  questionType: string;
  points: number;
  content: {
    prompt?: string;
    options?: Array<string | { id?: string; value?: string; label?: string }>;
    pairs?: Array<{ left: string; right?: string }>;
    items?: string[];
  };
}

interface BreakdownItem {
  questionId?: string;
  correct?: boolean;
  earnedPoints?: number;
  maxPoints?: number;
  expected?: unknown;
  correctAnswer?: unknown;
  explanation?: string;
}

const props = defineProps<{
  exercise: Record<string, unknown>;
  initialAnswers?: Record<string, unknown>;
  readonly?: boolean;
  saveState?: 'idle' | 'saving' | 'saved' | 'offline' | 'conflict' | 'error';
  isOnline?: boolean;
  submitting?: boolean;
  evaluation?: {
    breakdown?: BreakdownItem[];
    feedback?: string;
  } | null;
}>();

const emit = defineEmits<{
  'update:submission': [submission: Record<string, unknown>, telemetry: Record<string, unknown>];
  submit: [submission: Record<string, unknown>, telemetry: Record<string, unknown>];
  'retry-sync': [];
}>();

const questions = computed<QuestionView[]>(() =>
  Array.isArray(props.exercise.questions) ? (props.exercise.questions as QuestionView[]) : [],
);

const answers = ref<Record<string, unknown>>({ ...(props.initialAnswers ?? {}) });
const currentIndex = ref(0);
const visited = ref<Record<string, boolean>>({});
const flagged = ref<Record<string, boolean>>({});
const showSubmitConfirm = ref(false);

watch(
  () => props.initialAnswers,
  (next) => {
    if (next && typeof next === 'object') {
      answers.value = { ...next };
    }
  },
);

watch(
  [questions, currentIndex],
  ([qs, idx]) => {
    const q = qs[idx];
    if (q && !visited.value[q.id]) {
      visited.value = { ...visited.value, [q.id]: true };
    }
  },
  { immediate: true },
);

const currentQuestion = computed<QuestionView | null>(
  () => questions.value[currentIndex.value] ?? null,
);

function isAnswered(qid: string): boolean {
  const val = answers.value[qid];
  if (val === undefined || val === null || val === '') return false;
  if (Array.isArray(val)) return val.length > 0;
  if (typeof val === 'object') {
    const vals = Object.values(val as Record<string, unknown>);
    return vals.some((v) => v !== undefined && v !== null && String(v).trim() !== '');
  }
  return true;
}

const answeredCount = computed(() =>
  questions.value.reduce((acc, q) => (isAnswered(q.id) ? acc + 1 : acc), 0),
);

const skippedCount = computed(() =>
  questions.value.reduce((acc, q) => (visited.value[q.id] && !isAnswered(q.id) ? acc + 1 : acc), 0),
);

const unansweredCount = computed(() => Math.max(0, questions.value.length - answeredCount.value));

const flaggedCount = computed(() =>
  questions.value.reduce((acc, q) => (flagged.value[q.id] ? acc + 1 : acc), 0),
);

const progressPercent = computed(() =>
  questions.value.length > 0 ? Math.round((answeredCount.value / questions.value.length) * 100) : 0,
);

const breakdownByQid = computed<Record<string, BreakdownItem>>(() => {
  const map: Record<string, BreakdownItem> = {};
  for (const item of props.evaluation?.breakdown ?? []) {
    if (item.questionId) {
      map[item.questionId] = item;
    }
  }
  return map;
});

function normalizeOption(opt: string | { id?: string; value?: string; label?: string }): {
  value: string;
  label: string;
} {
  if (typeof opt === 'string') return { value: opt, label: opt };
  const val = String(opt.value ?? opt.id ?? opt.label ?? '');
  const lbl = String(opt.label ?? opt.value ?? opt.id ?? '');
  return { value: val, label: lbl };
}

function toggleMulti(qid: string, val: string): void {
  if (props.readonly) return;
  const cur = Array.isArray(answers.value[qid]) ? [...(answers.value[qid] as string[])] : [];
  const idx = cur.indexOf(val);
  if (idx >= 0) cur.splice(idx, 1);
  else cur.push(val);
  answers.value = { ...answers.value, [qid]: cur };
}

function setMatch(qid: string, leftKey: string, rightVal: string): void {
  if (props.readonly) return;
  const cur =
    typeof answers.value[qid] === 'object' && answers.value[qid] !== null
      ? { ...(answers.value[qid] as Record<string, string>) }
      : {};
  cur[leftKey] = rightVal;
  answers.value = { ...answers.value, [qid]: cur };
}

function moveOrdering(qid: string, items: string[], idx: number, dir: -1 | 1): void {
  if (props.readonly) return;
  const list = Array.isArray(answers.value[qid])
    ? [...(answers.value[qid] as string[])]
    : [...items];
  const nextIdx = idx + dir;
  if (nextIdx < 0 || nextIdx >= list.length) return;
  const tmp = list[idx]!;
  list[idx] = list[nextIdx]!;
  list[nextIdx] = tmp;
  answers.value = { ...answers.value, [qid]: list };
}

function toggleFlag(qid: string): void {
  flagged.value = { ...flagged.value, [qid]: !flagged.value[qid] };
}

function goToQuestion(idx: number): void {
  if (idx >= 0 && idx < questions.value.length) {
    currentIndex.value = idx;
  }
}

watch(
  answers,
  (val) => {
    if (!props.readonly) {
      emit(
        'update:submission',
        { answers: val },
        {
          flagged: flagged.value,
          currentIndex: currentIndex.value,
        },
      );
    }
  },
  { deep: true },
);

function requestSubmit(): void {
  if (props.readonly || props.submitting) return;
  showSubmitConfirm.value = true;
}

function confirmSubmit(): void {
  if (props.readonly || props.submitting) return;
  showSubmitConfirm.value = false;
  emit(
    'submit',
    { answers: answers.value },
    {
      flagged: flagged.value,
      currentIndex: currentIndex.value,
    },
  );
}
</script>

<template>
  <div class="uact-assess">
    <!-- Offline banner -->
    <div
      v-if="isOnline === false || saveState === 'offline'"
      class="uact-assess__banner uact-assess__banner--offline"
      role="status"
    >
      <span>{{ actT('offlineBanner') }}</span>
      <AppButton
        v-if="!readonly"
        size="s"
        variant="secondary"
        @click="emit('retry-sync')"
      >
        {{ actT('retrySync') }}
      </AppButton>
    </div>

    <!-- Conflict banner -->
    <div
      v-else-if="saveState === 'conflict'"
      class="uact-assess__banner uact-assess__banner--conflict"
      role="status"
    >
      <span>{{ actT('multiTabConflict') }}</span>
    </div>

    <!-- Progress + Save Status Bar -->
    <div class="uact-assess__progress-bar-wrap">
      <div class="uact-assess__progress-meta">
        <span>
          {{ actT('answeredCount') }}: <strong>{{ answeredCount }}</strong> / {{ questions.length }}
          <template v-if="skippedCount > 0">
            · {{ actT('skippedCount') }}: <strong>{{ skippedCount }}</strong>
          </template>
          <template v-if="flaggedCount > 0">
            · {{ actT('flaggedCount') }}: <strong>{{ flaggedCount }}</strong>
          </template>
        </span>
        <span
          v-if="!readonly"
          class="uact-assess__save-pill"
          :class="`uact-assess__save-pill--${saveState ?? 'idle'}`"
        >
          <template v-if="saveState === 'saving'">{{ actT('saving') }}</template>
          <template v-else-if="saveState === 'saved'">{{ actT('saved') }}</template>
          <template v-else-if="saveState === 'error'">{{ actT('saveError') }}</template>
        </span>
      </div>
      <div
        class="uact-assess__track"
        role="progressbar"
        :aria-valuenow="progressPercent"
        aria-valuemin="0"
        aria-valuemax="100"
      >
        <div
          class="uact-assess__fill"
          :style="{ width: `${progressPercent}%` }"
        />
      </div>
    </div>

    <!-- Question Navigator -->
    <nav
      v-if="questions.length > 1"
      class="uact-assess__nav"
      aria-label="Question navigator"
    >
      <button
        v-for="(q, idx) in questions"
        :key="q.id"
        type="button"
        class="uact-assess__pill"
        :class="{
          'is-active': idx === currentIndex,
          'is-answered': isAnswered(q.id),
          'is-skipped': visited[q.id] && !isAnswered(q.id) && idx !== currentIndex,
          'is-flagged': !!flagged[q.id],
        }"
        :aria-current="idx === currentIndex ? 'step' : undefined"
        @click="goToQuestion(idx)"
      >
        <span>{{ idx + 1 }}</span>
        <span
          v-if="flagged[q.id]"
          class="uact-assess__flag-dot"
          aria-hidden="true"
        />
      </button>
    </nav>

    <!-- Active Question Card -->
    <article
      v-if="currentQuestion"
      :key="currentQuestion.id"
      class="uact-assess__card"
    >
      <header class="uact-assess__qhead">
        <div class="uact-assess__qmeta">
          <span class="uact-assess__qindex">
            {{ actT('questionOf') }} {{ currentIndex + 1 }} {{ actT('ofLabel') }} {{ questions.length }}
          </span>
          <span class="uact-assess__qpts">
            {{ currentQuestion.points }} {{ actT('pointsLabel') }} · {{ currentQuestion.questionType }}
          </span>
        </div>
        <button
          v-if="!readonly"
          type="button"
          class="uact-mini-btn"
          :class="{ 'is-flagged': !!flagged[currentQuestion.id] }"
          @click="toggleFlag(currentQuestion.id)"
        >
          {{ flagged[currentQuestion.id] ? actT('unflagQuestion') : actT('flagQuestion') }}
        </button>
      </header>

      <p class="uact-assess__prompt">
        {{ currentQuestion.content.prompt || currentQuestion.id }}
      </p>

      <!-- Single Choice / True-False -->
      <div
        v-if="currentQuestion.questionType === 'single_choice' || currentQuestion.questionType === 'true_false'"
        class="uact-assess__opts"
      >
        <label
          v-for="opt in currentQuestion.content.options ||
          (currentQuestion.questionType === 'true_false' ? ['true', 'false'] : [])"
          :key="normalizeOption(opt).value"
          class="uact-assess__opt"
          :class="{ 'is-selected': answers[currentQuestion.id] === normalizeOption(opt).value }"
        >
          <input
            v-model="answers[currentQuestion.id]"
            type="radio"
            :name="`q-${currentQuestion.id}`"
            :value="normalizeOption(opt).value"
            :disabled="readonly"
          />
          <span>{{ normalizeOption(opt).label }}</span>
        </label>
      </div>

      <!-- Multi Choice -->
      <div
        v-else-if="currentQuestion.questionType === 'multi_choice'"
        class="uact-assess__opts"
      >
        <label
          v-for="opt in currentQuestion.content.options || []"
          :key="normalizeOption(opt).value"
          class="uact-assess__opt"
          :class="{
            'is-selected':
              Array.isArray(answers[currentQuestion.id]) &&
              (answers[currentQuestion.id] as string[]).includes(normalizeOption(opt).value),
          }"
        >
          <input
            type="checkbox"
            :checked="
              Array.isArray(answers[currentQuestion.id]) &&
              (answers[currentQuestion.id] as string[]).includes(normalizeOption(opt).value)
            "
            :disabled="readonly"
            @change="toggleMulti(currentQuestion.id, normalizeOption(opt).value)"
          />
          <span>{{ normalizeOption(opt).label }}</span>
        </label>
      </div>

      <!-- Short Text / Numeric -->
      <input
        v-else-if="currentQuestion.questionType === 'short_text' || currentQuestion.questionType === 'numeric'"
        v-model="answers[currentQuestion.id]"
        class="uact-assess__input"
        :type="currentQuestion.questionType === 'numeric' ? 'number' : 'text'"
        :disabled="readonly"
      />

      <!-- Long Text -->
      <textarea
        v-else-if="currentQuestion.questionType === 'long_text'"
        v-model="answers[currentQuestion.id] as string"
        class="uact-assess__input"
        rows="4"
        :disabled="readonly"
      />

      <!-- Matching -->
      <div
        v-else-if="currentQuestion.questionType === 'matching'"
        class="uact-assess__opts"
      >
        <div
          v-for="pair in currentQuestion.content.pairs || []"
          :key="pair.left"
          class="uact-assess__pair"
        >
          <span>{{ pair.left }}</span>
          <input
            class="uact-assess__input uact-assess__pair-input"
            :value="(answers[currentQuestion.id] as Record<string, string> | undefined)?.[pair.left] ?? ''"
            :disabled="readonly"
            @input="setMatch(currentQuestion.id, pair.left, ($event.target as HTMLInputElement).value)"
          />
        </div>
      </div>

      <!-- Ordering -->
      <div
        v-else-if="currentQuestion.questionType === 'ordering'"
        class="uact-assess__opts"
      >
        <div
          v-for="(item, oi) in Array.isArray(answers[currentQuestion.id])
            ? (answers[currentQuestion.id] as string[])
            : currentQuestion.content.items || []"
          :key="`${item}-${oi}`"
          class="uact-assess__pair"
        >
          <span>{{ oi + 1 }}. {{ item }}</span>
          <div
            v-if="!readonly"
            class="uact-assess__order-btns"
          >
            <button
              type="button"
              class="uact-mini-btn"
              :disabled="oi === 0"
              @click="moveOrdering(currentQuestion.id, currentQuestion.content.items || [], oi, -1)"
            >
              ↑
            </button>
            <button
              type="button"
              class="uact-mini-btn"
              :disabled="oi === ((Array.isArray(answers[currentQuestion.id]) ? (answers[currentQuestion.id] as string[]) : currentQuestion.content.items || []).length - 1)"
              @click="moveOrdering(currentQuestion.id, currentQuestion.content.items || [], oi, 1)"
            >
              ↓
            </button>
          </div>
        </div>
      </div>

      <!-- Post-evaluation feedback for current question (only when revealed by server policy) -->
      <div
        v-if="readonly && breakdownByQid[currentQuestion.id]"
        class="uact-assess__feedback"
        :class="breakdownByQid[currentQuestion.id]!.correct ? 'is-correct' : 'is-incorrect'"
      >
        <strong>
          {{ breakdownByQid[currentQuestion.id]!.correct ? actT('correctLabel') : actT('incorrectLabel') }}
          ({{ breakdownByQid[currentQuestion.id]!.earnedPoints ?? 0 }} / {{ breakdownByQid[currentQuestion.id]!.maxPoints ?? currentQuestion.points }})
        </strong>
        <p v-if="breakdownByQid[currentQuestion.id]!.explanation">
          {{ actT('explanationLabel') }}: {{ breakdownByQid[currentQuestion.id]!.explanation }}
        </p>
      </div>
    </article>

    <!-- Bottom Navigation & Submit Controls -->
    <footer class="uact-assess__footer">
      <div class="uact-assess__step-btns">
        <AppButton
          size="s"
          variant="secondary"
          :disabled="currentIndex === 0"
          @click="goToQuestion(currentIndex - 1)"
        >
          ← {{ actT('prevQuestion') }}
        </AppButton>
        <AppButton
          size="s"
          variant="secondary"
          :disabled="currentIndex >= questions.length - 1"
          @click="goToQuestion(currentIndex + 1)"
        >
          {{ actT('nextQuestion') }} →
        </AppButton>
      </div>

      <AppButton
        v-if="!readonly"
        variant="primary"
        :disabled="submitting"
        @click="requestSubmit"
      >
        {{ actT('submit') }}
      </AppButton>
    </footer>

    <!-- Submit Confirmation Modal -->
    <div
      v-if="showSubmitConfirm"
      class="uact-assess__modal-backdrop"
      role="dialog"
      aria-modal="true"
    >
      <div class="uact-assess__modal">
        <h3>{{ actT('confirmSubmitTitle') }}</h3>
        <p>{{ actT('confirmSubmitBody') }}</p>
        <ul class="uact-assess__summary">
          <li>{{ actT('answeredCount') }}: <strong>{{ answeredCount }} / {{ questions.length }}</strong></li>
          <li v-if="unansweredCount > 0">
            {{ actT('unansweredLabel') }}: <strong>{{ unansweredCount }}</strong>
          </li>
          <li v-if="flaggedCount > 0">
            {{ actT('flaggedCount') }}: <strong>{{ flaggedCount }}</strong>
          </li>
        </ul>
        <div class="uact-assess__modal-actions">
          <AppButton
            variant="secondary"
            @click="showSubmitConfirm = false"
          >
            {{ actT('cancelBtn') }}
          </AppButton>
          <AppButton
            variant="primary"
            :disabled="submitting"
            @click="confirmSubmit"
          >
            {{ actT('confirmSubmitBtn') }}
          </AppButton>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.uact-assess {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.uact-assess__banner {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 14px;
  border-radius: var(--radius-control-m);
  font-size: 0.9rem;
}
.uact-assess__banner--offline {
  background: var(--surface);
  border: 1px solid var(--danger);
  color: var(--danger);
}
.uact-assess__banner--conflict {
  background: var(--surface);
  border: 1px solid var(--accent);
  color: var(--text-1);
}
.uact-assess__progress-bar-wrap {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.uact-assess__progress-meta {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  font-size: 0.875rem;
  color: var(--text-2);
}
.uact-assess__save-pill {
  font-size: 0.8rem;
  font-weight: 500;
}
.uact-assess__save-pill--saved {
  color: var(--success, #16a34a);
}
.uact-assess__save-pill--error {
  color: var(--danger);
}
.uact-assess__track {
  height: 8px;
  width: 100%;
  border-radius: 999px;
  background: var(--surface);
  border: 1px solid var(--border);
  overflow: hidden;
}
.uact-assess__fill {
  height: 100%;
  background: var(--accent);
  transition: width 0.2s ease;
}
.uact-assess__nav {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.uact-assess__pill {
  position: relative;
  min-width: 34px;
  height: 34px;
  padding: 0 8px;
  border-radius: var(--radius-control-s);
  border: 1px solid var(--border);
  background: var(--card);
  color: var(--text-1);
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.uact-assess__pill.is-answered {
  border-color: var(--accent);
  background: var(--surface);
}
.uact-assess__pill.is-skipped {
  border-style: dashed;
  color: var(--text-2);
}
.uact-assess__pill.is-active {
  background: var(--accent);
  color: #fff;
  border-color: var(--accent);
}
.uact-assess__flag-dot {
  position: absolute;
  top: 3px;
  right: 3px;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--danger);
}
.uact-assess__card {
  padding: 16px;
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--card);
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.uact-assess__qhead {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
}
.uact-assess__qmeta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.uact-assess__qindex {
  font-weight: 600;
  color: var(--text-1);
}
.uact-assess__qpts {
  font-size: 0.85rem;
  color: var(--text-2);
}
.uact-assess__prompt {
  margin: 0;
  font-size: 1.05rem;
  line-height: 1.45;
  color: var(--text-1);
}
.uact-assess__opts {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.uact-assess__opt,
.uact-assess__pair {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 12px;
  border-radius: var(--radius-control-s);
  border: 1px solid var(--border);
  background: var(--surface);
  cursor: pointer;
}
.uact-assess__opt {
  justify-content: flex-start;
}
.uact-assess__opt.is-selected {
  border-color: var(--accent);
}
.uact-assess__input {
  width: 100%;
  padding: 10px 12px;
  border-radius: var(--radius-control-s);
  border: 1px solid var(--border);
  background: var(--bg);
  color: var(--text-1);
}
.uact-assess__pair-input {
  max-width: 260px;
}
.uact-assess__order-btns {
  display: flex;
  gap: 6px;
}
.uact-mini-btn {
  border: 1px solid var(--border);
  background: var(--card);
  color: var(--text-1);
  border-radius: var(--radius-control-s);
  padding: 4px 10px;
  cursor: pointer;
}
.uact-mini-btn.is-flagged {
  border-color: var(--danger);
  color: var(--danger);
}
.uact-assess__feedback {
  padding: 10px 12px;
  border-radius: var(--radius-control-s);
  background: var(--surface);
  border: 1px solid var(--border);
}
.uact-assess__feedback.is-correct {
  border-color: var(--success, #16a34a);
}
.uact-assess__feedback.is-incorrect {
  border-color: var(--danger);
}
.uact-assess__footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}
.uact-assess__step-btns {
  display: flex;
  gap: 8px;
}
.uact-assess__modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  z-index: 60;
}
.uact-assess__modal {
  width: 100%;
  max-width: 420px;
  padding: 20px;
  border-radius: var(--radius-control-m);
  background: var(--card);
  border: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.uact-assess__modal h3,
.uact-assess__modal p {
  margin: 0;
}
.uact-assess__summary {
  margin: 0;
  padding-left: 18px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.uact-assess__modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
@media (max-width: 600px) {
  .uact-assess__pair {
    flex-direction: column;
    align-items: stretch;
  }
  .uact-assess__pair-input {
    max-width: none;
  }
  .uact-assess__footer {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
