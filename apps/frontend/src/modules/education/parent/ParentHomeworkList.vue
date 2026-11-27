<script setup lang="ts">
import { ref } from 'vue';
import { parentApi, type HomeworkMaterial, type ParentHomework } from './api';
import { useParentWorkspace } from './workspace';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
const props = defineProps<{ items: ParentHomework[]; timezone: string; compact?: boolean }>();
const workspace = useParentWorkspace();
const { t, locale } = useEducationI18n();
const downloadingId = ref<string | null>(null);
const when = (value: string) =>
  new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: props.timezone,
  }).format(new Date(value));

async function downloadMaterial(material: HomeworkMaterial): Promise<void> {
  const child = workspace.child;
  if (!child || downloadingId.value) return;
  downloadingId.value = material.id;
  try {
    const blob = await parentApi.downloadHomeworkMaterial(child, material.id);
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = material.name;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } finally {
    downloadingId.value = null;
  }
}
</script>
<template>
  <EducationState
    v-if="!items.length"
    :empty="t('education.noHomework')"
  />
  <ul
    v-else
    class="edu-list"
  >
    <li
      v-for="assignment in items"
      :key="assignment.id"
      class="edu-list__item"
    >
      <div>
        <strong>{{ assignment.title }}</strong>
        <p class="edu-subtle">
          {{ t('education.homeworkDue') }}:
          {{ assignment.dueAt ? when(assignment.dueAt) : t('education.homeworkNoDue') }}
        </p>
        <p
          v-if="!compact"
          class="edu-message"
        >
          {{ assignment.description }}
        </p>
        <p
          v-if="!compact"
          class="edu-message"
        >
          {{ assignment.instructions }}
        </p>
        <p
          v-if="assignment.submittedAt"
          class="edu-subtle"
        >
          {{ t('education.homeworkSubmitted') }}: {{ when(assignment.submittedAt) }}
        </p>
        <template v-if="assignment.review">
          <p>
            {{ t(`education.homework${assignment.review.decision.toUpperCase()}`) }}
            <span v-if="assignment.review.resultStatus">
              ·
              {{
                t(
                  `education.${assignment.review.resultStatus === 'needs_support' ? 'needsSupport' : assignment.review.resultStatus}`,
                )
              }}
            </span>
          </p>
          <p
            v-if="assignment.review.comment"
            class="edu-message"
          >
            {{ assignment.review.comment }}
          </p>
        </template>
        <template v-if="!compact && assignment.materials?.length">
          <p class="edu-subtle">{{ t('education.parentStudyMaterials') }}:</p>
          <div class="edu-form-actions">
            <button
              v-for="material in assignment.materials"
              :key="material.id"
              type="button"
              class="edu-link"
              :disabled="downloadingId === material.id"
              @click="downloadMaterial(material)"
            >
              {{ material.name }} ({{ t('education.parentDownloadMaterial') }})
            </button>
          </div>
        </template>
      </div>
      <span class="edu-status">{{ t(`education.homework${assignment.submissionState}`) }}</span>
    </li>
  </ul>
</template>
