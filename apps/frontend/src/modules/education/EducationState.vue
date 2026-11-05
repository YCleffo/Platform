<script setup lang="ts">
import { useEducationI18n } from './i18n';
import { useEducationError } from './resources';
defineProps<{ loading?: boolean; error?: unknown; empty?: string }>();
defineEmits<{ retry: [] }>();
const { t } = useEducationI18n();
const issue = useEducationError();
</script>
<template>
  <div
    v-if="loading"
    class="edu-state"
    role="status"
  >
    {{ t('education.loading') }}
  </div>
  <div
    v-else-if="error"
    class="edu-state edu-state--error"
    role="alert"
  >
    <p>{{ issue(error) }}</p>
    <button
      type="button"
      class="edu-button"
      @click="$emit('retry')"
    >
      {{ t('education.retry') }}
    </button>
  </div>
  <p
    v-else-if="empty"
    class="edu-state"
  >
    {{ empty }}
  </p>
</template>
