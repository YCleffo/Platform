<script setup lang="ts">
import { studentSession } from './session';
import { activityApi } from '../activityApi';
import { useHomeworkResource } from '../homework/resources';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
import AchievementList from '../AchievementList.vue';
const { t } = useEducationI18n();
const { data, loading, error, refresh } = useHomeworkResource(
  () => studentSession.organizationId,
  activityApi.achievements,
);
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h2>{{ t('education.achievements') }}</h2>
      <RouterLink
        to="/student/progress"
        class="edu-link"
      >
        {{ t('education.myProgress') }}
      </RouterLink>
    </header>
    <p class="edu-subtle">{{ t('education.achievementHint') }}</p>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <AchievementList
      v-if="data"
      :items="data.items"
    />
  </section>
</template>
