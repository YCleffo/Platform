<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useEducationI18n } from '../i18n';
import { assessmentApi } from './assessmentApi';
import type { QuestionBankItem, AssessmentQuestion } from '../activityTypes';

const props = defineProps<{
  open: boolean;
}>();

const emit = defineEmits<{
  close: [];
  select: [question: AssessmentQuestion];
}>();

const { t } = useEducationI18n();
const items = ref<QuestionBankItem[]>([]);
const loading = ref(false);
const search = ref('');
const selectedDifficulty = ref<number | null>(null);
const category = ref('');

async function loadQuestions(): Promise<void> {
  loading.value = true;
  try {
    const res = await assessmentApi.listQuestionBank({
      search: search.value || undefined,
      difficulty: selectedDifficulty.value ?? undefined,
      category: category.value || undefined,
    });
    items.value = res.items;
  } catch {
    items.value = [];
  } finally {
    loading.value = false;
  }
}

function handleSelect(item: QuestionBankItem): void {
  const q: AssessmentQuestion = {
    id: `q_${Date.now()}_${Math.random().toString(36).substring(2, 6)}`,
    type: item.questionType,
    prompt: item.content.prompt,
    points: item.defaultPoints,
    options: item.content.options ? [...item.content.options] : undefined,
    tolerance: item.content.tolerance,
    answerKey: item.answerKey,
    explanation: item.explanation,
  };
  emit('select', q);
}

onMounted(() => {
  if (props.open) {
    void loadQuestions();
  }
});
</script>

<template>
  <div
    v-if="open"
    class="edu-bank-drawer__backdrop"
    @click.self="emit('close')"
  >
    <div
      class="edu-bank-drawer"
      role="dialog"
      :aria-label="t('education.questionBank')"
    >
      <header class="edu-bank-drawer__header">
        <h2 class="edu-bank-drawer__title">{{ t('education.questionBank') }}</h2>
        <button
          class="edu-btn edu-btn--ghost edu-bank-drawer__close"
          type="button"
          :aria-label="t('education.cancelTransfer')"
          @click="emit('close')"
        >
          ✕
        </button>
      </header>

      <div class="edu-bank-drawer__filters">
        <input
          v-model="search"
          type="search"
          class="edu-input edu-bank-drawer__search"
          :placeholder="t('education.quizPromptPlaceholder')"
          @input="loadQuestions"
        />
        <div class="edu-bank-drawer__diff-pills">
          <button
            v-for="d in [null, 1, 2, 3]"
            :key="String(d)"
            type="button"
            class="edu-btn edu-btn--sm"
            :class="selectedDifficulty === d ? 'edu-btn--primary' : 'edu-btn--secondary'"
            @click="selectedDifficulty = d; loadQuestions()"
          >
            {{ d === null ? t('education.wholeGroup') : `${d} · ${t(`education.difficulty${d}`)}` }}
          </button>
        </div>
      </div>

      <div class="edu-bank-drawer__body">
        <div
          v-if="loading"
          class="edu-bank-drawer__loading"
        >
          {{ t('education.transfer_verifying') }}…
        </div>
        <div
          v-else-if="items.length === 0"
          class="edu-bank-drawer__empty"
        >
          {{ t('education.noResultsYet') }}
        </div>
        <ul
          v-else
          class="edu-bank-drawer__list"
        >
          <li
            v-for="item in items"
            :key="item.id"
            class="edu-bank-item"
          >
            <div class="edu-bank-item__main">
              <div class="edu-bank-item__badges">
                <span class="edu-badge edu-badge--neutral">
                  {{ t(`education.questionType_${item.questionType}`) }}
                </span>
                <span class="edu-badge edu-badge--primary">
                  {{ item.defaultPoints }} {{ t('education.score') }}
                </span>
                <span class="edu-badge edu-badge--accent">
                  {{ t(`education.difficulty${item.difficulty}`) }}
                </span>
              </div>
              <p class="edu-bank-item__prompt">{{ item.content.prompt }}</p>
              <p
                v-if="item.explanation"
                class="edu-bank-item__exp"
              >
                <em>{{ item.explanation }}</em>
              </p>
            </div>
            <button
              class="edu-btn edu-btn--sm edu-btn--primary"
              type="button"
              @click="handleSelect(item)"
            >
              + {{ t('education.addQuestion') }}
            </button>
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<style scoped>
.edu-bank-drawer__backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  justify-content: flex-end;
  z-index: 1000;
}

.edu-bank-drawer {
  width: 100%;
  max-width: 520px;
  height: 100%;
  background: var(--surface);
  color: var(--text-1);
  display: flex;
  flex-direction: column;
  box-shadow: 0 4px 24px rgba(0, 0, 0, 0.15);
}

.edu-bank-drawer__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid var(--border);
}

.edu-bank-drawer__title {
  font-size: var(--type-h3-size);
  font-weight: var(--type-h3-weight);
  line-height: var(--type-h3-line);
  margin: 0;
}

.edu-bank-drawer__filters {
  padding: 1rem 1.5rem;
  border-bottom: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.edu-bank-drawer__diff-pills {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.edu-bank-drawer__body {
  flex: 1;
  overflow-y: auto;
  padding: 1rem 1.5rem;
}

.edu-bank-drawer__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.edu-bank-item {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--surface-2);
}

.edu-bank-item__main {
  flex: 1;
  min-width: 0;
}

.edu-bank-item__badges {
  display: flex;
  gap: 0.375rem;
  flex-wrap: wrap;
  margin-bottom: 0.5rem;
}

.edu-bank-item__prompt {
  font-size: var(--type-body-medium-emphasis-size);
  font-weight: var(--type-body-medium-emphasis-weight);
  line-height: var(--type-body-medium-emphasis-line);
  margin: 0 0 0.25rem;
  word-break: break-word;
}

.edu-bank-item__exp {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
  margin: 0;
}

.edu-bank-drawer__empty,
.edu-bank-drawer__loading {
  text-align: center;
  padding: 3rem 1rem;
  color: var(--text-2);
}
</style>

