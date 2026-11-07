<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { educationApi } from './api';
import { useEducationResource } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
const route = useRoute();
const { t, students } = useEducationI18n();
const groups = computed(() => route.query.tab === 'groups');
const offset = ref(0);
const { data, loading, error, refresh } = useEducationResource(
  async (org, signal) => ({
    students: groups.value ? null : await educationApi.students(org, offset.value, signal),
    groups: groups.value ? await educationApi.groups(org, offset.value, signal) : null,
  }),
  [groups, offset],
);
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ t('education.people') }}</h1>
      <RouterLink
        to="/devices"
        class="edu-button"
      >
        {{ t('education.computers') }}
      </RouterLink>
      <RouterLink
        :to="groups ? '/groups/new' : '/students/new'"
        class="edu-button edu-button--primary"
      >
        {{ t(groups ? 'education.createGroup' : 'education.addStudent') }}
      </RouterLink>
    </header>
    <nav
      class="edu-toolbar"
      :aria-label="t('education.people')"
    >
      <RouterLink
        to="/people"
        class="edu-button"
        :aria-current="!groups ? 'page' : undefined"
        @click="offset = 0"
      >
        {{ t('education.students') }}
      </RouterLink>
      <RouterLink
        to="/people?tab=groups"
        class="edu-button"
        :aria-current="groups ? 'page' : undefined"
        @click="offset = 0"
      >
        {{ t('education.groups') }}
      </RouterLink>
    </nav>
    <EducationState
      :loading="loading"
      :error="error"
      :empty="
        data && !(data.groups?.items.length || data.students?.items.length)
          ? t(groups ? 'education.noGroups' : 'education.noPeople')
          : undefined
      "
      @retry="refresh"
    />
    <ul
      v-if="data?.groups"
      class="edu-list"
    >
      <li
        v-for="item in data.groups.items"
        :key="item.id"
        class="edu-list__item"
      >
        <RouterLink
          :to="`/groups/${item.id}`"
          class="edu-list__main"
        >
          <strong>{{ item.name }}</strong>
          <p class="edu-subtle">
            {{ students(item.studentCount ?? 0) }}
            <span
              v-if="item.archivedAt"
              class="edu-status edu-status--cancelled"
            >
              {{ t('education.archived') }}
            </span>
          </p>
        </RouterLink>
        <div class="edu-form-actions">
          <RouterLink
            :to="`/groups/${item.id}`"
            class="edu-button"
          >
            {{ t('education.openGroup') }}
          </RouterLink>
          <RouterLink
            v-if="!item.archivedAt"
            :to="`/students/new?group=${item.id}`"
            class="edu-button"
          >
            {{ t('education.addStudent') }}
          </RouterLink>
        </div>
      </li>
    </ul>
    <ul
      v-if="data?.students"
      class="edu-list"
    >
      <li
        v-for="item in data.students.items"
        :key="item.id"
        class="edu-list__item"
      >
        <RouterLink
          :to="`/students/${item.id}`"
          class="edu-list__main"
        >
          <strong>{{ item.name }}</strong>
        </RouterLink>
        <RouterLink
          :to="`/students/${item.id}`"
          class="edu-button"
        >
          {{ t('education.openStudentCard') }}
        </RouterLink>
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
          (data?.groups ?? data?.students)?.nextOffset !== null &&
          (data?.groups ?? data?.students)?.nextOffset !== undefined
        "
        type="button"
        class="edu-button"
        @click="offset = (data?.groups ?? data?.students)?.nextOffset ?? 0"
      >
        {{ t('education.loadMore') }}
      </button>
    </div>
  </section>
</template>
