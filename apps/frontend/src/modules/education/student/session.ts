import { reactive } from 'vue';

export const studentSession = reactive<{
  organizationId: string;
  displayName: string | null;
  timezone: string;
  personId: string;
}>({
  organizationId: '',
  displayName: null,
  timezone: 'UTC',
  personId: '',
});
