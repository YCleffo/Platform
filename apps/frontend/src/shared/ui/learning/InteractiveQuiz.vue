<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { QuizActivityData, QuizQuestion, QuizResult } from './types';

const props = withDefaults(
  defineProps<{
    quiz: QuizActivityData;
    disabled?: boolean;
    initialAnswers?: Record<string, string | string[]>;
  }>(),
  {
    disabled: false,
    initialAnswers: undefined,
  },
);

const emit = defineEmits<{
  (e: 'complete', result: QuizResult): void;
  (e: 'change', answers: Record<string, string | string[]>): void;
}>();

let t: (key: string, params?: Record<string, unknown>) => string;
try {
  const i18n = useI18n();
  t = i18n.t;
} catch {
  t = (key: string, params?: Record<string, unknown>) => {
    if (params) {
      return Object.entries(params).reduce((str, [k, v]) => str.replace(`{${k}}`, String(v)), key);
    }
    return key;
  };
}

// Store answers per question ID
const answers = reactive<Record<string, string | string[]>>({});
// Store active dragged index for ordering
const draggedIndex = ref<number | null>(null);
const draggedQuestionId = ref<string | null>(null);

const isChecked = ref(false);
const result = ref<QuizResult | null>(null);

function initializeAnswers(): void {
  isChecked.value = false;
  result.value = null;
  props.quiz.questions.forEach((q) => {
    if (props.initialAnswers && props.initialAnswers[q.id] !== undefined) {
      answers[q.id] = props.initialAnswers[q.id]!;
      return;
    }
    if (q.type === 'single_choice') {
      answers[q.id] = '';
    } else if (q.type === 'multi_choice') {
      answers[q.id] = [];
    } else if (q.type === 'ordering') {
      // Initialize with copy of option IDs in initial or shuffled order
      answers[q.id] = q.options.map((o) => o.id);
    }
  });
}

watch(() => props.quiz, initializeAnswers, { immediate: true });

function selectSingleChoice(questionId: string, optionId: string): void {
  if (isChecked.value || props.disabled) return;
  answers[questionId] = optionId;
  emit('change', { ...answers });
}

function toggleMultiChoice(questionId: string, optionId: string): void {
  if (isChecked.value || props.disabled) return;
  const current = Array.isArray(answers[questionId]) ? [...(answers[questionId] as string[])] : [];
  const idx = current.indexOf(optionId);
  if (idx >= 0) {
    current.splice(idx, 1);
  } else {
    current.push(optionId);
  }
  answers[questionId] = current;
  emit('change', { ...answers });
}

// Drag and drop for ordering
function onDragStart(questionId: string, index: number, event: DragEvent): void {
  if (isChecked.value || props.disabled) return;
  draggedIndex.value = index;
  draggedQuestionId.value = questionId;
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(index));
  }
}

function onDragOver(event: DragEvent): void {
  event.preventDefault();
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = 'move';
  }
}

function onDrop(questionId: string, targetIndex: number): void {
  if (
    isChecked.value ||
    props.disabled ||
    draggedIndex.value === null ||
    draggedQuestionId.value !== questionId ||
    draggedIndex.value === targetIndex
  ) {
    draggedIndex.value = null;
    draggedQuestionId.value = null;
    return;
  }
  const current = Array.isArray(answers[questionId]) ? [...(answers[questionId] as string[])] : [];
  const item = current.splice(draggedIndex.value, 1)[0];
  if (item) {
    current.splice(targetIndex, 0, item);
    answers[questionId] = current;
    emit('change', { ...answers });
  }
  draggedIndex.value = null;
  draggedQuestionId.value = null;
}

// Accessible buttons for moving items up/down
function moveItem(questionId: string, index: number, direction: 'up' | 'down'): void {
  if (isChecked.value || props.disabled) return;
  const current = Array.isArray(answers[questionId]) ? [...(answers[questionId] as string[])] : [];
  const targetIndex = direction === 'up' ? index - 1 : index + 1;
  if (targetIndex < 0 || targetIndex >= current.length) return;
  const temp = current[index];
  const target = current[targetIndex];
  if (temp && target) {
    current[index] = target;
    current[targetIndex] = temp;
    answers[questionId] = current;
    emit('change', { ...answers });
  }
}

function checkAnswers(): void {
  let score = 0;
  const totalPoints = props.quiz.questions.length;
  const evaluations = props.quiz.questions.map((q) => {
    const userAns = answers[q.id];
    let correct = false;

    if (q.type === 'single_choice') {
      correct = userAns === q.correctAnswer;
    } else if (q.type === 'multi_choice') {
      const userArr = Array.isArray(userAns) ? userAns : [];
      const correctArr = Array.isArray(q.correctAnswer) ? q.correctAnswer : [q.correctAnswer];
      correct =
        userArr.length === correctArr.length &&
        userArr.every((id) => correctArr.includes(id)) &&
        correctArr.every((id) => userArr.includes(id));
    } else if (q.type === 'ordering') {
      const userArr = Array.isArray(userAns) ? userAns : [];
      const correctArr = Array.isArray(q.correctAnswer) ? q.correctAnswer : [];
      correct =
        userArr.length === correctArr.length && userArr.every((id, idx) => id === correctArr[idx]);
    }

    if (correct) {
      score += 1;
    }

    return {
      questionId: q.id,
      correct,
      userAnswer: userAns ?? '',
      correctAnswer: q.correctAnswer,
      explanation: q.explanation,
    };
  });

  const percent = totalPoints > 0 ? Math.round((score / totalPoints) * 100) : 100;
  const passThreshold = props.quiz.passingScorePercent ?? 70;
  const passed = percent >= passThreshold;

  const res: QuizResult = {
    score,
    totalPoints,
    percent,
    passed,
    questionResults: evaluations,
  };

  result.value = res;
  isChecked.value = true;
  emit('complete', res);
}

function resetQuiz(): void {
  initializeAnswers();
}

function getOption(q: QuizQuestion, optionId: string) {
  return q.options.find((o) => o.id === optionId) || { id: optionId, text: optionId };
}

const canSubmit = computed(() => {
  return props.quiz.questions.every((q) => {
    const ans = answers[q.id];
    if (q.type === 'single_choice') return typeof ans === 'string' && ans.length > 0;
    if (q.type === 'multi_choice') return Array.isArray(ans) && ans.length > 0;
    if (q.type === 'ordering') return Array.isArray(ans) && ans.length === q.options.length;
    return false;
  });
});
</script>

<template>
  <div class="app-quiz">
    <!-- Header -->
    <header class="app-quiz__header">
      <div class="app-quiz__header-meta">
        <h3 class="app-quiz__title">{{ quiz.title }}</h3>
        <span class="app-quiz__badge">
          {{ quiz.questions.length }} {{ t('education.questionsCount') }}
        </span>
      </div>
      <p
        v-if="quiz.description"
        class="app-quiz__desc"
      >
        {{ quiz.description }}
      </p>
    </header>

    <!-- Questions list -->
    <div class="app-quiz__questions">
      <section
        v-for="(q, qIndex) in quiz.questions"
        :key="q.id"
        class="app-quiz__card"
        :class="{
          'app-quiz__card--correct': isChecked && result?.questionResults[qIndex]?.correct,
          'app-quiz__card--incorrect': isChecked && !result?.questionResults[qIndex]?.correct,
        }"
      >
        <div class="app-quiz__q-header">
          <span class="app-quiz__q-number">#{{ qIndex + 1 }}</span>
          <p class="app-quiz__prompt">{{ q.prompt }}</p>
          <span class="app-quiz__q-type">
            {{
              q.type === 'single_choice'
                ? t('education.quizSingleChoice')
                : q.type === 'multi_choice'
                  ? t('education.quizMultiChoice')
                  : t('education.quizOrdering')
            }}
          </span>
        </div>

        <!-- Single Choice Options -->
        <div
          v-if="q.type === 'single_choice'"
          class="app-quiz__options"
          role="radiogroup"
        >
          <button
            v-for="opt in q.options"
            :key="opt.id"
            type="button"
            class="app-quiz__option"
            :class="{
              'app-quiz__option--selected': answers[q.id] === opt.id,
              'app-quiz__option--correct': isChecked && q.correctAnswer === opt.id,
              'app-quiz__option--wrong':
                isChecked && answers[q.id] === opt.id && q.correctAnswer !== opt.id,
            }"
            :disabled="isChecked || disabled"
            role="radio"
            :aria-checked="answers[q.id] === opt.id"
            @click="selectSingleChoice(q.id, opt.id)"
          >
            <span class="app-quiz__indicator"></span>
            <span class="app-quiz__opt-text">{{ opt.text }}</span>
          </button>
        </div>

        <!-- Multiple Choice Options -->
        <div
          v-else-if="q.type === 'multi_choice'"
          class="app-quiz__options"
          role="group"
        >
          <button
            v-for="opt in q.options"
            :key="opt.id"
            type="button"
            class="app-quiz__option"
            :class="{
              'app-quiz__option--selected':
                Array.isArray(answers[q.id]) && (answers[q.id] as string[]).includes(opt.id),
              'app-quiz__option--correct':
                isChecked &&
                Array.isArray(q.correctAnswer) &&
                (q.correctAnswer as string[]).includes(opt.id),
              'app-quiz__option--wrong':
                isChecked &&
                Array.isArray(answers[q.id]) &&
                (answers[q.id] as string[]).includes(opt.id) &&
                Array.isArray(q.correctAnswer) &&
                !(q.correctAnswer as string[]).includes(opt.id),
            }"
            :disabled="isChecked || disabled"
            role="checkbox"
            :aria-checked="
              Array.isArray(answers[q.id]) && (answers[q.id] as string[]).includes(opt.id)
            "
            @click="toggleMultiChoice(q.id, opt.id)"
          >
            <span class="app-quiz__checkbox-indicator"></span>
            <span class="app-quiz__opt-text">{{ opt.text }}</span>
          </button>
        </div>

        <!-- Ordering Drag-and-Drop + Button Reorder -->
        <div
          v-else-if="q.type === 'ordering'"
          class="app-quiz__ordering-list"
        >
          <div
            v-for="(optId, optIndex) in (answers[q.id] as string[]) || []"
            :key="optId"
            class="app-quiz__order-item"
            :class="{
              'app-quiz__order-item--correct':
                isChecked && (q.correctAnswer as string[])?.[optIndex] === optId,
              'app-quiz__order-item--wrong':
                isChecked && (q.correctAnswer as string[])?.[optIndex] !== optId,
            }"
            :draggable="!isChecked && !disabled"
            @dragstart="onDragStart(q.id, optIndex, $event)"
            @dragover="onDragOver"
            @drop="onDrop(q.id, optIndex)"
          >
            <span class="app-quiz__order-pos">{{ optIndex + 1 }}</span>
            <span
              class="app-quiz__order-handle"
              aria-hidden="true"
            >
              ⋮⋮
            </span>
            <span class="app-quiz__opt-text">{{ getOption(q, optId).text }}</span>

            <!-- Accessible up/down reorder buttons -->
            <div
              v-if="!isChecked && !disabled"
              class="app-quiz__order-actions"
            >
              <button
                type="button"
                class="app-quiz__arrow-btn"
                :disabled="optIndex === 0"
                :aria-label="t('education.moveUp')"
                @click="moveItem(q.id, optIndex, 'up')"
              >
                ▲
              </button>
              <button
                type="button"
                class="app-quiz__arrow-btn"
                :disabled="optIndex === (answers[q.id] as string[]).length - 1"
                :aria-label="t('education.moveDown')"
                @click="moveItem(q.id, optIndex, 'down')"
              >
                ▼
              </button>
            </div>
          </div>
        </div>

        <!-- Feedback & Explanation -->
        <div
          v-if="isChecked && q.explanation"
          class="app-quiz__explanation"
        >
          <strong>{{ t('education.explanation') }}:</strong>
          <p>{{ q.explanation }}</p>
        </div>
      </section>
    </div>

    <!-- Results Banner -->
    <div
      v-if="isChecked && result"
      class="app-quiz__result-banner"
      :class="{
        'app-quiz__result-banner--pass': result.passed,
        'app-quiz__result-banner--fail': !result.passed,
      }"
    >
      <div class="app-quiz__result-text">
        <strong>
          {{ result.passed ? t('education.quizPassedTitle') : t('education.quizFailedTitle') }}
        </strong>
        <span>
          {{
            t('education.quizScoreDetail', {
              score: result.score,
              total: result.totalPoints,
              percent: result.percent,
            })
          }}
        </span>
      </div>
      <button
        type="button"
        class="app-quiz__btn app-quiz__btn--retry"
        @click="resetQuiz"
      >
        {{ t('education.quizRetry') }}
      </button>
    </div>

    <!-- Actions -->
    <div
      v-if="!isChecked"
      class="app-quiz__actions"
    >
      <button
        type="button"
        class="app-quiz__btn app-quiz__btn--submit"
        :disabled="!canSubmit || disabled"
        @click="checkAnswers"
      >
        {{ t('education.quizCheckAnswers') }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.app-quiz {
  display: flex;
  flex-direction: column;
  gap: 16px;
  background-color: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
  padding: 16px;
  box-sizing: border-box;
  color: var(--text-1);
  width: 100%;
}

.app-quiz__header {
  display: flex;
  flex-direction: column;
  gap: 6px;
  border-bottom: 1px solid var(--border);
  padding-bottom: 12px;
}

.app-quiz__header-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.app-quiz__title {
  margin: 0;
  font-size: var(--type-h3-size);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  color: var(--text-1);
}

.app-quiz__badge {
  background: var(--surface-2);
  border-radius: var(--radius-card-s);
  padding: 2px 8px;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--accent);
}

.app-quiz__desc {
  margin: 0;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
}

.app-quiz__questions {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.app-quiz__card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
  padding: 12px;
  transition: border-color var(--theme-transition);
}

.app-quiz__card--correct {
  border-color: var(--success);
}

.app-quiz__card--incorrect {
  border-color: var(--danger);
}

.app-quiz__q-header {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.app-quiz__q-number {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-h3-weight);
  color: var(--accent);
  flex-shrink: 0;
}

.app-quiz__prompt {
  margin: 0;
  flex: 1;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-1);
}

.app-quiz__q-type {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
  flex-shrink: 0;
}

.app-quiz__options {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.app-quiz__option {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-s);
  color: var(--text-1);
  cursor: pointer;
  text-align: left;
  transition:
    background-color var(--theme-transition),
    border-color var(--theme-transition);
}

.app-quiz__option:hover:not(:disabled) {
  background: var(--surface-2);
  border-color: var(--accent);
}

.app-quiz__option--selected {
  border-color: var(--accent);
  background: var(--surface-2);
}

.app-quiz__option--correct {
  border-color: var(--success) !important;
  background: var(--surface-2) !important;
}

.app-quiz__option--wrong {
  border-color: var(--danger) !important;
  background: var(--surface-2) !important;
}

.app-quiz__indicator {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 2px solid var(--border);
  flex-shrink: 0;
  transition: border-color var(--theme-transition);
}

.app-quiz__option--selected .app-quiz__indicator {
  border-color: var(--accent);
  background-color: var(--accent);
}

.app-quiz__checkbox-indicator {
  width: 14px;
  height: 14px;
  border-radius: 3px;
  border: 2px solid var(--border);
  flex-shrink: 0;
  transition: border-color var(--theme-transition);
}

.app-quiz__option--selected .app-quiz__checkbox-indicator {
  border-color: var(--accent);
  background-color: var(--accent);
}

.app-quiz__opt-text {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  flex: 1;
  color: var(--text-1);
}

/* Ordering styles */
.app-quiz__ordering-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.app-quiz__order-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-s);
  color: var(--text-1);
  cursor: grab;
  user-select: none;
  -webkit-user-select: none;
  transition:
    background-color var(--theme-transition),
    border-color var(--theme-transition);
}

.app-quiz__order-item:active {
  cursor: grabbing;
}

.app-quiz__order-item--correct {
  border-color: var(--success);
}

.app-quiz__order-item--wrong {
  border-color: var(--danger);
}

.app-quiz__order-pos {
  width: 20px;
  height: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: var(--surface-2);
  color: var(--text-1);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-h3-weight);
}

.app-quiz__order-handle {
  color: var(--text-2);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}

.app-quiz__order-actions {
  display: flex;
  gap: 4px;
}

.app-quiz__arrow-btn {
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-s);
  color: var(--text-1);
  cursor: pointer;
  padding: 2px 6px;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  transition: background-color var(--theme-transition);
}

.app-quiz__arrow-btn:hover:not(:disabled) {
  background: var(--surface);
}

.app-quiz__arrow-btn:disabled {
  opacity: 0.3;
  cursor: not-allowed;
}

.app-quiz__explanation {
  margin-top: 6px;
  padding: 8px 12px;
  border-radius: var(--radius-card-s);
  background: var(--surface-2);
  border-left: 3px solid var(--accent);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
}

.app-quiz__result-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-radius: var(--radius-card-s);
  gap: 12px;
}

.app-quiz__result-banner--pass {
  background: var(--surface-2);
  border: 1px solid var(--success);
  color: var(--text-1);
}

.app-quiz__result-banner--fail {
  background: var(--surface-2);
  border: 1px solid var(--danger);
  color: var(--text-1);
}

.app-quiz__result-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-1);
}

.app-quiz__actions {
  display: flex;
  justify-content: flex-end;
}

.app-quiz__btn {
  border: none;
  border-radius: var(--radius-card-s);
  padding: 8px 16px;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-small-emphasis-weight);
  cursor: pointer;
  transition:
    background-color var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-linear);
}

.app-quiz__btn--submit {
  background-color: var(--accent);
  color: var(--color-white);
}

.app-quiz__btn--submit:hover:not(:disabled) {
  opacity: 0.9;
  transform: scale(1.02);
}

.app-quiz__btn--submit:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.app-quiz__btn--retry {
  background-color: var(--surface-2);
  color: var(--text-1);
  border: 1px solid var(--border);
}

.app-quiz__btn--retry:hover {
  background-color: var(--surface);
}
</style>
