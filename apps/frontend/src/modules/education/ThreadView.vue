<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useEducationWorkspace } from './workspace';
import EducationMessenger from './messaging/EducationMessenger.vue';

const route = useRoute();
const router = useRouter();
const workspace = useEducationWorkspace();
const id = computed(() => String(route.params.id || ''));

function onSelectThread(newId: string): void {
  if (route.params.id !== newId) {
    void router.push(`/messages/${newId}`);
  }
}
</script>

<template>
  <section class="edu-thread-page">
    <EducationMessenger
      v-if="workspace.organizationId && workspace.header"
      role="teacher"
      :organization-id="workspace.organizationId"
      :current-person-id="workspace.header.personId"
      :initial-thread-id="id"
      :timezone="workspace.header.timezone"
      @select-thread="onSelectThread"
    />
  </section>
</template>
