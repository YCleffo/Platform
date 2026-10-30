<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { commercialAuth } from '@/shared/api/commercial';
import { toErrorMessage } from '@/shared/errors/humanize';
import LoadingState from '@/shared/ui/LoadingState.vue';
import ErrorState from '@/shared/ui/ErrorState.vue';

const route = useRoute();
const loading = ref(true);
const error = ref<string | null>(null);
const verified = ref(false);

onMounted(async () => {
  const token = typeof route.query.token === 'string' ? route.query.token : '';
  if (token === '') {
    error.value = 'validation_failed';
    loading.value = false;
    return;
  }
  try {
    await commercialAuth.verifyEmail(token);
    verified.value = true;
  } catch (err) {
    error.value = toErrorMessage(err);
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <section>
    <h1 class="auth-title">{{ $t('auth.verifyTitle') }}</h1>
    <LoadingState v-if="loading" :label="$t('auth.verifyIntro')" />
    <ErrorState v-else-if="error" :message="error">
      <RouterLink :to="{ name: 'login' }">{{ $t('nav.login') }}</RouterLink>
    </ErrorState>
    <div v-else-if="verified" class="auth-success">
      <p>{{ $t('auth.verifySuccess') }}</p>
      <RouterLink :to="{ name: 'login' }">{{ $t('nav.login') }}</RouterLink>
    </div>
  </section>
</template>

<style scoped>
.auth-title {
  font-size: var(--text-xl);
}

.auth-success {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}
</style>
