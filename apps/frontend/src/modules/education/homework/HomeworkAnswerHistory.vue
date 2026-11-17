<script setup lang="ts">
import { computed } from 'vue';
import { useEducationI18n } from '../i18n';
import { useEducationWorkspace } from '../workspace';
import type { HomeworkSubmission } from './api';
import HomeworkFiles from './HomeworkFiles.vue';
const props = defineProps<{
  submission: HomeworkSubmission;
  organizationId: string;
  assignmentId: string;
}>();
const { t, locale } = useEducationI18n();
const workspace = useEducationWorkspace();
const versions = computed(() =>
  [...props.submission.versions].sort((a, b) => b.sequence - a.sequence),
);
function date(value: string): string {
  return new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: workspace.header?.timezone ?? 'UTC',
  }).format(new Date(value));
}
</script>
<template>
  <section class="edu-card">
    <h2>{{ t('education.homeworkReviewHistory') }}</h2>
    <article
      v-for="review in submission.reviews"
      :key="review.id"
      class="edu-homework-review"
    >
      <strong>
        {{
          t(
            review.decision === 'accepted'
              ? 'education.homeworkAccepted'
              : 'education.homeworkReturned',
          )
        }}
      </strong>
      <p class="edu-subtle">
        {{ date(review.createdAt) }}
        <span v-if="review.resultStatus">
          ·
          {{
            t(
              `education.${review.resultStatus === 'needs_support' ? 'needsSupport' : review.resultStatus}`,
            )
          }}
        </span>
      </p>
      <p class="edu-homework-prose">{{ review.comment }}</p>
      <p
        v-if="review.teacherName"
        class="edu-subtle"
      >
        {{ review.teacherName }}
      </p>
    </article>
    <h2>{{ t('education.homeworkAnswerHistory') }}</h2>
    <details
      v-for="version in versions"
      :key="version.id"
      class="edu-homework-version"
    >
      <summary>
        {{ t('education.homeworkVersion') }} {{ version.sequence }} ·
        {{ date(version.submittedAt) }}
        <span v-if="version.late">· {{ t('education.homeworkLate') }}</span>
      </summary>
      <p class="edu-subtle">{{ t('education.homeworkVersion') }} {{ version.assignmentVersion }}</p>
      <p class="edu-homework-prose">{{ version.text }}</p>
      <p v-if="version.activityResult">
        {{
          version.activityResult.wpm !== undefined
            ? `${version.activityResult.wpm} ${t('education.wpm')}`
            : `${version.activityResult.score} ${t('education.score')}`
        }}
        · {{ version.activityResult.accuracy }}% · {{ version.activityResult.errors }}
        {{ t('education.errors') }}
      </p>
      <HomeworkFiles
        :organization-id="organizationId"
        :assignment-id="assignmentId"
        :files="version.files"
        :title="t('education.homeworkAttachments')"
      />
    </details>
  </section>
</template>
