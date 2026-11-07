<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { educationApi } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationResource } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
const route = useRoute();
const workspace = useEducationWorkspace();
const { t } = useEducationI18n();
const id = computed(() => String(route.params.id ?? ''));
const offset = ref(0);
const activity = computed(() => 'kiberone-typing');
const duration = ref(30);
const assignError = ref('');
const { data, loading, error, refresh } = useEducationResource(
  async (org, signal) => {
    const [group, results] = await Promise.all([
      educationApi.group(org, id.value, signal),
      educationApi.groupActivityResults(org, id.value, activity.value, offset.value, signal),
    ]);
    return { group, results };
  },
  [id, activity, offset],
);
async function assign(): Promise<void> {
  assignError.value = '';
  try {
    await educationApi.assignActivity(workspace.organizationId, activity.value, {
      groupId: id.value,
      config: { durationSeconds: duration.value },
    });
    await refresh();
  } catch (failure) {
    assignError.value = failure instanceof Error ? failure.message : 'error';
  }
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ data?.group.name ?? t('education.activities') }} · {{ t('education.activities') }}</h1>
      <RouterLink
        :to="`/groups/${id}`"
        class="edu-link"
      >
        {{ t('education.group') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data">
      <form
        class="edu-form edu-card"
        @submit.prevent="assign"
      >
        <h2>{{ t('education.assignActivity') }}</h2>
        <div class="edu-form-actions">
          <label>
            {{ t('education.activity') }}
            <select
              v-model="activity"
              class="edu-input"
            >
              <option value="kiberone-typing">KiberOne Typing</option>
            </select>
          </label>
          <label>
            {{ t('education.duration') }}
            <select
              v-model="duration"
              class="edu-input"
            >
              <option :value="15">15 {{ t('education.seconds') }}</option>
              <option :value="30">30 {{ t('education.seconds') }}</option>
              <option :value="60">60 {{ t('education.seconds') }}</option>
            </select>
          </label>
          <button
            type="submit"
            class="edu-button edu-button--primary"
          >
            {{ t('education.assignActivity') }}
          </button>
        </div>
        <p
          v-if="assignError"
          class="edu-error"
          role="alert"
        >
          {{ assignError }}
        </p>
      </form>
      <div class="edu-section-title">
        <h2>{{ t('education.results') }}</h2>
      </div>
      <EducationState
        v-if="!data.results.items.length"
        :empty="t('education.noTrainingResults')"
      />
      <ul
        v-else
        class="edu-list"
      >
        <li
          v-for="attempt in data.results.items"
          :key="attempt.id"
          class="edu-list__item"
        >
          <RouterLink
            :to="`/students/${attempt.studentId}`"
            class="edu-list__main"
          >
            <strong>{{ attempt.name }}</strong>
            <p class="edu-subtle">{{ attempt.startedAt }}</p>
          </RouterLink>
          <span v-if="attempt.wpm !== null && attempt.wpm !== undefined">
            {{ attempt.wpm }} {{ t('education.wpm') }} · {{ attempt.accuracy }}%
          </span>
          <span v-else>{{ attempt.status }}</span>
        </li>
      </ul>
      <div class="edu-form-actions">
        <button
          v-if="offset"
          type="button"
          class="edu-button"
          @click="offset = Math.max(0, offset - 50)"
        >
          {{ t('education.back') }}
        </button>
        <button
          v-if="data.results.nextOffset !== null"
          type="button"
          class="edu-button"
          @click="offset = data.results.nextOffset ?? 0"
        >
          {{ t('education.loadMore') }}
        </button>
      </div>
    </template>
  </section>
</template>
