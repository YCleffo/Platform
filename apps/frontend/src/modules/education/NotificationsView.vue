<script setup lang="ts">
import { ref } from 'vue';
import { formatZonedTime } from '@/shared/formatting/calendar';
import { educationApi } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationError } from './resources';
import { useHomeworkMutation, useHomeworkResource } from './homework/resources';
import { notificationTarget } from './homework/notifications';
import { studentSession } from './student/session';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
const props = withDefaults(defineProps<{ attention?: boolean; student?: boolean }>(), { attention: false, student: false });
const workspace = useEducationWorkspace();
const { t, locale } = useEducationI18n();
const offset = ref(0);
const issue = useEducationError();
const org = () => props.student ? studentSession.organizationId : workspace.organizationId;
const { data, loading, error, refresh } = useHomeworkResource(org,
  async (org, signal) => ({
    attention: props.attention ? await educationApi.attention(org, signal) : null,
    notifications: props.attention
      ? null
      : props.student ? await educationApi.studentNotifications(org, offset.value, signal) : await educationApi.notifications(org, offset.value, signal),
  }),
  [offset],
);
const mutation = useHomeworkMutation(org);
const { busy, error: actionError } = mutation;
async function read(id: string): Promise<void> {
  await mutation.run(
    () => props.student ? educationApi.readStudentNotification(org(), id) : educationApi.readNotification(org(), id),
    async () => {
      await refresh();
      await workspace.refreshHeader();
    },
  );
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ t(attention ? 'education.attention' : 'education.notifications') }}</h1>
      <RouterLink
        :to="student ? '/student/home' : '/today'"
        class="edu-link"
      >
        {{ t('education.today') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      :empty="
        data && !(data.attention?.items.length || data.notifications?.items.length)
          ? t('education.noNotifications')
          : undefined
      "
      @retry="refresh"
    />
    <p
      v-if="actionError"
      class="edu-error"
      role="alert"
    >
      {{ issue(actionError) }}
    </p>
    <ul
      v-if="data?.attention"
      class="edu-list"
    >
      <li
        v-for="item in data.attention.items"
        :key="item.id"
      >
        <RouterLink
          :to="`/lessons/${item.id}/participants`"
          class="edu-list__item"
        >
          <strong>{{ item.title }}</strong>
          <span>{{ t('education.absent', { count: item.absentCount }) }}</span>
        </RouterLink>
      </li>
    </ul>
    <ul
      v-if="data?.notifications"
      class="edu-list"
    >
      <li
        v-for="item in data.notifications.items"
        :key="item.id"
        class="edu-list__item"
      >
        <div>
          <RouterLink
            v-if="notificationTarget(item, student)"
            :to="notificationTarget(item, student)!"
            class="edu-link"
          >
            {{ item.kind === 'achievement_unlocked' ? t('education.achievements') : item.subject }}
          </RouterLink>
          <strong v-else>{{ item.kind === 'achievement_unlocked' ? t('education.achievements') : item.subject }}</strong>
          <p v-if="item.body">{{ item.kind === 'achievement_unlocked' ? t('education.activityAwardNotice') : item.body }}</p>
          <p class="edu-subtle">
            {{ formatZonedTime(item.createdAt, locale, workspace.header?.timezone ?? 'UTC') }}
          </p>
        </div>
        <button
          v-if="!item.readAt"
          type="button"
          class="edu-button"
          :disabled="busy"
          @click="read(item.id)"
        >
          {{ t('education.markRead') }}
        </button>
      </li>
    </ul>
    <div class="edu-form-actions">
      <button
        v-if="offset"
        type="button"
        class="edu-button"
        @click="offset = Math.max(0, offset - 50)"
      >
        {{ t('education.back') }}
      </button>
      <button
        v-if="
          data?.notifications?.nextOffset !== null && data?.notifications?.nextOffset !== undefined
        "
        type="button"
        class="edu-button"
        @click="offset = data!.notifications!.nextOffset!"
      >
        {{ t('education.loadMore') }}
      </button>
    </div>
  </section>
</template>
