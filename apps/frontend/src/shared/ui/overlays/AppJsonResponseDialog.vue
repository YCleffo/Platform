<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppDialog from './AppDialog.vue';

const props = defineProps<{ title?: string; data: unknown }>();
const { t } = useI18n();
const open = defineModel<boolean>({ default: false });
const text = computed(() => JSON.stringify(props.data, null, 2));
</script>

<template>
  <AppDialog v-model="open" :title="title ?? t('common.response')" size="full">
    <pre class="response-dialog__json">{{ text }}</pre>
  </AppDialog>
</template>

<style scoped>
.response-dialog__json {
  min-height: 100%;
  margin: 0;
  padding: var(--space-16);
  overflow: auto;
  border-radius: var(--radius-control-m);
  background: var(--surface-2);
  color: var(--text-1);
  font-family: var(--font-mono);
  font-size: var(--type-body-medium-size);
  line-height: 1.55;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  transition: var(--theme-transition);
}
</style>
