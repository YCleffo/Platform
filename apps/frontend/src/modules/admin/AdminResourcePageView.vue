<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { useAdminResourceConfigs } from './adminConfigs';
import AdminResourceView from './AdminResourceView.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';

const route = useRoute();
const configs = useAdminResourceConfigs();
const key = computed(() => String(route.meta.adminResource ?? ''));
const config = computed(() => configs.value[key.value]);
</script>

<template>
  <AdminResourceView
    v-if="config"
    :config="config"
  />
  <AppErrorState
    v-else
    :message="$t('admin.unknownResource')"
  />
</template>
