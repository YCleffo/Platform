<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import { useEducationWorkspace } from '../workspace';
import { studentSession } from '../student/session';
import { useEducationI18n } from '../i18n';
import { educationApi } from '../api';
import EducationState from '../EducationState.vue';
import {
  assignmentStates,
  submissionStates,
  homeworkApi,
  studentAssignment,
  studentState,
} from './api';
import { useHomeworkResource } from './resources';
const props = defineProps<{ student?: boolean }>();
const workspace = useEducationWorkspace(),
  route = useRoute(),
  router = useRouter();
const { t, locale } = useEducationI18n();
const org = () => (props.student ? studentSession.organizationId : workspace.organizationId);
const queryString = (key: string): string =>
  typeof route.query[key] === 'string' ? (route.query[key] as string) : '';
const search = ref(queryString('search')),
  state = ref(queryString(props.student ? 'state' : 'status')),
  group = ref(queryString('groupId')),
  studentId = ref(queryString('studentId'));
const overdue = ref(queryString('overdue') === 'true'),
  sort = ref(queryString('sort') || 'dueAt');
const groupOffset = ref(0),
  studentOffset = ref(0);
const filters = computed(() => ({
  search: queryString('search'),
  [props.student ? 'state' : 'status']: queryString(props.student ? 'state' : 'status'),
  groupId: queryString('groupId'),
  studentId: queryString('studentId'),
  overdue: queryString('overdue') || undefined,
  sort: queryString('sort') || 'dueAt',
  offset: Math.max(0, Number(queryString('offset')) || 0),
}));
watch(
  () => route.query,
  () => {
    search.value = queryString('search');
    state.value = queryString(props.student ? 'state' : 'status');
    group.value = queryString('groupId');
    studentId.value = queryString('studentId');
    overdue.value = queryString('overdue') === 'true';
    sort.value = queryString('sort') || 'dueAt';
  },
);
const { data, loading, error, refresh } = useHomeworkResource(
  org,
  async (organization, signal) =>
    props.student
      ? homeworkApi.studentList(organization, filters.value, signal)
      : homeworkApi.list(organization, filters.value, signal),
  [filters, () => props.student],
);
const choices = useHomeworkResource(
  org,
  async (organization, signal) => {
    if (props.student) return null;
    const [groups, students] = await Promise.all([
      educationApi.groups(organization, groupOffset.value, signal),
      educationApi.students(organization, studentOffset.value, signal),
    ]);
    return { groups, students };
  },
  [groupOffset, studentOffset, () => props.student],
);
function apply(): void {
  void router.replace({
    query: {
      ...(search.value ? { search: search.value.trim() } : {}),
      ...(state.value ? { [props.student ? 'state' : 'status']: state.value } : {}),
      ...(!props.student && group.value ? { groupId: group.value } : {}),
      ...(!props.student && studentId.value ? { studentId: studentId.value } : {}),
      ...(overdue.value ? { overdue: 'true' } : {}),
      sort: sort.value,
    },
  });
}
function page(offset: number): void {
  void router.replace({ query: { ...route.query, offset } });
}
function date(value: string): string {
  return new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: props.student ? studentSession.timezone : (workspace.header?.timezone ?? 'UTC'),
  }).format(new Date(value));
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ t(student ? 'education.myHomework' : 'education.homework') }}</h1>
      <RouterLink
        v-if="!student"
        :to="{
          path: '/homework/new',
          query: { groupId: group || undefined, studentId: studentId || undefined },
        }"
        class="edu-button edu-button--primary"
      >
        {{ t('education.createHomework') }}
      </RouterLink>
      <RouterLink
        v-else
        to="/student/activities"
        class="edu-link"
      >
        {{ t('education.activities') }}
      </RouterLink>
    </header>
    <form
      class="edu-card edu-homework-filters"
      @submit.prevent="apply"
    >
      <AppInput
        id="homework-search"
        v-model="search"
        :label="t('education.homeworkSearch')"
        type="search"
        maxlength="240"
      />
      <AppDropdown
        v-model="state"
        :label="t('education.homeworkState')"
        :options="[
          { value: '', label: t('education.homeworkAll') },
          ...(student ? submissionStates : assignmentStates).map((value) => ({
            value,
            label: t(`education.homework${value}`),
          })),
        ]"
      />
      <AppDropdown
        v-model="sort"
        :label="t('education.position')"
        :options="[
          { value: 'dueAt', label: t('education.homeworkSortDue') },
          { value: 'newest', label: t('education.homeworkSortNewest') },
        ]"
      />
      <template v-if="!student && choices.data.value">
        <AppDropdown
          v-model="group"
          clearable
          searchable
          :label="t('education.group')"
          :options="
            choices.data.value.groups.items.map((item) => ({ value: item.id, label: item.name }))
          "
        />
        <AppDropdown
          v-model="studentId"
          clearable
          searchable
          :label="t('education.student')"
          :options="
            choices.data.value.students.items.map((item) => ({
              value: item.id,
              label: item.name ?? item.id,
            }))
          "
        />
      </template>
      <label class="edu-check">
        <input
          v-model="overdue"
          type="checkbox"
        />
        {{ t('education.homeworkOverdue') }}
      </label>
      <AppButton
        type="submit"
        :disabled="loading"
      >
        {{ t('education.apply') }}
      </AppButton>
      <template v-if="!student && choices.data.value">
        <div class="edu-form-actions">
          <AppButton
            v-if="groupOffset"
            variant="secondary"
            @click="groupOffset = Math.max(0, groupOffset - 50)"
          >
            {{ t('education.back') }} · {{ t('education.groups') }}
          </AppButton>
          <AppButton
            v-if="choices.data.value.groups.nextOffset !== null"
            variant="secondary"
            @click="groupOffset = choices.data.value!.groups.nextOffset!"
          >
            {{ t('education.loadMore') }} · {{ t('education.groups') }}
          </AppButton>
          <AppButton
            v-if="studentOffset"
            variant="secondary"
            @click="studentOffset = Math.max(0, studentOffset - 50)"
          >
            {{ t('education.back') }} · {{ t('education.students') }}
          </AppButton>
          <AppButton
            v-if="choices.data.value.students.nextOffset !== null"
            variant="secondary"
            @click="studentOffset = choices.data.value!.students.nextOffset!"
          >
            {{ t('education.loadMore') }} · {{ t('education.students') }}
          </AppButton>
        </div>
      </template>
    </form>
    <EducationState
      :loading="loading"
      :error="error"
      :empty="data && !data.items.length ? t('education.noHomework') : undefined"
      @retry="refresh"
    />
    <EducationState
      v-if="choices.error.value"
      :error="choices.error.value"
      @retry="choices.refresh"
    />
    <ul
      v-if="data"
      class="edu-list"
    >
      <li
        v-for="row in data.items"
        :key="studentAssignment(row).id"
        class="edu-list__item edu-homework-row"
      >
        <div>
          <RouterLink
            :to="`${student ? '/student' : ''}/homework/${studentAssignment(row).id}`"
            class="edu-link"
          >
            <strong>{{ studentAssignment(row).title }}</strong>
          </RouterLink>
          <p class="edu-subtle">{{ studentAssignment(row).description }}</p>
        </div>
        <dl
          v-if="!student"
          class="edu-homework-counts"
        >
          <div
            v-for="key in ['assigned', 'submitted', 'pendingReview', 'returned'] as const"
            :key="key"
          >
            <dt>{{ t(`education.homework${key[0]!.toUpperCase()}${key.slice(1)}`) }}</dt>
            <dd>{{ studentAssignment(row)[key] ?? 0 }}</dd>
          </div>
        </dl>
        <div class="edu-homework-row__meta">
          <span class="edu-status">
            {{
              t(`education.homework${student ? studentState(row) : studentAssignment(row).status}`)
            }}
          </span>
          <span>
            {{
              studentAssignment(row).dueAt
                ? date(studentAssignment(row).dueAt!)
                : t('education.homeworkNoDue')
            }}
          </span>
          <strong
            v-if="row.overdue"
            class="edu-error"
          >
            {{ t('education.homeworkOverdue') }}
          </strong>
        </div>
      </li>
    </ul>
    <nav
      v-if="data"
      class="edu-form-actions"
      :aria-label="t('education.homework')"
    >
      <AppButton
        v-if="data.offset"
        variant="secondary"
        @click="page(Math.max(0, data.offset - data.limit))"
      >
        {{ t('education.back') }}
      </AppButton>
      <AppButton
        v-if="data.nextOffset !== null"
        variant="secondary"
        @click="page(data.nextOffset!)"
      >
        {{ t('education.loadMore') }}
      </AppButton>
    </nav>
  </section>
</template>
