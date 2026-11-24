<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { studentSession } from './session';
import EducationMessenger from '../messaging/EducationMessenger.vue';

const route = useRoute();
const router = useRouter();
const initialThreadId = computed(() => (typeof route.params.id === 'string' ? route.params.id : ''));

function onSelectThread(id: string): void {
  if (route.params.id !== id) {
    void router.push(`/student/messages/${id}`);
  }
}
</script>

<template>
  <section class="edu-student-messages-view">
    <EducationMessenger
      v-if="studentSession.organizationId"
      role="student"
      :organization-id="studentSession.organizationId"
      :current-person-id="studentSession.personId"
      :initial-thread-id="initialThreadId"
      :timezone="studentSession.timezone"
      @select-thread="onSelectThread"
    />
  </section>
</template>
