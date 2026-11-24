<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useEducationI18n } from '../i18n';
import { assessmentApi } from './assessmentApi';
import type { AssessmentItem, AssessmentAttempt } from '../activityTypes';

const emit = defineEmits<{
  create: [];
  edit: [id: string];
  preview: [id: string];
}>();

const { t } = useEducationI18n();

const items = ref<AssessmentItem[]>([]);
const loading = ref(false);
const search = ref('');
const statusFilter = ref<string>('');

// Attempts drawer / modal state
const selectedAssessment = ref<AssessmentItem | null>(null);
const attempts = ref<Array<AssessmentAttempt & { studentName: string; versionNumber: number }>>([]);
const loadingAttempts = ref(false);
const showAttemptsDrawer = ref(false);

// Manual review state
const reviewAttemptId = ref<string | null>(null);
const reviewNotes = ref('');
const reviewScore = ref<number | null>(null);
const savingReview = ref(false);

async function loadAssessments(): Promise<void> {
  loading.value = true;
  try {
    const res = await assessmentApi.listAssessments({
      search: search.value || undefined,
      status: statusFilter.value || undefined,
    });
    items.value = res.items;
  } catch {
    items.value = [];
  } finally {
    loading.value = false;
  }
}

async function viewAttempts(assessment: AssessmentItem): Promise<void> {
  selectedAssessment.value = assessment;
  showAttemptsDrawer.value = true;
  loadingAttempts.value = true;
  try {
    attempts.value = await assessmentApi.listAssessmentAttempts(assessment.id);
  } catch {
    attempts.value = [];
  } finally {
    loadingAttempts.value = false;
  }
}

function openReview(attempt: AssessmentAttempt & { studentName: string }): void {
  reviewAttemptId.value = attempt.id;
  reviewNotes.value = attempt.manualReviewNotes || '';
  reviewScore.value = attempt.score ?? null;
}

async function saveReview(): Promise<void> {
  if (!reviewAttemptId.value) return;
  savingReview.value = true;
  try {
    await assessmentApi.reviewAttempt(reviewAttemptId.value, {
      manualReviewNotes: reviewNotes.value,
      score: reviewScore.value ?? undefined,
    });
    if (selectedAssessment.value) {
      attempts.value = await assessmentApi.listAssessmentAttempts(selectedAssessment.value.id);
    }
    reviewAttemptId.value = null;
  } catch {
    // ignore
  } finally {
    savingReview.value = false;
  }
}

async function quickPublish(item: AssessmentItem): Promise<void> {
  try {
    await assessmentApi.publishAssessment(item.id);
    await loadAssessments();
  } catch {
    // ignore
  }
}

onMounted(() => {
  void loadAssessments();
});
</script>

<template>
  <div class="edu-assessment-list-view">
    <header class="edu-assessment-list-view__header">
      <div>
        <h1 class="edu-assessment-list-view__title">{{ t('education.assessments') }}</h1>
        <p class="edu-assessment-list-view__subtitle">{{ t('education.assessmentStudio') }}</p>
      </div>

      <button
        class="edu-btn edu-btn--primary"
        type="button"
        @click="emit('create')"
      >
        + {{ t('education.createAssessment') }}
      </button>
    </header>

    <div class="edu-assessment-list-view__filters">
      <input
        v-model="search"
        type="search"
        class="edu-input edu-assessment-list-view__search"
        :placeholder="t('education.quizTitlePlaceholder')"
        @input="loadAssessments"
      />

      <div class="edu-assessment-list-view__status-filters">
        <button
          v-for="s in ['', 'PUBLISHED', 'DRAFT', 'CLOSED']"
          :key="s"
          type="button"
          class="edu-btn edu-btn--sm"
          :class="statusFilter === s ? 'edu-btn--primary' : 'edu-btn--secondary'"
          @click="
            statusFilter = s;
            loadAssessments();
          "
        >
          {{ s === '' ? t('education.wholeGroup') : s }}
        </button>
      </div>
    </div>

    <div
      v-if="loading"
      class="edu-assessment-list-view__loading"
    >
      {{ t('education.transfer_verifying') }}…
    </div>

    <div
      v-else-if="items.length === 0"
      class="edu-assessment-list-view__empty"
    >
      <p>{{ t('education.noResultsYet') }}</p>
      <button
        class="edu-btn edu-btn--primary"
        type="button"
        @click="emit('create')"
      >
        + {{ t('education.createAssessment') }}
      </button>
    </div>

    <div
      v-else
      class="edu-assessment-grid"
    >
      <article
        v-for="item in items"
        :key="item.id"
        class="edu-assessment-card"
      >
        <header class="edu-assessment-card__header">
          <span
            class="edu-pill"
            :class="item.status === 'PUBLISHED' ? 'edu-pill--success' : 'edu-pill--neutral'"
          >
            {{ item.status }}
          </span>

          <span
            v-if="item.latestVersionNumber"
            class="edu-assessment-card__ver"
          >
            v{{ item.latestVersionNumber }}
          </span>
        </header>

        <h2 class="edu-assessment-card__title">{{ item.title }}</h2>
        <p class="edu-assessment-card__desc">
          {{ item.description || t('education.assessmentStudio') }}
        </p>

        <div class="edu-assessment-card__meta">
          <div class="edu-assessment-stat">
            <span class="edu-assessment-stat__val">{{ item.questionsCount ?? 0 }}</span>
            <span class="edu-assessment-stat__label">{{ t('education.questionsCount') }}</span>
          </div>
          <div class="edu-assessment-stat">
            <span class="edu-assessment-stat__val">{{ item.attemptsCount ?? 0 }}</span>
            <span class="edu-assessment-stat__label">{{ t('education.completedAttempts') }}</span>
          </div>
          <div class="edu-assessment-stat">
            <span class="edu-assessment-stat__val">
              {{ item.avgScore !== null ? `${item.avgScore}%` : '—' }}
            </span>
            <span class="edu-assessment-stat__label">{{ t('education.averageAccuracy') }}</span>
          </div>
        </div>

        <footer class="edu-assessment-card__footer">
          <button
            class="edu-btn edu-btn--sm edu-btn--secondary"
            type="button"
            @click="emit('edit', item.id)"
          >
            {{ t('education.editAssessment') }}
          </button>
          <button
            class="edu-btn edu-btn--sm edu-btn--ghost"
            type="button"
            @click="viewAttempts(item)"
          >
            {{ t('education.reviewAttempts') }}
          </button>
          <button
            v-if="item.status === 'DRAFT'"
            class="edu-btn edu-btn--sm edu-btn--primary"
            type="button"
            @click="quickPublish(item)"
          >
            {{ t('education.publishAssessment') }}
          </button>
        </footer>
      </article>
    </div>

    <!-- Attempts Drawer -->
    <div
      v-if="showAttemptsDrawer"
      class="edu-modal-backdrop"
      @click.self="showAttemptsDrawer = false"
    >
      <div
        class="edu-modal edu-modal--lg"
        role="dialog"
        :aria-label="t('education.reviewAttempts')"
      >
        <header class="edu-modal__header">
          <div>
            <h2 class="edu-modal__title">{{ selectedAssessment?.title }}</h2>
            <p class="edu-modal__subtitle">{{ t('education.reviewAttempts') }}</p>
          </div>
          <button
            class="edu-btn edu-btn--ghost"
            type="button"
            @click="showAttemptsDrawer = false"
          >
            ✕
          </button>
        </header>

        <div class="edu-modal__body">
          <div
            v-if="loadingAttempts"
            class="edu-modal__loading"
          >
            {{ t('education.transfer_verifying') }}…
          </div>
          <div
            v-else-if="attempts.length === 0"
            class="edu-modal__empty"
          >
            {{ t('education.noResultsYet') }}
          </div>
          <table
            v-else
            class="edu-table"
          >
            <thead>
              <tr>
                <th>{{ t('education.studentOne') }}</th>
                <th>Версия</th>
                <th>Статус</th>
                <th>Балл</th>
                <th>%</th>
                <th>Дата</th>
                <th>Действия</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="att in attempts"
                :key="att.id"
              >
                <td>{{ att.studentName }}</td>
                <td>v{{ att.versionNumber }}</td>
                <td>
                  <span
                    class="edu-pill"
                    :class="att.passed ? 'edu-pill--success' : 'edu-pill--danger'"
                  >
                    {{ att.state }}
                  </span>
                </td>
                <td>{{ att.score !== null ? `${att.score} / ${att.maxScore}` : '—' }}</td>
                <td>{{ att.percentage !== null ? `${att.percentage}%` : '—' }}</td>
                <td>{{ new Date(att.startedAt).toLocaleDateString() }}</td>
                <td>
                  <button
                    class="edu-btn edu-btn--sm edu-btn--secondary"
                    type="button"
                    @click="openReview(att)"
                  >
                    {{ t('education.manualReview') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>

          <!-- Manual Review Form -->
          <div
            v-if="reviewAttemptId"
            class="edu-review-form"
          >
            <h3 class="edu-review-form__title">{{ t('education.manualReview') }}</h3>
            <div class="edu-grid-2">
              <label class="edu-field">
                <span class="edu-field__label">{{ t('education.overrideScore') }}</span>
                <input
                  v-model.number="reviewScore"
                  type="number"
                  class="edu-input"
                />
              </label>
              <label class="edu-field">
                <span class="edu-field__label">{{ t('education.manualReviewNotes') }}</span>
                <input
                  v-model="reviewNotes"
                  type="text"
                  class="edu-input"
                  :placeholder="t('education.explanation')"
                />
              </label>
            </div>
            <div class="edu-review-form__actions">
              <button
                class="edu-btn edu-btn--sm edu-btn--secondary"
                type="button"
                @click="reviewAttemptId = null"
              >
                {{ t('education.cancelTransfer') }}
              </button>
              <button
                class="edu-btn edu-btn--sm edu-btn--primary"
                type="button"
                :disabled="savingReview"
                @click="saveReview"
              >
                {{ t('education.draftNotice') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.edu-assessment-list-view {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1.5rem;
  background: var(--surface);
  color: var(--text-1);
}

.edu-assessment-list-view__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--border);
  padding-bottom: 1rem;
}

.edu-assessment-list-view__title {
  font-size: var(--type-h1-size);
  font-weight: var(--type-h1-weight);
  line-height: var(--type-h1-line);
  margin: 0;
}

.edu-assessment-list-view__subtitle {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
  margin: 0.25rem 0 0;
}

.edu-assessment-list-view__filters {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 1rem;
}

.edu-assessment-list-view__search {
  max-width: 320px;
}

.edu-assessment-list-view__status-filters {
  display: flex;
  gap: 0.5rem;
}

.edu-assessment-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 1.25rem;
}

.edu-assessment-card {
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--surface-2);
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.edu-assessment-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.edu-assessment-card__ver {
  font-size: var(--type-body-caption-size);
  font-weight: var(--type-body-caption-weight);
  line-height: var(--type-body-caption-line);
  color: var(--text-2);
}

.edu-assessment-card__title {
  font-size: var(--type-h3-size);
  font-weight: var(--type-h3-weight);
  line-height: var(--type-h3-line);
  margin: 0;
}

.edu-assessment-card__desc {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
  margin: 0;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.edu-assessment-card__meta {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.5rem;
  background: var(--surface);
  padding: 0.75rem;
  border-radius: 6px;
  border: 1px solid var(--border);
}

.edu-assessment-stat {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

.edu-assessment-stat__val {
  font-size: var(--type-h3-size);
  font-weight: var(--type-h3-weight);
  line-height: var(--type-h3-line);
}

.edu-assessment-stat__label {
  font-size: var(--type-body-ultra-small-size);
  font-weight: var(--type-body-ultra-small-weight);
  line-height: var(--type-body-ultra-small-line);
  color: var(--text-2);
}

.edu-assessment-card__footer {
  display: flex;
  gap: 0.5rem;
  margin-top: auto;
  flex-wrap: wrap;
}

.edu-pill {
  display: inline-flex;
  align-items: center;
  padding: 0.25rem 0.625rem;
  border-radius: 9999px;
  font-size: var(--type-body-caption-size);
  font-weight: var(--type-body-caption-weight);
  line-height: var(--type-body-caption-line);
}

.edu-pill--success {
  background: var(--success-soft);
  color: var(--success-text);
  border: 1px solid var(--border);
}

.edu-pill--danger {
  background: var(--danger-soft);
  color: var(--danger-text);
  border: 1px solid var(--border);
}

.edu-pill--neutral {
  background: var(--surface);
  color: var(--text-2);
  border: 1px solid var(--border);
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
  max-width: 800px;
  max-height: 90vh;
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
  font-size: var(--type-h2-size);
  font-weight: var(--type-h2-weight);
  line-height: var(--type-h2-line);
  margin: 0;
}

.edu-modal__subtitle {
  font-size: var(--type-body-caption-size);
  font-weight: var(--type-body-caption-weight);
  line-height: var(--type-body-caption-line);
  color: var(--text-2);
  margin: 0.25rem 0 0;
}

.edu-modal__body {
  padding: 1.5rem;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.edu-table {
  width: 100%;
  border-collapse: collapse;
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  line-height: var(--type-body-small-line);
}

.edu-table th,
.edu-table td {
  padding: 0.75rem;
  border-bottom: 1px solid var(--border);
  text-align: left;
}

.edu-table th {
  color: var(--text-2);
  font-weight: var(--type-body-small-emphasis-weight);
}

.edu-review-form {
  background: var(--surface-2);
  padding: 1rem;
  border-radius: 6px;
  border: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.edu-review-form__title {
  font-size: var(--type-body-medium-emphasis-size);
  font-weight: var(--type-body-medium-emphasis-weight);
  line-height: var(--type-body-medium-emphasis-line);
  margin: 0;
}

.edu-review-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}

.edu-assessment-list-view__loading,
.edu-assessment-list-view__empty,
.edu-modal__loading,
.edu-modal__empty {
  text-align: center;
  padding: 3rem 1rem;
  color: var(--text-2);
}
</style>
