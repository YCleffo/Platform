import { reactive } from 'vue';

export const studentSession = reactive<{
  organizationId: string;
  displayName: string | null;
  timezone: string;
}>({
  organizationId: '',
  displayName: null,
  timezone: 'UTC',
});
