<script setup lang="ts">
import { ref, watch } from 'vue';
import { educationApi } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationResource, useEducationMutation, useEducationError } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
const props = defineProps<{ groupId: string; archived?: boolean }>();
const workspace = useEducationWorkspace();
const { t } = useEducationI18n();
const issue = useEducationError();
const selected = ref<string[]>([]);
const { data, loading, error, refresh } = useEducationResource(
  (org, signal) => educationApi.groupTeachers(org, props.groupId, signal),
  [() => props.groupId],
);
watch(data, (value) => {
  if (value)
    selected.value = value.items.filter((item) => item.assigned).map((item) => item.accountId);
});
const mutation = useEducationMutation();
const { busy, error: actionError, uncertain } = mutation;
async function save(): Promise<void> {
  await mutation.run(
    () => educationApi.assignTeachers(workspace.organizationId, props.groupId, selected.value),
    () => refresh(),
  );
}
</script>
<template>
  <article class="edu-card edu-module">
    <h2>{{ t('education.teacherAssignments') }}</h2>
    <EducationState
      :loading="loading && !data"
      :error="error"
      @retry="refresh"
    />
    <form
      v-if="data"
      class="edu-form"
      @submit.prevent="save"
    >
      <p class="edu-subtle">{{ t('education.teacherAssignmentHint') }}</p>
      <template
        v-for="teacher in data.items"
        :key="teacher.accountId"
      >
        <label
          v-if="(data.canAssign && !archived) || teacher.assigned"
          :for="`teacher-${teacher.accountId}`"
          class="edu-teacher-choice"
        >
          <input
            :id="`teacher-${teacher.accountId}`"
            v-model="selected"
            type="checkbox"
            :value="teacher.accountId"
            :disabled="!data.canAssign || archived || busy || uncertain"
          />
          {{ teacher.name }}
        </label>
      </template>
      <p
        v-if="actionError"
        class="edu-error"
        role="alert"
      >
        {{ uncertain ? t('education.uncertain') : issue(actionError) }}
      </p>
      <button
        v-if="data.canAssign && !archived"
        class="edu-button"
        :disabled="busy || uncertain"
      >
        {{ t('education.save') }}
      </button>
    </form>
  </article>
</template>
<style scoped>
.edu-teacher-choice {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 44px;
}
.edu-teacher-choice input {
  width: 20px;
  height: 20px;
  accent-color: var(--accent);
}
</style>
