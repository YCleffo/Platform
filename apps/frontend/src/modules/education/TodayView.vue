<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import {
  Bell,
  Calendar,
  ChevronRight,
  Clock,
  People,
  Book,
  Plus,
  UserPlus,
  Upload,
  Message,
} from '@/shared/ui/icons';
import { calendarWeek, formatCalendarDate, formatZonedTime } from '@/shared/formatting/calendar';
import { workspaceApi } from '@/shared/api/workspaceApi';
import { useEducationWorkspace } from './workspace';
import { educationApi, type Lesson } from './api';
import { useEducationI18n } from './i18n';
import { useEducationResource } from './resources';
import { lessonDisplayStatus } from './status';
import EducationAvatar from './EducationAvatar.vue';
import EducationState from './EducationState.vue';

interface DashboardGroupItem {
  id: string;
  name: string;
  studentCount: number;
}

interface DashboardPendingReview {
  submissionId: string;
  assignmentId: string;
  assignmentTitle: string;
  state: string;
  updatedAt: string;
}

interface DashboardManualGrading {
  attemptId: string;
  activityId: string;
  activityTitle: string;
  pluginCode: string;
  studentName: string;
  submittedAt: string | null;
}

interface DashboardNotificationItem {
  id: string;
  title: string;
  body?: string | null;
  resourcePath?: string | null;
  createdAt: string;
  readAt?: string | null;
}

interface DashboardUpcomingLesson {
  id: string;
  title: string;
  startsAt: string;
  endsAt: string;
  groupId?: string;
  groupName: string;
}

const workspace = useEducationWorkspace();
const { t, locale, students } = useEducationI18n();
const selected = ref(workspace.header!.date);
const week = computed(() => calendarWeek(selected.value));
const weekId = useId();
watch(
  () => workspace.header?.date,
  (date, before) => {
    if (date && selected.value === before) selected.value = date;
  },
);
const resource = useEducationResource(
  async (org, signal) => {
    const days = calendarWeek(selected.value);
    const [schedule, timeline, next, attention, dashboard] = await Promise.all([
      educationApi.schedule(org, days[0]!, days[6]!, signal),
      educationApi.timeline(org, selected.value, signal),
      educationApi.next(org, signal),
      educationApi.attention(org, signal),
      workspaceApi.getDashboard(org).catch(() => null),
    ]);
    return { schedule, timeline, next, attention, dashboard };
  },
  [selected, () => workspace.header?.now.slice(0, 16)],
);
const { data, loading, error, refresh } = resource;

const educationWidgetData = computed(() => {
  const widget = data.value?.dashboard?.widgets.find((w) => w.id === 'education.overview') as
    | {
        data?: {
          upcomingLessons?: DashboardUpcomingLesson[];
          currentGroups?: DashboardGroupItem[];
          pendingReviews?: DashboardPendingReview[];
          pendingManualGrading?: DashboardManualGrading[];
        };
      }
    | undefined;
  return widget?.data ?? {};
});

const currentGroups = computed(() => educationWidgetData.value.currentGroups ?? []);
const pendingReviews = computed(() => educationWidgetData.value.pendingReviews ?? []);
const pendingManualGrading = computed(() => educationWidgetData.value.pendingManualGrading ?? []);
const upcomingLessons = computed(() => educationWidgetData.value.upcomingLessons ?? []);
const recentNotifications = computed<DashboardNotificationItem[]>(() => {
  const widget = data.value?.dashboard?.widgets.find((w) => w.id === 'notifications.unread') as
    | { data?: { items?: DashboardNotificationItem[] } }
    | undefined;
  return widget?.data?.items ?? [];
});

const quick = [
  { key: 'currentLesson', path: '/lessons/current', icon: ChevronRight, tone: 'primary' },
  { key: 'createLesson', path: '/lessons/new', icon: Plus, tone: 'success' },
  { key: 'createGroup', path: '/groups/new', icon: People, tone: 'warning' },
  { key: 'addStudent', path: '/students/new', icon: UserPlus, tone: 'info' },
  { key: 'createHomework', path: '/homework/new', icon: Book, tone: 'primary' },
  { key: 'assessments', path: '/assessments', icon: Book, tone: 'info' },
  { key: 'uploadMaterials', path: '/materials/upload', icon: Upload, tone: 'warning' },
  { key: 'writeMessage', path: '/messages/new', icon: Message, tone: 'primary' },
];
function time(value: string): string {
  return formatZonedTime(value, locale.value, workspace.header!.timezone);
}
function status(lesson: Lesson): string {
  return lessonDisplayStatus(lesson, workspace.header!.now);
}
const dateLine = computed(() =>
  formatCalendarDate(workspace.header!.date, locale.value, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }),
);
</script>
<template>
  <section class="edu-today">
    <header class="edu-greeting">
      <div>
        <h1>
          {{ t(`education.${workspace.header!.greeting}`)
          }}{{ workspace.header!.displayName ? `, ${workspace.header!.displayName}` : '' }}!
        </h1>
        <p class="edu-date">{{ dateLine }}</p>
      </div>
      <RouterLink
        to="/notifications"
        class="edu-icon-button"
        :aria-label="`${t('education.notifications')}: ${workspace.header!.unreadCount}`"
      >
        <Bell
          :size="22"
          aria-hidden="true"
        />
        <span
          v-if="workspace.header!.unreadCount"
          class="edu-dot"
          aria-hidden="true"
        >
          {{ Math.min(workspace.header!.unreadCount, 99) }}
        </span>
      </RouterLink>
      <EducationAvatar />
    </header>
    <div
      class="edu-week"
      role="group"
      :aria-label="t('education.week')"
    >
      <template
        v-for="day in week"
        :key="day"
      >
        <button
          type="button"
          class="edu-week__day"
          :class="{ 'edu-week__day--selected': selected === day }"
          :aria-pressed="selected === day"
          :aria-describedby="`${weekId}-${day}`"
          @click="selected = day"
        >
          <span>{{ formatCalendarDate(day, locale, { weekday: 'short' }) }}</span>
          <strong>{{ Number(day.slice(8)) }}</strong>
        </button>
        <span
          :id="`${weekId}-${day}`"
          hidden
        >
          {{ formatCalendarDate(day, locale, { dateStyle: 'full' }) }}
        </span>
      </template>
      <RouterLink
        :to="`/calendar?date=${selected}`"
        class="edu-icon-button edu-week__calendar"
        :aria-label="t('education.calendar')"
      >
        <Calendar
          :size="19"
          aria-hidden="true"
        />
      </RouterLink>
    </div>
    <EducationState
      v-if="(loading && !data) || error"
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data">
      <article
        v-if="!data.next && !data.schedule.lessons.length"
        class="edu-card edu-onboarding"
      >
        <h2>{{ t('education.getStarted') }}</h2>
        <p class="edu-subtle">{{ t('education.onboardingHint') }}</p>
        <ol>
          <li>
            <RouterLink
              to="/groups/new"
              class="edu-link"
            >
              {{ t('education.createGroup') }}
            </RouterLink>
          </li>
          <li>
            <RouterLink
              to="/students/new"
              class="edu-link"
            >
              {{ t('education.addStudent') }}
            </RouterLink>
          </li>
          <li>
            <RouterLink
              to="/programs"
              class="edu-link"
            >
              {{ t('education.programs') }}
            </RouterLink>
          </li>
          <li>
            <RouterLink
              to="/lessons/new"
              class="edu-link"
            >
              {{ t('education.createLesson') }}
            </RouterLink>
          </li>
        </ol>
      </article>
      <p
        v-if="loading"
        class="edu-subtle"
        role="status"
      >
        {{ t('education.refreshing') }}
      </p>
      <div class="edu-today-columns">
        <div class="edu-today-primary">
          <article
            v-if="data.next"
            class="edu-next"
          >
            <div class="edu-next__top">
              <span class="edu-tag">{{ t('education.nextLesson') }}</span>
              <span class="edu-next__when">
                <Clock
                  :size="13"
                  aria-hidden="true"
                />
                {{ time(data.next.startsAt) }}
              </span>
            </div>
            <RouterLink
              :to="`/lessons/${data.next.id}`"
              class="edu-next__body"
            >
              <span
                class="edu-monogram"
                aria-hidden="true"
              >
                {{ data.next.title.slice(0, 2) }}
              </span>
              <div>
                <p class="edu-caption">
                  {{ time(data.next.startsAt) }} – {{ time(data.next.endsAt) }}
                </p>
                <h2>{{ data.next.title }}</h2>
                <p class="edu-next__meta">
                  {{ data.next.groupName }} · {{ students(data.next.studentCount) }}
                  <template v-if="data.next.location">· {{ data.next.location }}</template>
                </p>
              </div>
              <ChevronRight
                :size="18"
                aria-hidden="true"
              />
            </RouterLink>
            <div class="edu-next__actions">
              <RouterLink
                :to="`/lessons/${data.next.id}`"
                class="edu-button edu-button--primary"
              >
                <ChevronRight
                  :size="13"
                  aria-hidden="true"
                />
                {{ t('education.openLesson') }}
              </RouterLink>
              <RouterLink
                :to="`/lessons/${data.next.id}/participants`"
                class="edu-button"
              >
                <People
                  :size="14"
                  aria-hidden="true"
                />
                {{ t('education.participants') }}
              </RouterLink>
              <RouterLink
                :to="`/lessons/${data.next.id}/materials`"
                class="edu-button"
              >
                <Book
                  :size="14"
                  aria-hidden="true"
                />
                {{ t('education.materials') }}
              </RouterLink>
            </div>
          </article>
          <article
            v-else
            class="edu-card edu-empty-next"
          >
            <h2>{{ t('education.noNext') }}</h2>
            <RouterLink
              to="/lessons/new"
              class="edu-link"
            >
              {{ t('education.createLesson') }}
            </RouterLink>
          </article>
          <section class="edu-timeline-section">
            <div class="edu-section-title">
              <h2>
                {{
                  selected === workspace.header!.date
                    ? t('education.today')
                    : formatCalendarDate(selected, locale, { day: 'numeric', month: 'long' })
                }}
              </h2>
              <RouterLink
                :to="`/calendar?date=${selected}`"
                class="edu-link"
              >
                {{ t('education.allEvents') }}
                <ChevronRight
                  :size="14"
                  aria-hidden="true"
                />
              </RouterLink>
            </div>
            <EducationState
              v-if="!data.timeline.items.length"
              :empty="t('education.emptyDay')"
            />
            <ol
              v-else
              class="edu-timeline"
            >
              <li
                v-for="item in data.timeline.items"
                :key="item.id"
                class="edu-timeline__item"
                :class="`edu-timeline__item--${item.kind === 'lesson' ? status(item) : item.kind}`"
              >
                <time :datetime="item.startsAt">{{ time(item.startsAt) }}</time>
                <span
                  class="edu-timeline__point"
                  aria-hidden="true"
                ></span>
                <RouterLink
                  :to="
                    item.kind === 'lesson'
                      ? `/lessons/${item.id}`
                      : `/calendar?date=${selected}#event-${item.id}`
                  "
                  class="edu-timeline__card"
                >
                  <div>
                    <strong>{{ item.title }}</strong>
                    <p>
                      {{
                        item.kind === 'lesson'
                          ? `${item.groupName} · ${students(item.studentCount)}`
                          : item.location || t(`education.${item.kind}`)
                      }}
                    </p>
                  </div>
                  <span
                    v-if="item.kind === 'lesson'"
                    class="edu-status"
                    :class="`edu-status--${status(item)}`"
                  >
                    {{ t(`education.${status(item)}`) }}
                  </span>
                  <ChevronRight
                    v-else
                    :size="18"
                    aria-hidden="true"
                  />
                </RouterLink>
              </li>
            </ol>
          </section>
          <section
            v-if="upcomingLessons.length"
            class="edu-timeline-section"
          >
            <div class="edu-section-title">
              <h2>{{ t('education.upcomingLessons') }}</h2>
              <RouterLink
                to="/calendar"
                class="edu-link"
              >
                {{ t('education.calendar') }}
                <ChevronRight
                  :size="14"
                  aria-hidden="true"
                />
              </RouterLink>
            </div>
            <ul class="edu-list">
              <li
                v-for="lesson in upcomingLessons"
                :key="lesson.id"
              >
                <RouterLink
                  :to="`/lessons/${lesson.id}`"
                  class="edu-list__item"
                >
                  <div class="edu-list__main">
                    <strong>{{ lesson.title }}</strong>
                    <p class="edu-subtle">
                      {{ lesson.groupName }} · {{ time(lesson.startsAt) }} –
                      {{ time(lesson.endsAt) }}
                    </p>
                  </div>
                  <ChevronRight
                    :size="18"
                    aria-hidden="true"
                  />
                </RouterLink>
              </li>
            </ul>
          </section>
          <section class="edu-timeline-section">
            <div class="edu-section-title">
              <h2>{{ t('education.currentGroups') }}</h2>
              <RouterLink
                to="/people?tab=groups"
                class="edu-link"
              >
                {{ t('education.groups') }}
                <ChevronRight
                  :size="14"
                  aria-hidden="true"
                />
              </RouterLink>
            </div>
            <EducationState
              v-if="!currentGroups.length"
              :empty="t('education.noGroups')"
            />
            <ul
              v-else
              class="edu-list"
            >
              <li
                v-for="group in currentGroups"
                :key="group.id"
              >
                <RouterLink
                  :to="`/groups/${group.id}`"
                  class="edu-list__item"
                >
                  <div class="edu-list__main">
                    <strong>{{ group.name }}</strong>
                    <p class="edu-subtle">{{ students(group.studentCount) }}</p>
                  </div>
                  <ChevronRight
                    :size="18"
                    aria-hidden="true"
                  />
                </RouterLink>
              </li>
            </ul>
          </section>
        </div>
        <div class="edu-today-secondary">
          <section class="edu-quick-section">
            <h2>{{ t('education.quickActions') }}</h2>
            <div class="edu-quick">
              <RouterLink
                v-for="item in quick"
                :key="item.path"
                :to="item.path"
                class="edu-quick__action"
                :data-action="item.key"
              >
                <span
                  class="edu-quick__icon"
                  :class="`edu-quick__icon--${item.tone}`"
                >
                  <component
                    :is="item.icon"
                    :size="25"
                    aria-hidden="true"
                  />
                </span>
                <span>{{ t(`education.${item.key}`) }}</span>
              </RouterLink>
            </div>
          </section>
          <section class="edu-timeline-section">
            <div class="edu-section-title">
              <h2>{{ t('education.pendingHomeworkReviews') }}</h2>
              <RouterLink
                to="/homework"
                class="edu-link"
              >
                {{ t('education.homework') }}
                <ChevronRight
                  :size="14"
                  aria-hidden="true"
                />
              </RouterLink>
            </div>
            <EducationState
              v-if="!pendingReviews.length"
              :empty="t('education.noPendingReviews')"
            />
            <ul
              v-else
              class="edu-list"
            >
              <li
                v-for="item in pendingReviews"
                :key="item.submissionId"
              >
                <RouterLink
                  :to="`/homework/submissions/${item.submissionId}`"
                  class="edu-list__item"
                >
                  <div class="edu-list__main">
                    <strong>{{ item.assignmentTitle }}</strong>
                    <p class="edu-subtle">{{ t(`education.homework${item.state}`) }}</p>
                  </div>
                  <ChevronRight
                    :size="18"
                    aria-hidden="true"
                  />
                </RouterLink>
              </li>
            </ul>
          </section>
          <section class="edu-timeline-section">
            <div class="edu-section-title">
              <h2>{{ t('education.pendingManualGrading') }}</h2>
              <RouterLink
                to="/assessments"
                class="edu-link"
              >
                {{ t('education.assessments') }}
                <ChevronRight
                  :size="14"
                  aria-hidden="true"
                />
              </RouterLink>
            </div>
            <EducationState
              v-if="!pendingManualGrading.length"
              :empty="t('education.noPendingManualGrading')"
            />
            <ul
              v-else
              class="edu-list"
            >
              <li
                v-for="item in pendingManualGrading"
                :key="item.attemptId"
              >
                <RouterLink
                  :to="`/assessments/${item.activityId}/results/${item.attemptId}`"
                  class="edu-list__item"
                >
                  <div class="edu-list__main">
                    <strong>{{ item.activityTitle }}</strong>
                    <p
                      v-if="item.studentName"
                      class="edu-subtle"
                    >
                      {{ item.studentName }}
                    </p>
                  </div>
                  <ChevronRight
                    :size="18"
                    aria-hidden="true"
                  />
                </RouterLink>
              </li>
            </ul>
          </section>
          <section class="edu-timeline-section">
            <div class="edu-section-title">
              <h2>{{ t('education.recentNotifications') }}</h2>
              <RouterLink
                to="/notifications"
                class="edu-link"
              >
                {{ t('education.all') }}
                <ChevronRight
                  :size="14"
                  aria-hidden="true"
                />
              </RouterLink>
            </div>
            <EducationState
              v-if="!recentNotifications.length"
              :empty="t('education.noNotifications')"
            />
            <ul
              v-else
              class="edu-list"
            >
              <li
                v-for="item in recentNotifications"
                :key="item.id"
              >
                <RouterLink
                  :to="item.resourcePath || '/notifications'"
                  class="edu-list__item"
                >
                  <div class="edu-list__main">
                    <strong>{{ item.title }}</strong>
                    <p
                      v-if="item.body"
                      class="edu-subtle"
                    >
                      {{ item.body }}
                    </p>
                  </div>
                  <span
                    v-if="!item.readAt"
                    class="edu-status edu-status--now"
                  >
                    {{ t('education.unread') }}
                  </span>
                  <ChevronRight
                    v-else
                    :size="18"
                    aria-hidden="true"
                  />
                </RouterLink>
              </li>
            </ul>
          </section>
          <section
            v-if="data.attention.items.length"
            class="edu-attention"
          >
            <div class="edu-section-title">
              <h2>{{ t('education.attention') }}</h2>
              <RouterLink
                to="/attention"
                class="edu-link"
              >
                {{ t('education.all') }}
                <ChevronRight
                  :size="14"
                  aria-hidden="true"
                />
              </RouterLink>
            </div>
            <RouterLink
              v-for="item in data.attention.items"
              :key="item.id"
              :to="`/lessons/${item.id}/participants`"
              class="edu-attention__item"
            >
              <span aria-hidden="true">!</span>
              {{ t('education.absent', { count: item.absentCount }) }}
              <ChevronRight
                :size="17"
                aria-hidden="true"
              />
            </RouterLink>
          </section>
        </div>
      </div>
    </template>
  </section>
</template>
