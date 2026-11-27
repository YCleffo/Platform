<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import { ApiError } from '@/shared/api/errors';
import {
  activityApi,
  type ActivityAttempt,
  type ActivityAttemptSummary,
  type ActivityItem,
} from '@/modules/activity/activityApi';
import { actT } from '@/modules/activity/activityI18n';
import UnifiedAttemptResultPanel from '@/modules/activity/components/UnifiedAttemptResultPanel.vue';
import { useEducationWorkspace } from '../workspace';
import { studentSession } from '../student/session';

const props = defineProps<{
  assessmentId?: string;
  attemptId?: string;
}>();

const route = useRoute();
const router = useRouter();
const workspace = useEducationWorkspace();

const resolvedAssessmentId = computed(() =>
  props.assessmentId || (typeof route.params.id === 'string' ? route.params.id : ''),
);
const resolvedAttemptId = computed(() =>
  props.attemptId || (typeof route.params.attemptId === 'string' ? route.params.attemptId : ''),
);
const organizationId = computed(
  () => workspace.organizationId || studentSession.organizationId || '',
);

const activity = ref<ActivityItem | null>(null);
const attempts = ref<ActivityAttemptSummary[]>([]);
const selectedAttempt = ref<ActivityAttempt | null>(null);
const activeFilter = ref<'ALL' | 'PENDING_REVIEW' | 'EVALUATED'>('ALL');
const loadingList = ref(false);
const loadingAttempt = ref(false);
const errorMsg = ref('');

const pendingCount = computed(
  () => attempts.value.filter((a) => a.state === 'PENDING_REVIEW').length,
);

const filteredAttempts = computed(() => {
  if (activeFilter.value === 'ALL') return attempts.value;
  return attempts.value.filter((a) => a.state === activeFilter.value);
});

async function loadList(): Promise<void> {
  if (!organizationId.value || !resolvedAssessmentId.value) return;
  loadingList.value = true;
  errorMsg.value = '';
  try {
    const [act, attRes] = await Promise.all([
      activityApi.getActivity(organizationId.value, resolvedAssessmentId.value),
      activityApi.listActivityAttempts(organizationId.value, resolvedAssessmentId.value),
    ]);
    activity.value = act;
    attempts.value = attRes.items;
    const firstAttempt = attRes.items[0];
    if (!resolvedAttemptId.value && firstAttempt) {
      await selectAttempt(firstAttempt.id);
    }
  } catch (err) {
    errorMsg.value = err instanceof ApiError ? err.message : actT('loadError');
  } finally {
    loadingList.value = false;
  }
}

async function loadAttempt(attId: string): Promise<void> {
  if (!organizationId.value || !attId) {
    selectedAttempt.value = null;
    return;
  }
  loadingAttempt.value = true;
  errorMsg.value = '';
  try {
    selectedAttempt.value = await activityApi.getAttempt(organizationId.value, attId);
  } catch (err) {
    errorMsg.value = err instanceof ApiError ? err.message : actT('loadError');
    selectedAttempt.value = null;
  } finally {
    loadingAttempt.value = false;
  }
}

async function selectAttempt(attId: string): Promise<void> {
  if (!resolvedAssessmentId.value) return;
  if (resolvedAttemptId.value !== attId && router) {
    await router.replace({
      name: 'education-assessment-attempt-result',
      params: { id: resolvedAssessmentId.value, attemptId: attId },
    });
  } else {
    await loadAttempt(attId);
  }
}

function onAttemptUpdated(updated: ActivityAttempt): void {
  selectedAttempt.value = updated;
  const idx = attempts.value.findIndex((a) => a.id === updated.id);
  const existing = idx !== -1 ? attempts.value[idx] : undefined;
  if (idx !== -1 && existing) {
    attempts.value[idx] = {
      ...existing,
      state: updated.state,
      rawScore: updated.rawScore !== null ? Number(updated.rawScore) : null,
      maxScore: updated.maxScore !== null ? Number(updated.maxScore) : null,
      percentage: updated.percentage !== null ? Number(updated.percentage) : null,
      passed: updated.passed,
      resultsPublishedAt: updated.resultsPublishedAt ?? null,
      reviewNotes: updated.reviewNotes,
      requiresManualReview: Boolean(updated.evaluation?.requiresManualReview),
    };
  }
}

function goBack(): void {
  if (router) {
    void router.push({ name: 'education-assessments' });
  }
}

watch(resolvedAttemptId, (attId) => {
  if (attId) {
    void loadAttempt(attId);
  }
});

watch(resolvedAssessmentId, () => {
  void loadList();
});

onMounted(async () => {
  await loadList();
  if (resolvedAttemptId.value) {
    await loadAttempt(resolvedAttemptId.value);
  }
});
</script>

<template>
  <section class="edu-results-workspace">
    <header class="edu-results-workspace__head">
      <div>
        <h1>{{ activity?.title || actT('resultsTitle') }}</h1>
        <p class="edu-results-workspace__sub">{{ actT('resultsTitle') }}</p>
      </div>
      <AppButton
        variant="secondary"
        @click="goBack"
      >
        ← {{ actT('backToList') }}
      </AppButton>
    </header>

    <p
      v-if="errorMsg"
      class="edu-results-workspace__error"
    >
      {{ errorMsg }}
    </p>

    <div class="edu-results-workspace__grid">
      <aside class="edu-results-workspace__sidebar">
        <div class="edu-results-workspace__filters">
          <button
            type="button"
            class="edu-filter-tab"
            :class="{ 'edu-filter-tab--active': activeFilter === 'ALL' }"
            @click="activeFilter = 'ALL'"
          >
            {{ actT('allAttempts') }} ({{ attempts.length }})
          </button>
          <button
            type="button"
            class="edu-filter-tab"
            :class="{ 'edu-filter-tab--active': activeFilter === 'PENDING_REVIEW' }"
            @click="activeFilter = 'PENDING_REVIEW'"
          >
            {{ actT('reviewQueue') }} ({{ pendingCount }})
          </button>
          <button
            type="button"
            class="edu-filter-tab"
            :class="{ 'edu-filter-tab--active': activeFilter === 'EVALUATED' }"
            @click="activeFilter = 'EVALUATED'"
          >
            {{ actT('evaluatedTab') }}
          </button>
        </div>

        <p
          v-if="loadingList"
          class="edu-results-workspace__muted"
        >
          {{ actT('loadingText') }}
        </p>
        <p
          v-else-if="filteredAttempts.length === 0"
          class="edu-results-workspace__muted"
        >
          {{ actT('noAttempts') }}
        </p>

        <ul
          v-else
          class="edu-attempt-list"
        >
          <li
            v-for="att in filteredAttempts"
            :key="att.id"
          >
            <button
              type="button"
              class="edu-attempt-item"
              :class="{ 'edu-attempt-item--active': selectedAttempt?.id === att.id }"
              @click="void selectAttempt(att.id)"
            >
              <div class="edu-attempt-item__top">
                <strong>{{ att.participantName }}</strong>
                <AppBadge
                  :tone="
                    att.state === 'PENDING_REVIEW'
                      ? 'warn'
                      : att.passed
                        ? 'ok'
                        : 'danger'
                  "
                >
                  {{
                    att.state === 'PENDING_REVIEW'
                      ? actT('manualReviewBadge')
                      : `${att.percentage ?? 0}%`
                  }}
                </AppBadge>
              </div>
              <div class="edu-attempt-item__meta">
                <span>v{{ att.versionNumber }}</span>
                <span>
                  {{ att.resultsPublishedAt ? actT('publishedBadge') : actT('unpublishedBadge') }}
                </span>
              </div>
            </button>
          </li>
        </ul>
      </aside>

      <main class="edu-results-workspace__main">
        <p
          v-if="loadingAttempt"
          class="edu-results-workspace__muted"
        >
          {{ actT('loadingText') }}
        </p>
        <UnifiedAttemptResultPanel
          v-else-if="selectedAttempt && organizationId"
          :organization-id="organizationId"
          :attempt="selectedAttempt"
          :can-review="true"
          @updated="onAttemptUpdated"
        />
        <p
          v-else
          class="edu-results-workspace__muted"
        >
          {{ actT('selectAttemptHint') }}
        </p>
      </main>
    </div>
  </section>
</template>

<style scoped>
.edu-results-workspace {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  padding: 1.5rem;
  color: var(--text-1);
}

.edu-results-workspace__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
}

.edu-results-workspace__head h1 {
  margin: 0;
  font-size: 1.5rem;
}

.edu-results-workspace__sub {
  margin: 0.2rem 0 0;
  color: var(--text-2);
  font-size: 0.9rem;
}

.edu-results-workspace__error {
  margin: 0;
  padding: 0.75rem 1rem;
  border-radius: 0.5rem;
  background: var(--surface-2);
  border: 1px solid var(--danger, #dc2626);
  color: var(--danger, #dc2626);
}

.edu-results-workspace__grid {
  display: grid;
  grid-template-columns: minmax(260px, 340px) 1fr;
  gap: 1.25rem;
  align-items: start;
}

@media (max-width: 900px) {
  .edu-results-workspace__grid {
    grid-template-columns: 1fr;
  }
}

.edu-results-workspace__sidebar {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1rem;
  border-radius: 0.75rem;
  background: var(--surface-2);
  border: 1px solid var(--border);
}

.edu-results-workspace__filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.edu-filter-tab {
  padding: 0.35rem 0.65rem;
  border-radius: 0.375rem;
  border: 1px solid var(--border);
  background: var(--surface-1);
  color: var(--text-2);
  font-size: 0.8rem;
  cursor: pointer;
}

.edu-filter-tab--active {
  background: var(--accent);
  color: #fff;
  border-color: var(--accent);
}

.edu-attempt-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.edu-attempt-item {
  width: 100%;
  text-align: left;
  padding: 0.75rem;
  border-radius: 0.5rem;
  border: 1px solid var(--border);
  background: var(--surface-1);
  color: var(--text-1);
  cursor: pointer;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.edu-attempt-item--active {
  border-color: var(--accent);
  box-shadow: 0 0 0 1px var(--accent);
}

.edu-attempt-item__top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
}

.edu-attempt-item__meta {
  display: flex;
  justify-content: space-between;
  font-size: 0.78rem;
  color: var(--text-2);
}

.edu-results-workspace__main {
  padding: 1.25rem;
  border-radius: 0.75rem;
  background: var(--surface-2);
  border: 1px solid var(--border);
  min-height: 320px;
}

.edu-results-workspace__muted {
  color: var(--text-2);
  font-size: 0.9rem;
}
</style>
