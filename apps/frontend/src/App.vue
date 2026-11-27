<script setup lang="ts">
import AppToast from '@/shared/ui/feedback/AppToast.vue';
import WorkspaceShellOverlays from '@/modules/workspace/WorkspaceShellOverlays.vue';
</script>

<template>
  <RouterView />
  <WorkspaceShellOverlays />
  <AppToast />
</template>
