<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { studentSession } from './session';
import UniversalMessenger from '@/modules/chat/UniversalMessenger.vue';

const route = useRoute();
const router = useRouter();
const initialThreadId = computed(() =>
  typeof route.params.id === 'string' ? route.params.id : '',
);

function onSelectThread(id: string): void {
  if (route.params.id !== id) {
    void router.push(`/student/messages/${id}`);
  }
}
</script>

<template>
  <section class="edu-student-messages-view">
    <UniversalMessenger
      v-if="studentSession.organizationId"
      :organization-id="studentSession.organizationId"
      :current-person-id="studentSession.personId"
      :initial-room-id="initialThreadId"
      :timezone="studentSession.timezone"
      @select-room="onSelectThread"
    />
  </section>
</template>
