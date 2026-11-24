<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useEducationI18n } from '../i18n';
import { assessmentApi } from './assessmentApi';
import AssessmentResultView from './AssessmentResultView.vue';
import type { AssessmentQuestion, AssessmentResult } from '../activityTypes';

const props = defineProps<{
  assessmentId?: string;
  attemptId?: string;
}>();

const emit = defineEmits<{
  finished: [result: AssessmentResult];
  close: [];
}>();

const { t } = useEducationI18n();

const loading = ref(true);
const submitting = ref(false);
const showSubmitConfirm = ref(false);

const activeAttemptId = ref<string | null>(props.attemptId || null);
const revision = ref(1);
const title = ref('');
const instructions = ref('');
const timeLimitSeconds = ref<number | null>(null);
const passingScorePercent = ref(60);
const questions = ref<AssessmentQuestion[]>([]);
const answers = ref<Record<string, unknown>>({});
const flagged = ref<Record<string, boolean>>({});
const currentIndex = ref(0);

const elapsedSeconds = ref(0);
const remainingSeconds = ref<number | null>(null);
const timerInterval = ref<number | null>(null);

const evaluatedResult = ref<AssessmentResult | null>(null);

const currentQuestion = computed(() => questions.value[currentIndex.value] || null);

const answeredCount = computed(() => {
  let count = 0;
  for (const q of questions.value) {
    const ans = answers.value[q.id];
    if (
      ans !== undefined &&
      ans !== null &&
      ans !== '' &&
      !(Array.isArray(ans) && ans.length === 0)
    ) {
      count++;
    }
  }
  return count;
});

const formattedTimeLeft = computed(() => {
  if (remainingSeconds.value === null) return null;
  const m = Math.floor(remainingSeconds.value / 60);
  const s = remainingSeconds.value % 60;
  return `${m}:${s < 10 ? '0' : ''}${s}`;
});

async function startOrLoad(): Promise<void> {
  loading.value = true;
  try {
    let data;
    if (props.attemptId) {
      data = await assessmentApi.getStudentAssessmentAttempt(props.attemptId);
      activeAttemptId.value = data.id;
    } else if (props.assessmentId) {
      data = await assessmentApi.startStudentAssessment(props.assessmentId);
      activeAttemptId.value = data.id;
    } else {
      return;
    }

    title.value = data.title;
    instructions.value = data.instructions || '';
    timeLimitSeconds.value = data.timeLimitSeconds;
    passingScorePercent.value = data.passingScorePercent;
    questions.value = (data.questions as AssessmentQuestion[]) || [];
    answers.value = (data.answers as Record<string, unknown>) || {};
    revision.value = (data as any).revision || 1;

    if (data.state === 'EVALUATED' && data.result) {
      evaluatedResult.value = data.result as AssessmentResult;
      return;
    }

    if (timeLimitSeconds.value) {
      const started = new Date(data.startedAt).getTime();
      const now = Date.now();
      const elapsed = Math.floor((now - started) / 1000);
      elapsedSeconds.value = elapsed;
      remainingSeconds.value = Math.max(0, timeLimitSeconds.value - elapsed);
    }

    startTimer();
  } catch {
    // error
  } finally {
    loading.value = false;
  }
}

function startTimer(): void {
  if (timerInterval.value) clearInterval(timerInterval.value);
  timerInterval.value = window.setInterval(() => {
    elapsedSeconds.value++;
    if (remainingSeconds.value !== null) {
      if (remainingSeconds.value > 0) {
        remainingSeconds.value--;
      } else {
        // Time expired: auto-submit!
        void submitTest();
      }
    }
  }, 1000);
}

function selectSingleOption(qId: string, optId: string): void {
  answers.value[qId] = optId;
  debouncedSave();
}

function toggleMultiOption(qId: string, optId: string): void {
  const current = Array.isArray(answers.value[qId]) ? [...(answers.value[qId] as string[])] : [];
  const idx = current.indexOf(optId);
  if (idx >= 0) {
    current.splice(idx, 1);
  } else {
    current.push(optId);
  }
  answers.value[qId] = current;
  debouncedSave();
}

let saveTimer: number | null = null;
function debouncedSave(): void {
  if (!activeAttemptId.value) return;
  if (saveTimer) clearTimeout(saveTimer);
  saveTimer = window.setTimeout(async () => {
    if (activeAttemptId.value) {
      try {
        const saveRes = await assessmentApi.saveStudentAssessmentAttempt(activeAttemptId.value, {
          answers: answers.value,
          revision: revision.value,
        });
        if (saveRes && saveRes.revision) {
          revision.value = saveRes.revision;
        }
      } catch {
        // Optimistic refresh on conflict
        try {
          if (activeAttemptId.value) {
            const fresh = await assessmentApi.getStudentAssessmentAttempt(activeAttemptId.value);
            revision.value = (fresh as any).revision || 1;
          }
        } catch {
          // ignore
        }
      }
    }
  }, 800);
}

function toggleFlag(qId: string): void {
  flagged.value[qId] = !flagged.value[qId];
}

async function submitTest(): Promise<void> {
  if (!activeAttemptId.value || submitting.value) return;
  submitting.value = true;
  if (timerInterval.value) clearInterval(timerInterval.value);

  try {
    const res = await assessmentApi.submitStudentAssessmentAttempt(activeAttemptId.value, {
      answers: answers.value,
      elapsedSeconds: elapsedSeconds.value,
    });
    evaluatedResult.value = res.result;
    showSubmitConfirm.value = false;
    emit('finished', {
      ...res.result,
      attemptId: res.id,
      score: res.score ?? 0,
      maxScore: res.maxScore ?? 0,
      percentage: res.percentage ?? 0,
      passed: res.passed ?? false,
    });
  } catch {
    // handle error
  } finally {
    submitting.value = false;
  }
}

onMounted(() => {
  void startOrLoad();
});

onUnmounted(() => {
  if (timerInterval.value) clearInterval(timerInterval.value);
  if (saveTimer) clearTimeout(saveTimer);
});
</script>

<template>
  <div class="edu-runner-container">
    <div
      v-if="loading"
      class="edu-runner-loading"
    >
      {{ t('education.transfer_verifying') }}…
    </div>

    <!-- Results Screen -->
    <AssessmentResultView
      v-else-if="evaluatedResult"
      :result="evaluatedResult"
      :title="title"
      @close="emit('close')"
    />

    <!-- Active Test Taking UI -->
    <div
      v-else
      class="edu-runner"
    >
      <header class="edu-runner__header">
        <div>
          <h1 class="edu-runner__title">{{ title }}</h1>
          <p
            v-if="instructions"
            class="edu-runner__inst"
          >
            {{ instructions }}
          </p>
        </div>

        <div class="edu-runner__meta">
          <div
            v-if="formattedTimeLeft !== null"
            class="edu-runner__timer"
            :class="{
              'edu-runner__timer--urgent': remainingSeconds !== null && remainingSeconds < 120,
            }"
          >
            <span class="edu-runner__timer-icon">⏱</span>
            <span class="edu-runner__timer-val">{{ formattedTimeLeft }}</span>
          </div>

          <button
            class="edu-btn edu-btn--primary"
            type="button"
            @click="showSubmitConfirm = true"
          >
            {{ t('education.submitAssessment') }}
          </button>
        </div>
      </header>

      <!-- Progress Navigation Bar -->
      <nav
        class="edu-runner__nav"
        :aria-label="t('education.studentAssessmentTitle')"
      >
        <button
          v-for="(q, idx) in questions"
          :key="q.id"
          type="button"
          class="edu-runner__nav-btn"
          :class="{
            'edu-runner__nav-btn--current': idx === currentIndex,
            'edu-runner__nav-btn--answered': answers[q.id] !== undefined && answers[q.id] !== '',
            'edu-runner__nav-btn--flagged': flagged[q.id],
          }"
          @click="currentIndex = idx"
        >
          {{ idx + 1 }}
          <span
            v-if="flagged[q.id]"
            class="edu-runner__flag-dot"
          >
            •
          </span>
        </button>
      </nav>

      <!-- Main Question Surface -->
      <main
        v-if="currentQuestion"
        class="edu-runner__card"
      >
        <div class="edu-runner__card-header">
          <span class="edu-runner__card-idx">{{ currentIndex + 1 }} / {{ questions.length }}</span>
          <span class="edu-badge edu-badge--neutral">
            {{ currentQuestion.points }} {{ t('education.score') }}
          </span>

          <label class="edu-runner__flag-toggle">
            <input
              type="checkbox"
              :checked="Boolean(flagged[currentQuestion.id])"
              @change="toggleFlag(currentQuestion.id)"
            />
            <span>🚩</span>
          </label>
        </div>

        <h2 class="edu-runner__prompt">{{ currentQuestion.prompt }}</h2>

        <!-- Single Choice Options -->
        <div
          v-if="currentQuestion.type === 'single_choice' && currentQuestion.options"
          class="edu-runner__options"
        >
          <button
            v-for="opt in currentQuestion.options"
            :key="opt.id"
            type="button"
            class="edu-runner__option"
            :class="{ 'edu-runner__option--selected': answers[currentQuestion.id] === opt.id }"
            @click="selectSingleOption(currentQuestion.id, opt.id)"
          >
            <span class="edu-runner__option-radio"></span>
            <span class="edu-runner__option-text">{{ opt.label }}</span>
          </button>
        </div>

        <!-- Multi Choice Options -->
        <div
          v-else-if="currentQuestion.type === 'multi_choice' && currentQuestion.options"
          class="edu-runner__options"
        >
          <button
            v-for="opt in currentQuestion.options"
            :key="opt.id"
            type="button"
            class="edu-runner__option"
            :class="{
              'edu-runner__option--selected':
                Array.isArray(answers[currentQuestion.id]) &&
                (answers[currentQuestion.id] as string[]).includes(opt.id),
            }"
            @click="toggleMultiOption(currentQuestion.id, opt.id)"
          >
            <span class="edu-runner__option-checkbox"></span>
            <span class="edu-runner__option-text">{{ opt.label }}</span>
          </button>
        </div>

        <!-- True / False -->
        <div
          v-else-if="currentQuestion.type === 'true_false'"
          class="edu-runner__tf-options"
        >
          <button
            type="button"
            class="edu-runner__option edu-runner__option--tf"
            :class="{ 'edu-runner__option--selected': answers[currentQuestion.id] === true }"
            @click="
              answers[currentQuestion.id] = true;
              debouncedSave();
            "
          >
            {{ t('education.correctAnswer') }} (True)
          </button>
          <button
            type="button"
            class="edu-runner__option edu-runner__option--tf"
            :class="{ 'edu-runner__option--selected': answers[currentQuestion.id] === false }"
            @click="
              answers[currentQuestion.id] = false;
              debouncedSave();
            "
          >
            {{ t('education.incorrectAnswer') }} (False)
          </button>
        </div>

        <!-- Short Text Input -->
        <div
          v-else-if="currentQuestion.type === 'short_text'"
          class="edu-runner__text-input-wrap"
        >
          <input
            v-model="answers[currentQuestion.id]"
            type="text"
            class="edu-input edu-input--lg"
            :placeholder="t('education.quizPromptPlaceholder')"
            @input="debouncedSave"
          />
        </div>

        <!-- Numeric Input -->
        <div
          v-else-if="currentQuestion.type === 'numeric'"
          class="edu-runner__numeric-input-wrap"
        >
          <input
            v-model.number="answers[currentQuestion.id]"
            type="number"
            step="any"
            class="edu-input edu-input--lg"
            :placeholder="t('education.quizPromptPlaceholder')"
            @input="debouncedSave"
          />
        </div>

        <!-- Footer Navigation Controls -->
        <footer class="edu-runner__card-footer">
          <button
            class="edu-btn edu-btn--secondary"
            type="button"
            :disabled="currentIndex === 0"
            @click="currentIndex--"
          >
            ← {{ t('education.restoreAttempt') }}
          </button>

          <button
            v-if="currentIndex < questions.length - 1"
            class="edu-btn edu-btn--primary"
            type="button"
            @click="currentIndex++"
          >
            {{ t('education.nextExercise') }} →
          </button>

          <button
            v-else
            class="edu-btn edu-btn--primary"
            type="button"
            @click="showSubmitConfirm = true"
          >
            {{ t('education.submitAssessment') }}
          </button>
        </footer>
      </main>
    </div>

    <!-- Submit Confirm Modal -->
    <div
      v-if="showSubmitConfirm"
      class="edu-modal-backdrop"
      @click.self="showSubmitConfirm = false"
    >
      <div
        class="edu-modal"
        role="dialog"
        :aria-label="t('education.submitConfirmTitle')"
      >
        <header class="edu-modal__header">
          <h2 class="edu-modal__title">{{ t('education.submitConfirmTitle') }}</h2>
          <button
            class="edu-btn edu-btn--ghost"
            type="button"
            @click="showSubmitConfirm = false"
          >
            ✕
          </button>
        </header>

        <div class="edu-modal__body">
          <p>
            {{
              t('education.submitConfirmDesc', {
                answered: answeredCount,
                total: questions.length,
              })
            }}
          </p>
        </div>

        <footer class="edu-modal__footer">
          <button
            class="edu-btn edu-btn--secondary"
            type="button"
            @click="showSubmitConfirm = false"
          >
            {{ t('education.cancelTransfer') }}
          </button>
          <button
            class="edu-btn edu-btn--primary"
            type="button"
            :disabled="submitting"
            @click="submitTest"
          >
            {{ submitting ? t('education.transfer_verifying') : t('education.submitAssessment') }}
          </button>
        </footer>
      </div>
    </div>
  </div>
</template>

<style scoped>
.edu-runner-container {
  display: flex;
  flex-direction: column;
  min-height: 100%;
  background: var(--surface);
  color: var(--text-1);
}

.edu-runner {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  max-width: 840px;
  margin: 0 auto;
  width: 100%;
  padding: 1.5rem;
}

.edu-runner__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 1rem;
  border-bottom: 1px solid var(--border);
  padding-bottom: 1rem;
}

.edu-runner__title {
  font-size: var(--type-h1-size);
  font-weight: var(--type-h1-weight);
  line-height: var(--type-h1-line);
  margin: 0;
}

.edu-runner__inst {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
  margin: 0.25rem 0 0;
}

.edu-runner__meta {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.edu-runner__timer {
  display: flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.5rem 0.875rem;
  border-radius: 9999px;
  background: var(--surface-2);
  border: 1px solid var(--border);
  font-size: var(--type-body-medium-emphasis-size);
  font-weight: var(--type-body-medium-emphasis-weight);
  line-height: var(--type-body-medium-emphasis-line);
}

.edu-runner__timer--urgent {
  background: var(--danger-soft);
  color: var(--danger-text);
  border-color: var(--danger-soft);
}

.edu-runner__nav {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.edu-runner__nav-btn {
  width: 36px;
  height: 36px;
  border-radius: 6px;
  border: 1px solid var(--border);
  background: var(--surface-2);
  color: var(--text-1);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: var(--type-body-small-emphasis-size);
  font-weight: var(--type-body-small-emphasis-weight);
  line-height: var(--type-body-small-line);
  cursor: pointer;
  position: relative;
  transition:
    color var(--theme-transition),
    background var(--theme-transition),
    border-color var(--theme-transition);
}

.edu-runner__nav-btn--current {
  border-color: var(--accent);
  box-shadow: 0 0 0 2px var(--accent-soft);
}

.edu-runner__nav-btn--answered {
  background: var(--accent-soft);
  color: var(--accent);
}

.edu-runner__flag-dot {
  position: absolute;
  top: 1px;
  right: 3px;
  color: var(--warning-text);
  font-size: var(--type-body-caption-size);
  font-weight: var(--type-body-caption-weight);
  line-height: var(--type-body-caption-line);
}

.edu-runner__card {
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--surface-2);
  padding: 1.75rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.edu-runner__card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.edu-runner__card-idx {
  font-size: var(--type-body-small-emphasis-size);
  font-weight: var(--type-body-small-emphasis-weight);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
}

.edu-runner__prompt {
  font-size: var(--type-h2-size);
  font-weight: var(--type-h2-weight);
  line-height: var(--type-h2-line);
  margin: 0;
}

.edu-runner__options {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.edu-runner__option {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1rem 1.25rem;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--text-1);
  text-align: left;
  cursor: pointer;
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  line-height: var(--type-body-medium-line);
  transition:
    color var(--theme-transition),
    background var(--theme-transition),
    border-color var(--theme-transition);
}

.edu-runner__option:hover {
  background: var(--surface-2);
  border-color: var(--accent);
}

.edu-runner__option--selected {
  background: var(--accent-soft);
  border-color: var(--accent);
  font-weight: var(--type-body-medium-emphasis-weight);
}

.edu-runner__option-radio,
.edu-runner__option-checkbox {
  width: 18px;
  height: 18px;
  border: 2px solid var(--border);
  border-radius: 50%;
  flex-shrink: 0;
}

.edu-runner__option-checkbox {
  border-radius: 4px;
}

.edu-runner__option--selected .edu-runner__option-radio,
.edu-runner__option--selected .edu-runner__option-checkbox {
  border-color: var(--accent);
  background: var(--accent);
}

.edu-runner__tf-options {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}

.edu-runner__card-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-top: 1px solid var(--border);
  padding-top: 1.25rem;
  margin-top: 1rem;
}

.edu-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 1rem;
}

.edu-modal {
  background: var(--surface);
  color: var(--text-1);
  border-radius: 8px;
  width: 100%;
  max-width: 480px;
  display: flex;
  flex-direction: column;
  box-shadow: 0 4px 24px rgba(0, 0, 0, 0.2);
}

.edu-modal__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid var(--border);
}

.edu-modal__title {
  font-size: var(--type-h3-size);
  font-weight: var(--type-h3-weight);
  line-height: var(--type-h3-line);
  margin: 0;
}

.edu-modal__body {
  padding: 1.25rem 1.5rem;
}

.edu-modal__footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 1rem 1.5rem;
  border-top: 1px solid var(--border);
}

.edu-runner-loading {
  text-align: center;
  padding: 5rem 1rem;
  color: var(--text-2);
}
</style>
