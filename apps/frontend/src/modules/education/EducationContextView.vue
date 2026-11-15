<script setup lang="ts">
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useEducationI18n } from './i18n';
import { educationApi } from './api';
import { educationContexts } from './contexts';
import { onScopeDispose, ref, shallowRef } from 'vue';
import type { EducationContext } from './api';
import { coreAdminSession } from '@/core/admin/session';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import EducationState from './EducationState.vue';
import './education.css';
const { t } = useEducationI18n();
const router = useRouter();
const auth = useAdminAuthStore();
const data = shallowRef<EducationContext | null>(null);
const error = shallowRef<unknown>(null);
const loading = ref(false);
let controller: AbortController | null = null;
async function refresh(): Promise<void> {
  controller?.abort();
  const request = new AbortController();
  controller = request;
  const epoch = coreAdminSession.epoch();
  data.value = null;
  error.value = null;
  loading.value = true;
  try {
    const context = await educationApi.context(request.signal);
    if (request.signal.aborted || epoch !== coreAdminSession.epoch()) return;
    data.value = context;
    const choices = educationContexts(context);
    if (choices.length === 1) await router.replace(choices[0]!.path);
  } catch (failure) {
    if (!request.signal.aborted && epoch === coreAdminSession.epoch()) error.value = failure;
  } finally {
    if (!request.signal.aborted && epoch === coreAdminSession.epoch()) loading.value = false;
  }
}
onMounted(() => {
  void refresh();
});
onScopeDispose(() => {
  controller?.abort();
  data.value = null;
});
</script>
<template>
  <main class="education-login">
    <section class="edu-login-card">
      <p class="edu-brand">Education</p>
      <h1>{{ t('education.chooseContext') }}</h1>
      <EducationState
        :loading="loading"
        :error="error"
        @retry="refresh"
      />
      <nav
        v-if="data"
        class="edu-form"
      >
        <RouterLink
          v-for="choice in educationContexts(data)"
          :key="choice.kind"
          :to="choice.path"
          class="edu-button"
        >
          {{ t(`education.context${choice.kind}`) }}
        </RouterLink>
        <RouterLink
          to="/parent/activate"
          class="edu-link"
        >
          {{ t('education.activateParentInvitation') }}
        </RouterLink>
      </nav>
      <p>
        <button
          type="button"
          class="edu-link"
          @click="auth.logout().then(() => router.replace('/education/login'))"
        >
          {{ t('education.signOut') }}
        </button>
      </p>
    </section>
  </main>
</template>
