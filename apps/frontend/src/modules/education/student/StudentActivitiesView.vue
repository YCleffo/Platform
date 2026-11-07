<script setup lang="ts">
import { computed } from 'vue';
import { studentPortalApi } from './api';
import { studentSession } from './session';
import { useEducationResource } from '../resources';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
const { t } = useEducationI18n();
const org = computed(() => studentSession.organizationId);
const { data, loading, error, refresh } = useEducationResource(
  async (_org, signal) => studentPortalApi.activities(org.value, signal),
  [org],
);
function target(code: string): string {
  return code === 'kiberone-typing' ? `/student/typing/${code}` : `/student/activities`;
}
</script>
<template>
  <section>
    <h2>{{ t('education.activities') }}</h2>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data">
      <EducationState
        v-if="!data.items.length"
        :empty="t('education.noActivities')"
      />
      <ul
        v-else
        class="edu-list"
      >
        <li
          v-for="activity in data.items"
          :key="activity.id"
          class="edu-list__item"
        >
          <RouterLink
            :to="target(activity.code)"
            class="edu-list__main"
          >
            <strong>{{ activity.name }}</strong>
            <p class="edu-subtle">{{ activity.kind }}</p>
          </RouterLink>
          <RouterLink
            :to="target(activity.code)"
            class="edu-button"
          >
            {{ t('education.openActivity') }}
          </RouterLink>
        </li>
      </ul>
    </template>
  </section>
</template>
