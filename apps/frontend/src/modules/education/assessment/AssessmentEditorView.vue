<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { useEducationI18n } from '../i18n';
import { assessmentApi } from './assessmentApi';
import QuestionBankDrawer from './QuestionBankDrawer.vue';
import type {
  AssessmentQuestion,
  QuestionType,
  AssessmentItem,
  AssessmentVersion,
} from '../activityTypes';

const props = defineProps<{
  assessmentId?: string;
}>();

const emit = defineEmits<{
  saved: [item: AssessmentItem];
  published: [item: AssessmentItem & AssessmentVersion];
  cancel: [];
}>();

const { t } = useEducationI18n();

const title = ref('');
const description = ref('');
const instructions = ref('');
const status = ref<'DRAFT' | 'PUBLISHED' | 'CLOSED' | 'ARCHIVED'>('DRAFT');
const passingScorePercent = ref(60);
const timeLimitSeconds = ref<number | null>(1800);
const maxAttempts = ref(1);
const shuffleQuestions = ref(false);
const shuffleOptions = ref(false);
const revealAnswersPolicy = ref<'never' | 'immediate' | 'after_grading' | 'deadline'>(
  'after_grading',
);
const gradingPolicy = ref<'highest' | 'latest' | 'average'>('highest');
const questions = ref<AssessmentQuestion[]>([]);

const loading = ref(false);
const saving = ref(false);
const publishing = ref(false);
const showBankDrawer = ref(false);
const toastMessage = ref<string | null>(null);

const totalPoints = computed(() => questions.value.reduce((acc, q) => acc + (q.points || 1), 0));

function showToast(msg: string): void {
  toastMessage.value = msg;
  setTimeout(() => {
    toastMessage.value = null;
  }, 3500);
}

function addQuestion(type: QuestionType): void {
  const newQ: AssessmentQuestion = {
    id: `q_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`,
    type,
    prompt: '',
    points: 1,
    explanation: '',
  };

  if (type === 'single_choice' || type === 'multi_choice') {
    newQ.options = [
      { id: 'opt_1', label: '' },
      { id: 'opt_2', label: '' },
    ];
    newQ.answerKey = type === 'single_choice' ? 'opt_1' : ['opt_1'];
  } else if (type === 'true_false') {
    newQ.answerKey = true;
  } else if (type === 'numeric') {
    newQ.answerKey = 0;
    newQ.tolerance = 0;
  } else if (type === 'short_text') {
    newQ.answerKey = '';
  }

  questions.value.push(newQ);
}

function removeQuestion(index: number): void {
  questions.value.splice(index, 1);
}

function moveQuestion(index: number, direction: -1 | 1): void {
  const target = index + direction;
  if (target < 0 || target >= questions.value.length) return;
  const currentQ = questions.value[index];
  const targetQ = questions.value[target];
  if (!currentQ || !targetQ) return;
  questions.value[index] = targetQ;
  questions.value[target] = currentQ;
}

function addOption(question: AssessmentQuestion): void {
  if (!question.options) question.options = [];
  const nextId = `opt_${question.options.length + 1}`;
  question.options.push({ id: nextId, label: '' });
}

function removeOption(question: AssessmentQuestion, optIndex: number): void {
  if (!question.options || question.options.length <= 2) return;
  const removed = question.options.splice(optIndex, 1)[0];
  if (!removed) return;
  if (question.type === 'single_choice' && question.answerKey === removed.id) {
    question.answerKey = question.options[0]?.id;
  } else if (question.type === 'multi_choice' && Array.isArray(question.answerKey)) {
    question.answerKey = question.answerKey.filter((id) => id !== removed.id);
  }
}

function toggleMultiOption(question: AssessmentQuestion, optId: string): void {
  if (!Array.isArray(question.answerKey)) {
    question.answerKey = [optId];
    return;
  }
  const current = [...(question.answerKey as string[])];
  const idx = current.indexOf(optId);
  if (idx >= 0) {
    if (current.length > 1) {
      current.splice(idx, 1);
    }
  } else {
    current.push(optId);
  }
  question.answerKey = current;
}

function onImportFromBank(q: AssessmentQuestion): void {
  questions.value.push(q);
  showBankDrawer.value = false;
  showToast(t('education.draftNotice'));
}

async function saveToBank(question: AssessmentQuestion): Promise<void> {
  try {
    await assessmentApi.createQuestionBankItem({
      questionType: question.type,
      content: {
        prompt: question.prompt,
        options: question.options,
        tolerance: question.tolerance,
      },
      answerKey: question.answerKey,
      explanation: question.explanation,
      defaultPoints: question.points,
      difficulty: 1,
      category: 'general',
    });
    showToast(t('education.draftNotice'));
  } catch {
    // ignore
  }
}

async function loadAssessment(): Promise<void> {
  if (!props.assessmentId) return;
  loading.value = true;
  try {
    const data = await assessmentApi.getAssessment(props.assessmentId);
    title.value = data.title;
    description.value = data.description;
    status.value = data.status;
    instructions.value = data.instructions || '';
    passingScorePercent.value = data.passingScorePercent ?? 60;
    timeLimitSeconds.value = data.timeLimitSeconds;
    maxAttempts.value = data.maxAttempts ?? 1;
    shuffleQuestions.value = Boolean(data.shuffleQuestions);
    shuffleOptions.value = Boolean(data.shuffleOptions);
    revealAnswersPolicy.value = data.revealAnswersPolicy || 'after_grading';
    gradingPolicy.value = data.gradingPolicy || 'highest';
    questions.value = data.questions ? [...data.questions] : [];
  } catch {
    // fallback
  } finally {
    loading.value = false;
  }
}

async function saveDraft(): Promise<void> {
  if (!title.value.trim()) return;
  saving.value = true;
  const payload = {
    title: title.value.trim(),
    description: description.value,
    instructions: instructions.value,
    status: status.value,
    passingScorePercent: passingScorePercent.value,
    timeLimitSeconds: timeLimitSeconds.value,
    maxAttempts: maxAttempts.value,
    shuffleQuestions: shuffleQuestions.value,
    shuffleOptions: shuffleOptions.value,
    revealAnswersPolicy: revealAnswersPolicy.value,
    gradingPolicy: gradingPolicy.value,
    questions: questions.value,
  };

  try {
    let saved: AssessmentItem;
    if (props.assessmentId) {
      saved = await assessmentApi.updateAssessment(props.assessmentId, payload);
    } else {
      saved = await assessmentApi.createAssessment(payload);
    }
    showToast(t('education.draftNotice'));
    emit('saved', saved);
  } catch {
    // error handled
  } finally {
    saving.value = false;
  }
}

async function publishTest(): Promise<void> {
  if (!title.value.trim() || questions.value.length === 0) return;
  publishing.value = true;
  const payload = {
    title: title.value.trim(),
    description: description.value,
    instructions: instructions.value,
    passingScorePercent: passingScorePercent.value,
    timeLimitSeconds: timeLimitSeconds.value,
    maxAttempts: maxAttempts.value,
    shuffleQuestions: shuffleQuestions.value,
    shuffleOptions: shuffleOptions.value,
    revealAnswersPolicy: revealAnswersPolicy.value,
    gradingPolicy: gradingPolicy.value,
    questions: questions.value,
  };

  try {
    let id = props.assessmentId;
    if (!id) {
      const created = await assessmentApi.createAssessment({ ...payload, status: 'DRAFT' });
      id = created.id;
    }
    const published = await assessmentApi.publishAssessment(id, payload);
    status.value = 'PUBLISHED';
    showToast(t('education.publishedNotice'));
    emit('published', published);
  } catch {
    // error handled
  } finally {
    publishing.value = false;
  }
}

onMounted(() => {
  void loadAssessment();
});
</script>

<template>
  <div class="edu-assessment-editor">
    <header class="edu-assessment-editor__header">
      <div>
        <h1 class="edu-assessment-editor__title">
          {{ assessmentId ? t('education.editAssessment') : t('education.createAssessment') }}
        </h1>
        <p class="edu-assessment-editor__subtitle">
          {{ t('education.assessmentStudio') }} · {{ questions.length }}
          {{ t('education.questionsCount') }} ({{ totalPoints }} {{ t('education.score') }})
        </p>
      </div>

      <div class="edu-assessment-editor__actions">
        <button
          class="edu-btn edu-btn--secondary"
          type="button"
          @click="emit('cancel')"
        >
          {{ t('education.cancelTransfer') }}
        </button>
        <button
          class="edu-btn edu-btn--secondary"
          type="button"
          :disabled="saving || publishing"
          @click="saveDraft"
        >
          {{ saving ? t('education.transfer_verifying') : t('education.draftNotice') }}
        </button>
        <button
          class="edu-btn edu-btn--primary"
          type="button"
          :disabled="saving || publishing || questions.length === 0"
          @click="publishTest"
        >
          {{ publishing ? t('education.transfer_verifying') : t('education.publishAssessment') }}
        </button>
      </div>
    </header>

    <div
      v-if="toastMessage"
      class="edu-assessment-toast"
      role="status"
    >
      {{ toastMessage }}
    </div>

    <div class="edu-assessment-editor__layout">
      <!-- Left Column: Settings -->
      <section class="edu-assessment-settings">
        <h2 class="edu-assessment-settings__title">{{ t('education.exerciseSettings') }}</h2>

        <label class="edu-field">
          <span class="edu-field__label">{{ t('education.quizTitleLabel') }}</span>
          <input
            v-model="title"
            type="text"
            class="edu-input"
            required
            :placeholder="t('education.quizTitlePlaceholder')"
          />
        </label>

        <label class="edu-field">
          <span class="edu-field__label">{{ t('education.quizQuestionPrompt') }}</span>
          <textarea
            v-model="instructions"
            class="edu-input edu-assessment-textarea"
            rows="3"
            :placeholder="t('education.quizPromptPlaceholder')"
          ></textarea>
        </label>

        <div class="edu-grid-2">
          <label class="edu-field">
            <span class="edu-field__label">{{ t('education.assessmentPassingScore') }}</span>
            <input
              v-model.number="passingScorePercent"
              type="number"
              min="0"
              max="100"
              step="5"
              class="edu-input"
            />
          </label>

          <label class="edu-field">
            <span class="edu-field__label">{{ t('education.assessmentTimeLimit') }}</span>
            <select
              v-model="timeLimitSeconds"
              class="edu-input"
            >
              <option :value="null">{{ t('education.wholeGroup') }} (∞)</option>
              <option :value="300">5 {{ t('education.minutes') }}</option>
              <option :value="600">10 {{ t('education.minutes') }}</option>
              <option :value="900">15 {{ t('education.minutes') }}</option>
              <option :value="1800">30 {{ t('education.minutes') }}</option>
              <option :value="2700">45 {{ t('education.minutes') }}</option>
              <option :value="3600">60 {{ t('education.minutes') }}</option>
            </select>
          </label>
        </div>

        <div class="edu-checkbox-group">
          <label class="edu-checkbox-label">
            <input
              v-model="shuffleQuestions"
              type="checkbox"
            />
            <span>{{ t('education.assessmentShuffleQuestions') }}</span>
          </label>
          <label class="edu-checkbox-label">
            <input
              v-model="shuffleOptions"
              type="checkbox"
            />
            <span>{{ t('education.assessmentShuffleOptions') }}</span>
          </label>
        </div>

        <label class="edu-field">
          <span class="edu-field__label">{{ t('education.assessmentRevealPolicy') }}</span>
          <select
            v-model="revealAnswersPolicy"
            class="edu-input"
          >
            <option value="after_grading">{{ t('education.revealAfterGrading') }}</option>
            <option value="immediate">{{ t('education.revealImmediate') }}</option>
            <option value="never">{{ t('education.revealNever') }}</option>
          </select>
        </label>
      </section>

      <!-- Right Column: Question Builder -->
      <section class="edu-assessment-questions">
        <div class="edu-assessment-questions__toolbar">
          <div class="edu-btn-group">
            <button
              class="edu-btn edu-btn--sm edu-btn--secondary"
              type="button"
              @click="addQuestion('single_choice')"
            >
              + {{ t('education.questionType_single_choice') }}
            </button>
            <button
              class="edu-btn edu-btn--sm edu-btn--secondary"
              type="button"
              @click="addQuestion('multi_choice')"
            >
              + {{ t('education.questionType_multi_choice') }}
            </button>
            <button
              class="edu-btn edu-btn--sm edu-btn--secondary"
              type="button"
              @click="addQuestion('true_false')"
            >
              + {{ t('education.questionType_true_false') }}
            </button>
            <button
              class="edu-btn edu-btn--sm edu-btn--secondary"
              type="button"
              @click="addQuestion('short_text')"
            >
              + {{ t('education.questionType_short_text') }}
            </button>
            <button
              class="edu-btn edu-btn--sm edu-btn--secondary"
              type="button"
              @click="addQuestion('numeric')"
            >
              + {{ t('education.questionType_numeric') }}
            </button>
          </div>

          <button
            class="edu-btn edu-btn--sm edu-btn--primary"
            type="button"
            @click="showBankDrawer = true"
          >
            📂 {{ t('education.importFromBank') }}
          </button>
        </div>

        <div
          v-if="questions.length === 0"
          class="edu-assessment-questions__empty"
        >
          <p>{{ t('education.noResultsYet') }}</p>
          <p class="edu-assessment-questions__empty-hint">{{ t('education.addQuestion') }}</p>
        </div>

        <div
          v-else
          class="edu-assessment-questions__list"
        >
          <article
            v-for="(q, idx) in questions"
            :key="q.id"
            class="edu-question-card"
          >
            <header class="edu-question-card__header">
              <span class="edu-question-card__index">#{{ idx + 1 }}</span>
              <span class="edu-badge edu-badge--neutral">
                {{ t(`education.questionType_${q.type}`) }}
              </span>

              <div class="edu-question-card__points">
                <input
                  v-model.number="q.points"
                  type="number"
                  min="1"
                  max="100"
                  class="edu-input edu-input--sm edu-question-card__points-input"
                />
                <span class="edu-question-card__points-label">{{ t('education.score') }}</span>
              </div>

              <div class="edu-question-card__nav">
                <button
                  type="button"
                  class="edu-btn edu-btn--sm edu-btn--ghost"
                  :disabled="idx === 0"
                  @click="moveQuestion(idx, -1)"
                >
                  ↑
                </button>
                <button
                  type="button"
                  class="edu-btn edu-btn--sm edu-btn--ghost"
                  :disabled="idx === questions.length - 1"
                  @click="moveQuestion(idx, 1)"
                >
                  ↓
                </button>
                <button
                  type="button"
                  class="edu-btn edu-btn--sm edu-btn--ghost"
                  :title="t('education.saveToBank')"
                  @click="saveToBank(q)"
                >
                  💾
                </button>
                <button
                  type="button"
                  class="edu-btn edu-btn--sm edu-btn--ghost edu-btn--danger"
                  @click="removeQuestion(idx)"
                >
                  ✕
                </button>
              </div>
            </header>

            <div class="edu-question-card__body">
              <label class="edu-field">
                <span class="edu-field__label">{{ t('education.questionPromptLabel') }}</span>
                <input
                  v-model="q.prompt"
                  type="text"
                  class="edu-input"
                  :placeholder="t('education.quizPromptPlaceholder')"
                  required
                />
              </label>

              <!-- Single Choice Options -->
              <div
                v-if="q.type === 'single_choice' && q.options"
                class="edu-options-builder"
              >
                <div
                  v-for="(opt, optIdx) in q.options"
                  :key="opt.id"
                  class="edu-option-row"
                >
                  <input
                    type="radio"
                    :name="`radio_${q.id}`"
                    :checked="q.answerKey === opt.id"
                    :aria-label="t('education.correctOption')"
                    @change="q.answerKey = opt.id"
                  />
                  <input
                    v-model="opt.label"
                    type="text"
                    class="edu-input"
                    :placeholder="`${t('education.quizOptionPlaceholder')} ${optIdx + 1}`"
                  />
                  <button
                    type="button"
                    class="edu-btn edu-btn--sm edu-btn--ghost"
                    :disabled="q.options.length <= 2"
                    @click="removeOption(q, optIdx)"
                  >
                    ✕
                  </button>
                </div>
                <button
                  type="button"
                  class="edu-btn edu-btn--sm edu-btn--secondary"
                  @click="addOption(q)"
                >
                  + {{ t('education.addOption') }}
                </button>
              </div>

              <!-- Multi Choice Options -->
              <div
                v-else-if="q.type === 'multi_choice' && q.options"
                class="edu-options-builder"
              >
                <div
                  v-for="(opt, optIdx) in q.options"
                  :key="opt.id"
                  class="edu-option-row"
                >
                  <input
                    type="checkbox"
                    :checked="Array.isArray(q.answerKey) && q.answerKey.includes(opt.id)"
                    :aria-label="t('education.correctOption')"
                    @change="toggleMultiOption(q, opt.id)"
                  />
                  <input
                    v-model="opt.label"
                    type="text"
                    class="edu-input"
                    :placeholder="`${t('education.quizOptionPlaceholder')} ${optIdx + 1}`"
                  />
                  <button
                    type="button"
                    class="edu-btn edu-btn--sm edu-btn--ghost"
                    :disabled="q.options.length <= 2"
                    @click="removeOption(q, optIdx)"
                  >
                    ✕
                  </button>
                </div>
                <button
                  type="button"
                  class="edu-btn edu-btn--sm edu-btn--secondary"
                  @click="addOption(q)"
                >
                  + {{ t('education.addOption') }}
                </button>
              </div>

              <!-- True / False -->
              <div
                v-else-if="q.type === 'true_false'"
                class="edu-tf-builder"
              >
                <label class="edu-radio-label">
                  <input
                    v-model="q.answerKey"
                    type="radio"
                    :name="`tf_${q.id}`"
                    :value="true"
                  />
                  <span>{{ t('education.correctAnswer') }} (True)</span>
                </label>
                <label class="edu-radio-label">
                  <input
                    v-model="q.answerKey"
                    type="radio"
                    :name="`tf_${q.id}`"
                    :value="false"
                  />
                  <span>{{ t('education.incorrectAnswer') }} (False)</span>
                </label>
              </div>

              <!-- Short Text -->
              <div
                v-else-if="q.type === 'short_text'"
                class="edu-short-text-builder"
              >
                <label class="edu-field">
                  <span class="edu-field__label">{{ t('education.acceptedAnswers') }}</span>
                  <input
                    :value="Array.isArray(q.answerKey) ? q.answerKey.join(', ') : q.answerKey || ''"
                    type="text"
                    class="edu-input"
                    @input="
                      q.answerKey = ($event.target as HTMLInputElement).value
                        .split(',')
                        .map((s) => s.trim())
                        .filter(Boolean)
                    "
                  />
                </label>
              </div>

              <!-- Numeric -->
              <div
                v-else-if="q.type === 'numeric'"
                class="edu-numeric-builder edu-grid-2"
              >
                <label class="edu-field">
                  <span class="edu-field__label">{{ t('education.correctOption') }}</span>
                  <input
                    v-model.number="q.answerKey"
                    type="number"
                    step="any"
                    class="edu-input"
                  />
                </label>
                <label class="edu-field">
                  <span class="edu-field__label">{{ t('education.toleranceLabel') }}</span>
                  <input
                    v-model.number="q.tolerance"
                    type="number"
                    min="0"
                    step="0.001"
                    class="edu-input"
                  />
                </label>
              </div>

              <!-- Explanation -->
              <label class="edu-field edu-field--subtle">
                <span class="edu-field__label">{{ t('education.explanationLabel') }}</span>
                <input
                  v-model="q.explanation"
                  type="text"
                  class="edu-input edu-input--sm"
                  :placeholder="t('education.explanation')"
                />
              </label>
            </div>
          </article>
        </div>
      </section>
    </div>

    <QuestionBankDrawer
      :open="showBankDrawer"
      @close="showBankDrawer = false"
      @select="onImportFromBank"
    />
  </div>
</template>

<style scoped>
.edu-assessment-editor {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1.5rem;
  background: var(--surface);
  color: var(--text-1);
}

.edu-assessment-editor__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 1rem;
  border-bottom: 1px solid var(--border);
  padding-bottom: 1rem;
}

.edu-assessment-editor__title {
  font-size: var(--type-h1-size);
  font-weight: var(--type-h1-weight);
  line-height: var(--type-h1-line);
  margin: 0 0 0.25rem;
}

.edu-assessment-editor__subtitle {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
  margin: 0;
}

.edu-assessment-editor__actions {
  display: flex;
  gap: 0.5rem;
}

.edu-assessment-toast {
  padding: 0.75rem 1.25rem;
  background: var(--success-soft);
  color: var(--success-text);
  border: 1px solid var(--border);
  border-radius: 6px;
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  line-height: var(--type-body-small-line);
}

.edu-assessment-editor__layout {
  display: grid;
  grid-template-columns: 320px 1fr;
  gap: 1.5rem;
  align-items: start;
}

@media (max-width: 900px) {
  .edu-assessment-editor__layout {
    grid-template-columns: 1fr;
  }
}

.edu-assessment-settings {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  background: var(--surface-2);
  padding: 1.25rem;
  border-radius: 8px;
  border: 1px solid var(--border);
}

.edu-assessment-settings__title {
  font-size: var(--type-h3-size);
  font-weight: var(--type-h3-weight);
  line-height: var(--type-h3-line);
  margin: 0;
}

.edu-field {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.edu-field__label {
  font-size: var(--type-body-caption-size);
  font-weight: var(--type-body-caption-weight);
  line-height: var(--type-body-caption-line);
  color: var(--text-2);
}

.edu-field--subtle {
  margin-top: 0.5rem;
}

.edu-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem;
}

.edu-checkbox-group {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.edu-checkbox-label,
.edu-radio-label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  line-height: var(--type-body-small-line);
  cursor: pointer;
}

.edu-assessment-questions {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.edu-assessment-questions__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  background: var(--surface-2);
  border-radius: 8px;
  border: 1px solid var(--border);
}

.edu-btn-group {
  display: flex;
  gap: 0.375rem;
  flex-wrap: wrap;
}

.edu-assessment-questions__empty {
  text-align: center;
  padding: 4rem 1rem;
  border: 2px dashed var(--border);
  border-radius: 8px;
  color: var(--text-2);
}

.edu-assessment-questions__empty-hint {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  line-height: var(--type-body-small-line);
  margin-top: 0.25rem;
}

.edu-assessment-questions__list {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.edu-question-card {
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--surface-2);
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.edu-question-card__header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.edu-question-card__index {
  font-size: var(--type-body-medium-emphasis-size);
  font-weight: var(--type-body-medium-emphasis-weight);
  line-height: var(--type-body-medium-emphasis-line);
}

.edu-question-card__points {
  display: flex;
  align-items: center;
  gap: 0.375rem;
}

.edu-question-card__points-input {
  width: 50px;
  text-align: center;
}

.edu-question-card__points-label {
  font-size: var(--type-body-caption-size);
  font-weight: var(--type-body-caption-weight);
  line-height: var(--type-body-caption-line);
  color: var(--text-2);
}

.edu-question-card__nav {
  margin-left: auto;
  display: flex;
  gap: 0.25rem;
}

.edu-question-card__body {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.edu-options-builder {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.edu-option-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.edu-tf-builder {
  display: flex;
  gap: 1.5rem;
}

.edu-assessment-textarea {
  resize: vertical;
}
</style>
