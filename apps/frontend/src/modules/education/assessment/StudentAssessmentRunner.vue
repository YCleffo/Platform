<script setup lang="ts">
import ActivityRunnerView from '@/modules/activity/ActivityRunnerView.vue';
import type { AssessmentResult } from '../activityTypes';

defineProps<{
  assessmentId?: string;
  attemptId?: string;
  assignmentId?: string;
}>();

const emit = defineEmits<{
  finished: [result: AssessmentResult & { attemptId?: string }];
  close: [];
}>();

function handleFinished(res: {
  attemptId: string;
  score: number;
  maxScore: number;
  percentage: number;
  passed: boolean;
  breakdown?: Array<Record<string, unknown>>;
  feedback?: string;
}): void {
  emit('finished', {
    attemptId: res.attemptId,
    score: res.score,
    maxScore: res.maxScore,
    percentage: res.percentage,
    passed: res.passed,
    passingScorePercent: 60,
    totalQuestions: Array.isArray(res.breakdown) ? res.breakdown.length : 0,
    correctQuestions: Array.isArray(res.breakdown)
      ? res.breakdown.filter((b) => b.correct === true).length
      : 0,
    elapsedSeconds: 0,
  });
}
</script>

<template>
  <ActivityRunnerView
    :activity-id="assessmentId"
    :attempt-id="attemptId"
    :assignment-id="assignmentId"
    :embedded="true"
    @finished="handleFinished"
    @close="emit('close')"
  />
</template>
