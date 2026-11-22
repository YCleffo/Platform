import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { useHomeworkResource } from '../homework/resources';
import { studentPortalApi, type StudentAssignment } from './api';
import { studentSession } from './session';

export function isLearningAssignmentOpen(assignment: StudentAssignment): boolean {
  const now = Date.now();
  return (!assignment.opensAt || Date.parse(assignment.opensAt) <= now) &&
    (!assignment.dueAt || Date.parse(assignment.dueAt) >= now);
}

export function useLearningAssignments(code: 'platform-typing' | 'platform-logic', homeworkId: () => string) {
  const route = useRoute();
  const id = computed(() => typeof route.query.assignment === 'string' ? route.query.assignment : '');
  const resource = useHomeworkResource(
    () => studentSession.organizationId,
    (org, signal) => homeworkId() ? Promise.resolve(null) : studentPortalApi.activityConfig(org, code, signal),
    [homeworkId],
  );
  const items = computed(() => resource.data.value?.assignments.filter(
    (assignment) => assignment.origin === 'learning' && assignment.activityCode === code,
  ) ?? []);
  const selected = computed(() => items.value.find((assignment) => assignment.id === id.value) ?? null);
  const allowed = computed(() => !id.value || !!selected.value && isLearningAssignmentOpen(selected.value));
  return { ...resource, id, items, selected, allowed };
}
