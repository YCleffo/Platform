<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import UniversalMessenger from './UniversalMessenger.vue';

const route = useRoute();
const router = useRouter();
const organizations = ref<Array<{ id: string; name: string }>>([]);
const selectedOrgId = ref('');
const personId = ref('');
const loading = ref(true);

const initialRoomId = computed(() => (typeof route.params.id === 'string' ? route.params.id : ''));

async function loadContext(): Promise<void> {
  loading.value = true;
  try {
    const res = await coreAdminHttp.request<{
      items: Array<{ id: string; name: string }>;
      personId: string;
    }>('/chat/organizations', { method: 'GET' });
    organizations.value = res.items;
    personId.value = res.personId;
    if (!selectedOrgId.value && res.items[0]) {
      selectedOrgId.value = res.items[0].id;
    }
  } finally {
    loading.value = false;
  }
}

function onSelectRoom(id: string): void {
  if (route.params.id !== id) {
    void router.push(`/chat/${id}`);
  }
}

onMounted(() => {
  void loadContext();
});
</script>

<template>
  <section class="uchat-page">
    <div
      v-if="organizations.length > 1"
      class="uchat-page__org-bar"
    >
      <AppDropdown
        v-model="selectedOrgId"
        label="Organization"
        :options="organizations.map((o) => ({ value: o.id, label: o.name }))"
      />
    </div>
    <UniversalMessenger
      v-if="!loading && selectedOrgId && personId"
      :organization-id="selectedOrgId"
      :current-person-id="personId"
      :initial-room-id="initialRoomId"
      @select-room="onSelectRoom"
    />
  </section>
</template>
