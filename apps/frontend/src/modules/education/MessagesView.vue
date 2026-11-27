<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useEducationWorkspace } from './workspace';
import UniversalMessenger from '@/modules/chat/UniversalMessenger.vue';

const route = useRoute();
const router = useRouter();
const workspace = useEducationWorkspace();
const initialThreadId = computed(() =>
  typeof route.params.id === 'string' ? route.params.id : '',
);

function onSelectThread(id: string): void {
  if (route.params.id !== id) {
    void router.push(`/messages/${id}`);
  }
}
</script>

<template>
  <section class="edu-messages-page">
    <UniversalMessenger
      v-if="workspace.organizationId && workspace.header"
      :organization-id="workspace.organizationId"
      :current-person-id="workspace.header.personId"
      :initial-room-id="initialThreadId"
      :timezone="workspace.header.timezone"
      @select-room="onSelectThread"
    />
  </section>
</template>
