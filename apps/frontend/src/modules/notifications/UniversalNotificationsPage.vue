<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { useOperationsCenter } from '@/modules/workspace/useOperationsCenter';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import NotificationsCenterView from './NotificationsCenterView.vue';

const operations = useOperationsCenter();
const organizations = ref<Array<{ id: string; name: string }>>([]);
const selectedOrgId = ref(operations.organizationId ?? '');
const personId = ref('');
const loading = ref(true);

const activeOrgId = computed(() => selectedOrgId.value || operations.organizationId || '');

watch(
  () => operations.organizationId,
  (next) => {
    if (next && next !== selectedOrgId.value) {
      selectedOrgId.value = next;
    }
  },
);

watch(selectedOrgId, (next) => {
  if (next && next !== operations.organizationId) {
    operations.setOrganizationId(next);
  }
});

onMounted(async () => {
  try {
    await operations.ensureOrganization();
    const res = await coreAdminHttp.request<{
      items: Array<{ id: string; name: string }>;
      personId: string;
    }>('/chat/organizations', { method: 'GET' });
    organizations.value = res.items;
    personId.value = res.personId;
    if (operations.organizationId && res.items.some((o) => o.id === operations.organizationId)) {
      selectedOrgId.value = operations.organizationId;
    } else if (res.items[0]) {
      selectedOrgId.value = res.items[0].id;
    }
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <section class="unotif-page">
    <AppDropdown
      v-if="organizations.length > 1"
      v-model="selectedOrgId"
      label="Organization"
      :options="organizations.map((o) => ({ value: o.id, label: o.name }))"
    />
    <NotificationsCenterView
      v-if="!loading && activeOrgId && personId"
      :organization-id="activeOrgId"
      :person-id="personId"
    />
  </section>
</template>
