<script setup lang="ts">
import { educationApi } from './api';
import { useEducationResource } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
const props = defineProps<{ studentId?: string }>();
const { t } = useEducationI18n();
const { data, loading, error, refresh } = useEducationResource(
  (org, signal) =>
    props.studentId
      ? educationApi.studentCurriculumProgress(org, props.studentId, signal)
      : educationApi.myCurriculumProgress(org, signal),
  [() => props.studentId],
);
</script>
<template>
  <article class="edu-card edu-module">
    <h2>{{ t('education.programs') }}</h2>
    <EducationState
      :loading="loading && !data"
      :error="error"
      :empty="data && !data.programs.length ? t('education.noAssignedPrograms') : undefined"
      @retry="refresh"
    />
    <div
      v-for="program in data?.programs ?? []"
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
  </article>
</template>
