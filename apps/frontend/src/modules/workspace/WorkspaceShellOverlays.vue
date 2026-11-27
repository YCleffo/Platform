<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from 'vue';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { coreAdminSession } from '@/core/admin/session';
import { realtimeClient } from '@/shared/realtime/realtimeClient';
import { useOperationsCenter } from './useOperationsCenter';
import CommandPalette from './CommandPalette.vue';
import OperationsCenterDrawer from './OperationsCenterDrawer.vue';
import UndoToastHost from './UndoToastHost.vue';

const adminAuth = useAdminAuthStore();
const operations = useOperationsCenter();

let unsubRealtime: (() => void) | null = null;

watch(
  () => adminAuth.isAuthenticated,
  async (authed) => {
    if (authed) {
      unsubRealtime = operations.initRealtime();
      const orgId = await operations.ensureOrganization();
      if (orgId && coreAdminSession.accessToken()) {
        void realtimeClient.connect(orgId).catch(() => undefined);
      }
      void operations.syncOperations().catch(() => undefined);
    } else {
      operations.reset();
    }
  },
  { immediate: true },
);

onMounted(() => {
  if (adminAuth.isAuthenticated) {
    unsubRealtime = operations.initRealtime();
  }
});

onBeforeUnmount(() => {
  unsubRealtime?.();
});
</script>

<template>
  <CommandPalette />
  <OperationsCenterDrawer />
  <UndoToastHost />
</template>
