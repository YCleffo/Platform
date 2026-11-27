<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useEducationWorkspace } from './workspace';
import UniversalMessenger from '@/modules/chat/UniversalMessenger.vue';

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
    <UniversalMessenger
      v-if="workspace.organizationId && workspace.header"
      :organization-id="workspace.organizationId"
      :current-person-id="workspace.header.personId"
      :initial-room-id="id"
      :timezone="workspace.header.timezone"
      @select-room="onSelectThread"
    />
  </section>
</template>
