<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { coreAdminSession } from '@/core/admin/session';
import { useEducationWorkspace } from './workspace';
import { educationApi } from './api';
import { useEducationI18n } from './i18n';
const workspace = useEducationWorkspace();
const { t } = useEducationI18n();
const url = ref('');
const initials = computed(() =>
  (workspace.header?.displayName ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((value) => value[0])
    .join(''),
);
let controller: AbortController | null = null;
function clear(): void {
  if (url.value) URL.revokeObjectURL(url.value);
  url.value = '';
}
watch(
  [() => workspace.header?.avatarFileId, () => workspace.revision],
  async ([id], _, cleanup) => {
    controller?.abort();
    controller = new AbortController();
    const signal = controller.signal;
    cleanup(() => controller?.abort());
    clear();
    if (typeof id !== 'string') return;
    const epoch = coreAdminSession.epoch();
    const revision = workspace.revision;
    try {
      const blob = await educationApi.download(workspace.organizationId, id, signal);
      if (
        !signal.aborted &&
        epoch === coreAdminSession.epoch() &&
        revision === workspace.revision &&
        blob.type.startsWith('image/')
      )
        url.value = URL.createObjectURL(blob);
    } catch {
      /* A missing profile image leaves the actual profile initials visible. */
    }
  },
  { immediate: true },
);
onBeforeUnmount(() => {
  controller?.abort();
  clear();
});
</script>
<template>
  <RouterLink
    to="/more"
    class="edu-avatar"
    :aria-label="t('education.profile')"
  >
    <img
      v-if="url"
      :src="url"
      :alt="t('education.avatar')"
    />
    <span
      v-else
      aria-hidden="true"
    >
      {{ initials }}
    </span>
  </RouterLink>
</template>
