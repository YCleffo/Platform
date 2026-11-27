<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AssessmentConstructor from '@/modules/activity/AssessmentConstructor.vue';
import type { AssessmentItem, AssessmentVersion } from '../activityTypes';
import type { ActivityItem } from '@/modules/activity/activityApi';

const props = defineProps<{
  assessmentId?: string;
}>();

const emit = defineEmits<{
  saved: [item: AssessmentItem | ActivityItem];
  published: [item: (AssessmentItem & AssessmentVersion) | ActivityItem];
  cancel: [];
}>();

const route = useRoute();
const router = useRouter();

const resolvedId = computed<string | undefined>(() => {
  if (props.assessmentId) return props.assessmentId;
  const param = route?.params?.id;
  return typeof param === 'string' && param !== '' ? param : undefined;
});

function handleSaved(item: AssessmentItem | ActivityItem): void {
  emit('saved', item);
  if (!resolvedId.value && item?.id && router) {
    void router.replace({ name: 'education-assessment-edit', params: { id: item.id } });
  }
}

function handlePublished(item: (AssessmentItem & AssessmentVersion) | ActivityItem): void {
  emit('published', item);
  if (!resolvedId.value && item?.id && router) {
    void router.replace({ name: 'education-assessment-edit', params: { id: item.id } });
  }
}

function handleDuplicated(newId: string): void {
  if (router) {
    void router.replace({ name: 'education-assessment-edit', params: { id: newId } });
  }
}

function handleCancel(): void {
  emit('cancel');
  if (router) {
    void router.push({ name: 'education-assessments' });
  }
}
</script>

<template>
  <AssessmentConstructor
    mode="education"
    :assessment-id="resolvedId"
    @saved="handleSaved"
    @published="handlePublished"
    @duplicated="handleDuplicated"
    @cancel="handleCancel"
  />
</template>
