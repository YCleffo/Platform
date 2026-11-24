<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { QuizActivityData, QuizQuestionType } from './types';

const emit = defineEmits<{
  (e: 'create', quiz: QuizActivityData): void;
  (e: 'cancel'): void;
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

const presets: QuizActivityData[] = [
  {
    id: 'quiz-python-basics',
    title: 'Python: Переменные и циклы',
    description: 'Интерактивный экспресс-квиз по основам алгоритмов и конструкций.',
    passingScorePercent: 70,
    questions: [
      {
        id: 'q1',
        type: 'single_choice',
        prompt: 'Какой тип данных используется для хранения целых чисел в Python?',
        options: [
          { id: 'o1', text: 'int' },
          { id: 'o2', text: 'str' },
          { id: 'o3', text: 'float' },
          { id: 'o4', text: 'bool' },
        ],
        correctAnswer: 'o1',
        explanation: 'Тип данных int представляет целые числа произвольной точности.',
      },
      {
        id: 'q2',
        type: 'multi_choice',
        prompt: 'Какие из перечисленных ключевых слов управляют циклами?',
        options: [
          { id: 'm1', text: 'break' },
          { id: 'm2', text: 'continue' },
          { id: 'm3', text: 'def' },
          { id: 'm4', text: 'import' },
        ],
        correctAnswer: ['m1', 'm2'],
        explanation: 'break досрочно завершает цикл, а continue переходит к следующей итерации.',
      },
      {
        id: 'q3',
        type: 'ordering',
        prompt: 'Упорядочите этапы выполнения цикла for в логической последовательности:',
        options: [
          { id: 'ord1', text: '1. Инициализация итератора / коллекции' },
          { id: 'ord2', text: '2. Извлечение следующего элемента' },
          { id: 'ord3', text: '3. Выполнение тела цикла' },
          { id: 'ord4', text: '4. Проверка условия завершения' },
        ],
        correctAnswer: ['ord1', 'ord2', 'ord3', 'ord4'],
        explanation:
          'Цикл начинается с подготовки итератора, затем извлекается элемент, выполняется тело и проверяется завершение.',
      },
    ],
  },
  {
    id: 'quiz-web-dev',
    title: 'Web Dev: Селекторы и архитектура',
    description: 'Проверка знаний HTML, CSS и компонентов платформы.',
    passingScorePercent: 70,
    questions: [
      {
        id: 'qw1',
        type: 'single_choice',
        prompt: 'Какое свойство в CSS используется для изменения цвета текста?',
        options: [
          { id: 'c1', text: 'color' },
          { id: 'c2', text: 'font-color' },
          { id: 'c3', text: 'text-color' },
        ],
        correctAnswer: 'c1',
        explanation: 'Свойство color задаёт основной цвет текста элемента.',
      },
      {
        id: 'qw2',
        type: 'ordering',
        prompt: 'Расположите слои блочной модели CSS (Box Model) от внутреннего к внешнему:',
        options: [
          { id: 'b1', text: 'Content (содержимое)' },
          { id: 'b2', text: 'Padding (внутренний отступ)' },
          { id: 'b3', text: 'Border (граница)' },
          { id: 'b4', text: 'Margin (внешний отступ)' },
        ],
        correctAnswer: ['b1', 'b2', 'b3', 'b4'],
        explanation: 'Порядок Box Model изнутри наружу: Content -> Padding -> Border -> Margin.',
      },
    ],
  },
];

const selectedPresetIdx = ref(0);
const isCustomMode = ref(false);

const customQuiz = reactive<QuizActivityData>({
  id: '',
  title: '',
  description: '',
  passingScorePercent: 70,
  questions: [
    {
      id: 'custom-q1',
      type: 'single_choice' as QuizQuestionType,
      prompt: '',
      options: [
        { id: 'opt-1', text: '' },
        { id: 'opt-2', text: '' },
      ],
      correctAnswer: 'opt-1',
      explanation: '',
    },
  ],
});

function handleCreatePreset(): void {
  const chosen = presets[selectedPresetIdx.value];
  if (!chosen) return;
  emit('create', {
    ...chosen,
    id: `quiz-${Date.now()}`,
  });
}

function handleCreateCustom(): void {
  if (!customQuiz.title.trim()) return;
  emit('create', {
    ...customQuiz,
    id: `quiz-${Date.now()}`,
  });
}
</script>

<template>
  <div class="app-quiz-builder">
    <div class="app-quiz-builder__header">
      <h3 class="app-quiz-builder__title">{{ t('education.quizBuilderTitle') }}</h3>
      <button
        type="button"
        class="app-quiz-builder__close-btn"
        :aria-label="t('education.cancel')"
        @click="emit('cancel')"
      >
        ✕
      </button>
    </div>

    <!-- Mode Selector -->
    <div class="app-quiz-builder__mode-toggle">
      <button
        type="button"
        class="app-quiz-builder__toggle-btn"
        :class="{ 'app-quiz-builder__toggle-btn--active': !isCustomMode }"
        @click="isCustomMode = false"
      >
        {{ t('education.quizPresets') }}
      </button>
      <button
        type="button"
        class="app-quiz-builder__toggle-btn"
        :class="{ 'app-quiz-builder__toggle-btn--active': isCustomMode }"
        @click="isCustomMode = true"
      >
        {{ t('education.quizCustom') }}
      </button>
    </div>

    <!-- Presets View -->
    <div
      v-if="!isCustomMode"
      class="app-quiz-builder__presets"
    >
      <div
        v-for="(preset, idx) in presets"
        :key="preset.id"
        class="app-quiz-builder__preset-card"
        :class="{ 'app-quiz-builder__preset-card--selected': selectedPresetIdx === idx }"
        @click="selectedPresetIdx = idx"
      >
        <div class="app-quiz-builder__preset-meta">
          <strong>{{ preset.title }}</strong>
          <span class="app-quiz-builder__badge">
            {{ preset.questions.length }} {{ t('education.questionsCount') }}
          </span>
        </div>
        <p class="app-quiz-builder__desc">{{ preset.description }}</p>
      </div>

      <div class="app-quiz-builder__actions">
        <button
          type="button"
          class="app-quiz-builder__btn app-quiz-builder__btn--cancel"
          @click="emit('cancel')"
        >
          {{ t('education.cancel') }}
        </button>
        <button
          type="button"
          class="app-quiz-builder__btn app-quiz-builder__btn--primary"
          @click="handleCreatePreset"
        >
          {{ t('education.quizAttachToChat') }}
        </button>
      </div>
    </div>

    <!-- Custom Quiz View -->
    <div
      v-else
      class="app-quiz-builder__custom"
    >
      <label class="app-quiz-builder__field">
        <span>{{ t('education.quizTitleLabel') }}</span>
        <input
          v-model="customQuiz.title"
          class="app-quiz-builder__input"
          :placeholder="t('education.quizTitlePlaceholder')"
        />
      </label>

      <div
        v-for="(q, qIndex) in customQuiz.questions"
        :key="q.id"
        class="app-quiz-builder__q-form"
      >
        <label class="app-quiz-builder__field">
          <span>{{ t('education.quizQuestionPrompt') }} #{{ qIndex + 1 }}</span>
          <input
            v-model="q.prompt"
            class="app-quiz-builder__input"
            :placeholder="t('education.quizPromptPlaceholder')"
          />
        </label>

        <div class="app-quiz-builder__options-form">
          <div
            v-for="(opt, optIdx) in q.options"
            :key="opt.id"
            class="app-quiz-builder__opt-row"
          >
            <input
              type="radio"
              :name="`correct-${q.id}`"
              :checked="q.correctAnswer === opt.id"
              :aria-label="t('education.correctAnswer')"
              @change="q.correctAnswer = opt.id"
            />
            <input
              v-model="opt.text"
              class="app-quiz-builder__input"
              :placeholder="`${t('education.quizOptionPlaceholder')} ${optIdx + 1}`"
            />
          </div>
        </div>
      </div>

      <div class="app-quiz-builder__actions">
        <button
          type="button"
          class="app-quiz-builder__btn app-quiz-builder__btn--cancel"
          @click="emit('cancel')"
        >
          {{ t('education.cancel') }}
        </button>
        <button
          type="button"
          class="app-quiz-builder__btn app-quiz-builder__btn--primary"
          :disabled="!customQuiz.title.trim()"
          @click="handleCreateCustom"
        >
          {{ t('education.quizAttachToChat') }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.app-quiz-builder {
  display: flex;
  flex-direction: column;
  gap: 14px;
  background-color: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
  padding: 16px;
  color: var(--text-1);
  box-sizing: border-box;
  width: 100%;
}

.app-quiz-builder__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.app-quiz-builder__title {
  margin: 0;
  font-size: var(--type-h3-size);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  color: var(--text-1);
}

.app-quiz-builder__close-btn {
  background: none;
  border: none;
  color: var(--text-2);
  cursor: pointer;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  transition: color var(--theme-transition);
}

.app-quiz-builder__close-btn:hover {
  color: var(--text-1);
}

.app-quiz-builder__mode-toggle {
  display: flex;
  gap: 8px;
  background: var(--surface-2);
  padding: 4px;
  border-radius: var(--radius-card-s);
}

.app-quiz-builder__toggle-btn {
  flex: 1;
  border: none;
  background: transparent;
  color: var(--text-2);
  padding: 6px 12px;
  border-radius: var(--radius-card-s);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-small-emphasis-weight);
  cursor: pointer;
  transition:
    background-color var(--theme-transition),
    color var(--theme-transition);
}

.app-quiz-builder__toggle-btn--active {
  background: var(--surface);
  color: var(--text-1);
}

.app-quiz-builder__presets {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.app-quiz-builder__preset-card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 10px 12px;
  border-radius: var(--radius-card-s);
  background: var(--surface-2);
  border: 1px solid var(--border);
  cursor: pointer;
  transition:
    border-color var(--theme-transition),
    background-color var(--theme-transition);
}

.app-quiz-builder__preset-card:hover {
  border-color: var(--accent);
}

.app-quiz-builder__preset-card--selected {
  border-color: var(--accent);
  background: var(--surface);
}

.app-quiz-builder__preset-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.app-quiz-builder__badge {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--accent);
}

.app-quiz-builder__desc {
  margin: 0;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
}

.app-quiz-builder__custom {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.app-quiz-builder__field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-2);
}

.app-quiz-builder__input {
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: var(--radius-card-s);
  padding: 8px 12px;
  color: var(--text-1);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  outline: none;
  transition: border-color var(--theme-transition);
}

.app-quiz-builder__input:focus {
  border-color: var(--accent);
}

.app-quiz-builder__q-form {
  display: flex;
  flex-direction: column;
  gap: 8px;
  background: var(--surface-2);
  padding: 10px;
  border-radius: var(--radius-card-s);
  border: 1px solid var(--border);
}

.app-quiz-builder__options-form {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.app-quiz-builder__opt-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.app-quiz-builder__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 6px;
}

.app-quiz-builder__btn {
  border: none;
  border-radius: var(--radius-card-s);
  padding: 8px 14px;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-small-emphasis-weight);
  cursor: pointer;
  transition:
    background-color var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-linear);
}

.app-quiz-builder__btn--cancel {
  background: var(--surface-2);
  color: var(--text-1);
  border: 1px solid var(--border);
}

.app-quiz-builder__btn--primary {
  background: var(--accent);
  color: var(--color-white);
}

.app-quiz-builder__btn--primary:hover:not(:disabled) {
  opacity: 0.9;
  transform: scale(1.02);
}

.app-quiz-builder__btn--primary:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
</style>
