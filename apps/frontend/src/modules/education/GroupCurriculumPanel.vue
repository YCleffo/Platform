<script setup lang="ts">
import { computed, ref } from 'vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import { educationApi } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationResource, useEducationMutation, useEducationError } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
const props = defineProps<{ groupId: string; archived?: boolean }>();
const workspace = useEducationWorkspace();
const { t } = useEducationI18n();
const issue = useEducationError();
const programId = ref('');
const { data, loading, error, refresh } = useEducationResource(
  async (org, signal) => {
    const [catalog, assigned, progress] = await Promise.all([
      educationApi.programs(org, 0, signal),
      educationApi.groupPrograms(org, props.groupId, signal),
      educationApi.groupProgress(org, props.groupId, signal),
    ]);
    return { catalog, assigned, progress };
  },
  [() => props.groupId],
);
const programs = computed(() => data.value?.catalog.items.filter((p) => !p.archivedAt) ?? []);
const mutation = useEducationMutation();
const { busy, error: actionError, uncertain } = mutation;
async function assign(): Promise<void> {
  await mutation.run(
    () => educationApi.assignProgram(workspace.organizationId, props.groupId, programId.value),
    () => refresh(),
  );
}
</script>
<template>
  <article class="edu-card edu-module">
    <div class="edu-section-title">
      <h2>{{ t('education.assignedPrograms') }}</h2>
      <RouterLink
        to="/programs"
        class="edu-link"
      >
        {{ t('education.programs') }}
      </RouterLink>
    </div>
    <EducationState
      :loading="loading && !data"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data">
      <p
        v-if="!data.assigned.items.length"
        class="edu-subtle"
      >
        {{ t('education.noAssignedPrograms') }}
      </p>
      <div
        v-for="program in data.progress.programs"
        :key="program.assignmentId"
        class="edu-module"
      >
        <strong>{{ program.name }}</strong>
        <div class="edu-progress">
          <progress
            :value="program.completedLessons"
            :max="program.totalLessons || 1"
            :aria-label="program.name"
          ></progress>
          <span>
            {{ program.completedLessons }} / {{ program.totalLessons }} · {{ program.percent }}%
          </span>
        </div>
        <ul class="edu-module__lessons">
          <li
            v-for="m in program.modules"
            :key="m.id ?? m.name"
          >
            <div>
              <strong>{{ m.name }}</strong>
              <p class="edu-subtle">
                {{ m.completedLessons }} / {{ m.totalLessons }} · {{ m.percent }}%
              </p>
            </div>
          </li>
        </ul>
      </div>
      <p class="edu-subtle">{{ t('education.catalogHistoryHint') }}</p>
      <form
        v-if="!archived && programs.length"
        class="edu-form"
        @submit.prevent="assign"
      >
        <AppDropdown
          id="group-program"
          v-model="programId"
          :label="t('education.programs')"
          :options="programs.map((p) => ({ value: p.id, label: p.name }))"
          :disabled="busy || uncertain"
        />
        <button
          class="edu-button"
          :disabled="!programId || busy || uncertain"
        >
          {{ t('education.assignProgram') }}
        </button>
      </form>
      <RouterLink
        v-else-if="!archived"
        to="/programs"
        class="edu-button"
      >
        {{ t('education.createProgram') }}
      </RouterLink>
      <RouterLink
        v-if="!archived && data.assigned.items.length"
        :to="`/lessons/new?group=${groupId}`"
        class="edu-button edu-module"
      >
        {{ t('education.createLesson') }}
      </RouterLink>
    </template>
    <p
      v-if="actionError"
      class="edu-error"
      role="alert"
    >
      {{ uncertain ? t('education.uncertain') : issue(actionError) }}
    </p>
  </article>
</template>
