<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useEducationWorkspace } from './workspace';
import EducationMessenger from './messaging/EducationMessenger.vue';

const route = useRoute();
const router = useRouter();
const workspace = useEducationWorkspace();
const initialThreadId = computed(() => (typeof route.params.id === 'string' ? route.params.id : ''));

function onSelectThread(id: string): void {
  if (route.params.id !== id) {
    void router.push(`/messages/${id}`);
  }
}
</script>

<template>
  <section class="edu-messages-page">
    <EducationMessenger
      v-if="workspace.organizationId && workspace.header"
      role="teacher"
      :organization-id="workspace.organizationId"
      :current-person-id="workspace.header.personId"
      :initial-thread-id="initialThreadId"
      :timezone="workspace.header.timezone"
      @select-thread="onSelectThread"
    />
  </section>
</template>
