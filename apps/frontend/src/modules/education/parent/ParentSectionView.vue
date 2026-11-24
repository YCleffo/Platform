<script setup lang="ts">
import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue';
import { useRoute } from 'vue-router';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import { ApiError } from '@/shared/api/errors';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
import ActivityProgressPanel from '../ActivityProgressPanel.vue';
import type { Notification, Page } from '../api';
import {
  parentApi,
  type ParentHome,
  type ParentHomework,
  type ParentLesson,
  type ParentProgress,
} from './api';
import { parentChildKey, useParentWorkspace } from './workspace';
import { useParentResource } from './resources';
import ParentLessonList from './ParentLessonList.vue';
import ParentHomeworkList from './ParentHomeworkList.vue';
import EducationMessenger from '../messaging/EducationMessenger.vue';
const workspace = useParentWorkspace();
const route = useRoute();
const { t, locale } = useEducationI18n();
const section = computed(() => String(route.meta.parentSection ?? 'home'));
const offset = ref(0);
const today = new Intl.DateTimeFormat('en-CA', {
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  timeZone: workspace.context?.timezone ?? 'UTC',
}).format(new Date());
const year = Number(today.slice(0, 4)),
  month = Number(today.slice(5, 7));
const from = ref(section.value === 'schedule' ? today : `${today.slice(0, 7)}-01`);
const to = ref(
  section.value === 'schedule'
    ? new Date(Date.parse(`${today}T12:00:00Z`) + 6 * 86400000).toISOString().slice(0, 10)
    : new Date(Date.UTC(year, month, 0, 12)).toISOString().slice(0, 10),
);
const invalidPeriod = computed(
  () =>
    !from.value ||
    !to.value ||
    from.value > to.value ||
    Date.parse(to.value) - Date.parse(from.value) > 30 * 86400000,
);
const timezone = computed(() => workspace.context?.timezone ?? 'UTC');
watch(
  () => workspace.selectedKey,
  () => {
    offset.value = 0;
  },
);
interface SectionData {
  home?: ParentHome;
  lessons?: ParentLesson[];
  homework?: ParentHomework[];
  progress?: ParentProgress;
  notifications?: Notification[];
  nextOffset?: number | null;
}
const { data, error, loading, refresh } = useParentResource<SectionData>(
  async (child, signal) => {
    switch (section.value) {
      case 'schedule': {
        if (invalidPeriod.value) throw new ApiError(422, 'invalid_period', 'Invalid period');
        const schedule = await parentApi.schedule(child, from.value, to.value, signal);
        return { lessons: schedule.items };
      }
      case 'diary': {
        const page = await parentApi.diary(child, offset.value, signal, from.value, to.value);
        return { lessons: page.items, nextOffset: page.nextOffset };
      }
      case 'homework': {
        const page = await parentApi.homework(child, offset.value, signal);
        return { homework: page.items, nextOffset: page.nextOffset };
      }
      case 'progress':
        return { progress: await parentApi.progress(child, signal, from.value, to.value) };
      case 'notifications': {
        const page: Page<Notification> = await parentApi.notifications(child, offset.value, signal);
        return { notifications: page.items, nextOffset: page.nextOffset };
      }
      case 'messages':
        return {};
      default:
        return { home: await parentApi.home(child, signal) };
    }
  },
  [section, offset],
);
const readError = shallowRef<unknown>(null);
const readBusy = ref(false);
let readController: AbortController | null = null;
function resetRead(): void {
  readController?.abort();
  readController = null;
  readBusy.value = false;
  readError.value = null;
}
watch([() => workspace.revision, () => workspace.selectedKey, coreAdminSessionEpoch], resetRead, {
  flush: 'sync',
});
onScopeDispose(resetRead);
async function readNotification(id: string): Promise<void> {
  const child = workspace.child;
  if (!child || readBusy.value) return;
  const request = new AbortController();
  const epoch = coreAdminSession.epoch();
  const revision = workspace.revision;
  const current = () =>
    !request.signal.aborted &&
    epoch === coreAdminSession.epoch() &&
    revision === workspace.revision &&
    workspace.selectedKey === parentChildKey(child);
  readController = request;
  readBusy.value = true;
  readError.value = null;
  try {
    await parentApi.readNotification(child, id, request.signal);
    if (current()) await refresh();
  } catch (failure) {
    if (!current()) return;
    if (failure instanceof ApiError && failure.status === 403) {
      workspace.denyChild();
    } else if (failure instanceof ApiError && failure.status === 404) {
      // A deleted notice and a revoked relationship both return 404 on the write.
      // The scoped list read distinguishes them.
      await refresh();
    } else {
      readError.value = failure;
    }
  } finally {
    if (readController === request) {
      readController = null;
      readBusy.value = false;
    }
  }
}
function childSection(path: string) {
  const child = workspace.child;
  return { path, query: child ? { child: child.id, organization: child.organizationId } : {} };
}
const when = (value: string) =>
  new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: timezone.value,
  }).format(new Date(value));
const parentNotificationKinds = new Set([
  'parent_homework_published',
  'parent_homework_reviewed',
  'parent_homework_returned',
  'parent_homework_deadline_changed',
  'parent_schedule_changed',
  'parent_family_added',
  'parent_family_updated',
  'parent_family_relationship_changed',
  'parent_family_confirmed',
  'parent_activity_finished',
  'parent_achievement_unlocked',
  'parent_family_revoked',
  'parent_family_invited',
  'parent_family_invitation_revoked',
]);
const notificationLabel = (notification: Notification) =>
  t(
    parentNotificationKinds.has(notification.kind ?? '')
      ? `education.parentNotice_${notification.kind}`
      : 'education.notifications',
  );
</script>
<template>
  <section class="edu-parent-section">
    <header class="edu-page-title">
      <h2>{{ t(`education.parent${section}`) }}</h2>
      <button
        type="button"
        class="edu-link"
        :disabled="loading"
        @click="refresh"
      >
        {{ t('education.refresh') }}
      </button>
    </header>
    <form
      v-if="['schedule', 'diary', 'progress'].includes(section)"
      class="edu-form edu-card"
      @submit.prevent="
        offset = 0;
        refresh();
      "
    >
      <div class="edu-form-actions">
        <AppInput
          id="parent-schedule-from"
          v-model="from"
          type="date"
          :label="t('education.periodFrom')"
          required
        />
        <AppInput
          id="parent-schedule-to"
          v-model="to"
          type="date"
          :label="t('education.periodTo')"
          required
        />
        <button
          type="submit"
          class="edu-button"
          :disabled="invalidPeriod || loading"
        >
          {{ t('education.apply') }}
        </button>
      </div>
      <p
        v-if="invalidPeriod"
        class="edu-error"
      >
        {{ t('education.parentPeriodHint') }}
      </p>
    </form>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data && !loading && !error">
      <template v-if="data.home">
        <div class="edu-parent-grid">
          <article class="edu-card">
            <h2>{{ t('education.nextLesson') }}</h2>
            <ParentLessonList
              :items="data.home.nextLesson ? [data.home.nextLesson] : []"
              :timezone="timezone"
            />
          </article>
          <article class="edu-card">
            <h2>{{ t('education.attendanceHistory') }}</h2>
            <div class="edu-parent-stats">
              <div>
                <strong>{{ data.home.attendance.present }}</strong>
                {{ t('education.present') }}
              </div>
              <div>
                <strong>{{ data.home.attendance.absent }}</strong>
                {{ t('education.absentStatus') }}
              </div>
              <div>
                <strong>{{ data.home.attendance.total }}</strong>
                {{ t('education.totalLessons') }}
              </div>
            </div>
          </article>
        </div>
        <article class="edu-card">
          <div class="edu-page-title">
            <h2>{{ t('education.resultsHistory') }}</h2>
            <RouterLink
              :to="childSection('/parent/diary')"
              class="edu-link"
            >
              {{ t('education.parentdiary') }}
            </RouterLink>
          </div>
          <ParentLessonList
            :items="data.home.results"
            :timezone="timezone"
            diary
          />
        </article>
        <article class="edu-card">
          <div class="edu-page-title">
            <h2>{{ t('education.parentDeadlines') }}</h2>
            <RouterLink
              :to="childSection('/parent/homework')"
              class="edu-link"
            >
              {{ t('education.homework') }}
            </RouterLink>
          </div>
          <ParentHomeworkList
            :items="data.home.homework"
            :timezone="timezone"
            compact
          />
        </article>
        <article class="edu-card">
          <h2>{{ t('education.notifications') }}</h2>
          <p>{{ t('education.parentUnread', { count: data.home.unreadCount }) }}</p>
          <RouterLink
            :to="childSection('/parent/notifications')"
            class="edu-link"
          >
            {{ t('education.parentnotifications') }}
          </RouterLink>
        </article>
      </template>
      <ParentLessonList
        v-if="data.lessons"
        :items="data.lessons"
        :timezone="timezone"
        :diary="section === 'diary'"
      />
      <template v-if="data.homework">
        <p class="edu-subtle">{{ t('education.parentHomeworkHint') }}</p>
        <ParentHomeworkList
          :items="data.homework"
          :timezone="timezone"
        />
      </template>
      <template v-if="data.progress">
        <article class="edu-card">
          <h2>{{ t('education.programs') }}</h2>
          <EducationState
            v-if="!data.progress.programs.length"
            :empty="t('education.noAssignedPrograms')"
          />
          <div
            v-for="program in data.progress.programs"
            :key="program.assignmentId"
            class="edu-module"
          >
            <strong>{{ program.name }}</strong>
            <div class="edu-progress">
              <progress
                :value="program.completedLessons"
                :max="program.totalLessons || 1"
                :aria-label="program.name"
              ></progress>
              <span>
                {{ program.completedLessons }} / {{ program.totalLessons }} · {{ program.percent }}%
              </span>
            </div>
            <ul class="edu-list">
              <li
                v-for="module in program.modules"
                :key="module.id ?? module.name"
              >
                <strong>{{ module.name }}</strong>
                <p class="edu-subtle">
                  {{ module.completedLessons }} / {{ module.totalLessons }} · {{ module.percent }}%
                </p>
              </li>
            </ul>
          </div>
        </article>
        <div class="edu-parent-grid">
          <article class="edu-card">
            <h2>{{ t('education.attendanceHistory') }}</h2>
            <p>{{ t('education.presentCount', { count: data.progress.attendance.present }) }}</p>
            <p>{{ t('education.absentCount', { count: data.progress.attendance.absent }) }}</p>
          </article>
          <article class="edu-card">
            <h2>{{ t('education.homework') }}</h2>
            <p>{{ t('education.parentHomeworkTotal', { count: data.progress.homework.total }) }}</p>
            <p>
              {{
                t('education.parentHomeworkSubmitted', { count: data.progress.homework.submitted })
              }}
            </p>
            <p>
              {{
                t('education.parentHomeworkReviewed', { count: data.progress.homework.reviewed })
              }}
            </p>
            <p>
              {{
                t('education.parentHomeworkReturned', { count: data.progress.homework.returned })
              }}
            </p>
          </article>
        </div>
        <ActivityProgressPanel
          :progress="data.progress.activityProgress"
          show-achievements
        />
      </template>
      <template v-if="data.notifications">
        <EducationState
          :error="readError"
          @retry="
            resetRead();
            refresh();
          "
        />
        <EducationState
          v-if="!data.notifications.length"
          :empty="t('education.noNotifications')"
        />
        <ul
          v-else
          class="edu-list"
        >
          <li
            v-for="notification in data.notifications"
            :key="notification.id"
            class="edu-list__item"
          >
            <div>
              <strong>{{ notificationLabel(notification) }}</strong>
              <p class="edu-subtle">{{ when(notification.createdAt) }}</p>
            </div>
            <button
              v-if="!notification.readAt"
              type="button"
              class="edu-button"
              :disabled="readBusy"
              @click="readNotification(notification.id)"
            >
              {{ t('education.markRead') }}
            </button>
          </li>
        </ul>
      </template>
      <template v-else-if="section === 'messages'">
        <EducationMessenger
          v-if="workspace.child && workspace.context"
          role="parent"
          :organization-id="workspace.child.organizationId"
          :current-person-id="workspace.context.personId"
          :parent-child="workspace.child"
          :timezone="workspace.context.timezone"
          :initial-thread-id="typeof route.query.thread === 'string' ? route.query.thread : ''"
        />
      </template>
      <div
        v-if="offset || data.nextOffset != null"
        class="edu-form-actions"
      >
        <button
          v-if="offset"
          type="button"
          class="edu-button"
          @click="offset = Math.max(0, offset - 50)"
        >
          {{ t('education.back') }}
        </button>
        <button
          v-if="data.nextOffset != null"
          type="button"
          class="edu-button"
          @click="offset = data.nextOffset ?? 0"
        >
          {{ t('education.loadMore') }}
        </button>
      </div>
    </template>
  </section>
</template>
