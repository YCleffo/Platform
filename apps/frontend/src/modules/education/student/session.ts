import { reactive } from 'vue';

export const studentSession = reactive<{ organizationId: string; displayName: string | null }>({
  organizationId: '',
  displayName: null,
});
