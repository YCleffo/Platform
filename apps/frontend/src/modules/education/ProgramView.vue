<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import { educationApi, type CurriculumModule, type CurriculumLesson } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationResource, useEducationMutation, useEducationError } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
const workspace = useEducationWorkspace();
const route = useRoute();
const { t } = useEducationI18n();
const issue = useEducationError();
const id = computed(() => String(route.params.id));
const moduleId = computed(() => String(route.params.moduleId ?? ''));
const { data, loading, error, refresh } = useEducationResource(
  (org, signal) => educationApi.program(org, id.value, signal),
  [id],
);
const name = ref('');
const description = ref('');
const moduleName = ref('');
const moduleDescription = ref('');
const editing = ref(false);
const moduleDrafts = reactive(
  new Map<string, { name: string; description: string; position: number }>(),
);
const lessonDrafts = reactive(
  new Map<string, { title: string; description: string; position: number }>(),
);
const lessonNames = reactive<Record<string, string>>({});
watch(data, (value) => {
  if (!value) return;
  name.value = value.name;
  description.value = value.description;
  for (const m of value.modules ?? []) {
    moduleDrafts.set(m.id, { name: m.name, description: m.description, position: m.position });
    for (const l of m.lessons)
      lessonDrafts.set(l.id, { title: l.title, description: l.description, position: l.position });
  }
});
const modules = computed(() =>
  (data.value?.modules ?? [])
    .filter((m) => !moduleId.value || m.id === moduleId.value)
    .sort((a, b) => a.position - b.position),
);
const mutation = useEducationMutation();
const { busy, error: actionError, uncertain } = mutation;
async function save(): Promise<void> {
  await mutation.run(
    () =>
      educationApi.updateProgram(workspace.organizationId, id.value, {
        name: name.value,
        description: description.value,
      }),
    async () => {
      editing.value = false;
      await refresh();
    },
  );
}
async function archive(): Promise<void> {
  await mutation.run(
    () =>
      educationApi.updateProgram(workspace.organizationId, id.value, {
        name: name.value,
        description: description.value,
        archived: true,
      }),
    () => refresh(),
  );
}
async function addModule(): Promise<void> {
  await mutation.run(
    () =>
      educationApi.createCurriculumModule(workspace.organizationId, id.value, {
        name: moduleName.value,
        description: moduleDescription.value,
        position: Math.max(0, ...(data.value?.modules ?? []).map((m) => m.position)) + 1,
      }),
    async () => {
      moduleName.value = '';
      moduleDescription.value = '';
      await refresh();
    },
  );
}
async function saveModule(m: CurriculumModule, archived = false): Promise<void> {
  const draft = moduleDrafts.get(m.id);
  if (draft)
    await mutation.run(
      () =>
        educationApi.updateCurriculumModule(workspace.organizationId, m.id, { ...draft, archived }),
      () => refresh(),
    );
}
async function addLesson(m: CurriculumModule): Promise<void> {
  await mutation.run(
    () =>
      educationApi.createCurriculumLesson(workspace.organizationId, m.id, {
        title: lessonNames[m.id] ?? '',
        description: '',
        position: Math.max(0, ...m.lessons.map((l) => l.position)) + 1,
      }),
    async () => {
      lessonNames[m.id] = '';
      await refresh();
    },
  );
}
async function saveLesson(l: CurriculumLesson, archived = false): Promise<void> {
  const draft = lessonDrafts.get(l.id);
  if (draft)
    await mutation.run(
      () =>
        educationApi.updateCurriculumLesson(workspace.organizationId, l.id, { ...draft, archived }),
      () => refresh(),
    );
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ data?.name ?? t('education.programs') }}</h1>
      <RouterLink
        to="/programs"
        class="edu-link"
      >
        {{ t('education.programs') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading && !data"
      :error="error"
      @retry="refresh"
    />
    <p
      v-if="actionError"
      class="edu-error"
      role="alert"
    >
      {{ uncertain ? t('education.uncertain') : issue(actionError) }}
    </p>
    <template v-if="data">
      <p class="edu-subtle">{{ t('education.catalogHistoryHint') }}</p>
      <p
        v-if="data.archivedAt"
        class="edu-state"
      >
        {{ t('education.programArchivedHint') }}
      </p>
      <article class="edu-card">
        <p>{{ data.description }}</p>
        <div
          v-if="!data.archivedAt"
          class="edu-form-actions"
        >
          <button
            type="button"
            class="edu-button"
            :aria-expanded="editing"
            @click="editing = !editing"
          >
            {{ t('education.edit') }}
          </button>
          <button
            type="button"
            class="edu-button"
            :disabled="busy || uncertain"
            @click="archive"
          >
            {{ t('education.archive') }}
          </button>
        </div>
        <form
          v-if="editing"
          class="edu-form"
          @submit.prevent="save"
        >
          <AppInput
            id="edit-program-name"
            v-model="name"
            :label="t('education.name')"
            :maxlength="160"
            required
          />
          <label for="edit-program-description">
            {{ t('education.description') }}
            <textarea
              id="edit-program-description"
              v-model="description"
              maxlength="5000"
            ></textarea>
          </label>
          <button
            class="edu-button edu-button--primary"
            :disabled="busy || uncertain"
          >
            {{ t('education.save') }}
          </button>
        </form>
      </article>
      <div class="edu-section-title">
        <h2>{{ t('education.curriculumModules') }}</h2>
        <RouterLink
          v-if="moduleId"
          :to="`/programs/${id}`"
          class="edu-link"
        >
          {{ t('education.allModules') }}
        </RouterLink>
      </div>
      <article
        v-for="m in modules"
        :id="`module-${m.id}`"
        :key="m.id"
        class="edu-card edu-module"
      >
        <header class="edu-section-title">
          <h2>
            <RouterLink
              class="edu-link"
              :to="`/programs/${id}/modules/${m.id}`"
            >
              {{ m.position }}. {{ m.name }}
            </RouterLink>
          </h2>
          <span
            v-if="m.archivedAt"
            class="edu-status"
          >
            {{ t('education.archived') }}
          </span>
        </header>
        <p class="edu-subtle">{{ m.description }}</p>
        <details v-if="!data.archivedAt && !m.archivedAt">
          <summary>{{ t('education.edit') }}</summary>
          <form
            v-if="moduleDrafts.get(m.id)"
            class="edu-form"
            @submit.prevent="saveModule(m)"
          >
            <AppInput
              :id="`module-name-${m.id}`"
              v-model="moduleDrafts.get(m.id)!.name"
              :label="t('education.name')"
              required
              :maxlength="160"
            />
            <AppInput
              :id="`module-description-${m.id}`"
              v-model="moduleDrafts.get(m.id)!.description"
              :label="t('education.description')"
              :maxlength="5000"
            />
            <label :for="`module-position-${m.id}`">
              {{ t('education.position') }}
              <input
                :id="`module-position-${m.id}`"
                v-model.number="moduleDrafts.get(m.id)!.position"
                type="number"
                min="0"
                required
              />
            </label>
            <div class="edu-form-actions">
              <button
                class="edu-button"
                :disabled="busy || uncertain"
              >
                {{ t('education.save') }}
              </button>
              <button
                type="button"
                class="edu-button"
                :disabled="busy || uncertain"
                @click="saveModule(m, true)"
              >
                {{ t('education.archive') }}
              </button>
            </div>
          </form>
        </details>
        <ol class="edu-module__lessons">
          <li
            v-for="lesson in [...m.lessons].sort((a, b) => a.position - b.position)"
            :key="lesson.id"
          >
            <div>
              <strong>{{ lesson.position }}. {{ lesson.title }}</strong>
              <p class="edu-subtle">{{ lesson.description }}</p>
              <span
                v-if="lesson.archivedAt"
                class="edu-status"
              >
                {{ t('education.archived') }}
              </span>
            </div>
            <details v-if="!data.archivedAt && !m.archivedAt && !lesson.archivedAt">
              <summary>{{ t('education.edit') }}</summary>
              <form
                v-if="lessonDrafts.get(lesson.id)"
                class="edu-form"
                @submit.prevent="saveLesson(lesson)"
              >
                <AppInput
                  :id="`lesson-title-${lesson.id}`"
                  v-model="lessonDrafts.get(lesson.id)!.title"
                  :label="t('education.name')"
                  required
                  :maxlength="160"
                />
                <AppInput
                  :id="`lesson-description-${lesson.id}`"
                  v-model="lessonDrafts.get(lesson.id)!.description"
                  :label="t('education.description')"
                  :maxlength="5000"
                />
                <label :for="`lesson-position-${lesson.id}`">
                  {{ t('education.position') }}
                  <input
                    :id="`lesson-position-${lesson.id}`"
                    v-model.number="lessonDrafts.get(lesson.id)!.position"
                    type="number"
                    min="0"
                    required
                  />
                </label>
                <div class="edu-form-actions">
                  <button
                    class="edu-button"
                    :disabled="busy || uncertain"
                  >
                    {{ t('education.save') }}
                  </button>
                  <button
                    type="button"
                    class="edu-button"
                    :disabled="busy || uncertain"
                    @click="saveLesson(lesson, true)"
                  >
                    {{ t('education.archive') }}
                  </button>
                </div>
              </form>
            </details>
          </li>
        </ol>
        <form
          v-if="!data.archivedAt && !m.archivedAt"
          class="edu-form"
          @submit.prevent="addLesson(m)"
        >
          <AppInput
            :id="`new-lesson-${m.id}`"
            v-model="lessonNames[m.id]"
            :label="t('education.addCurriculumLesson')"
            required
            :maxlength="160"
          />
          <button
            class="edu-button"
            :disabled="busy || uncertain"
          >
            {{ t('education.addCurriculumLesson') }}
          </button>
        </form>
      </article>
      <form
        v-if="!data.archivedAt && !moduleId"
        class="edu-form edu-card edu-module"
        @submit.prevent="addModule"
      >
        <h2>{{ t('education.addModule') }}</h2>
        <AppInput
          id="new-module-name"
          v-model="moduleName"
          :label="t('education.name')"
          required
          :maxlength="160"
        />
        <AppInput
          id="new-module-description"
          v-model="moduleDescription"
          :label="t('education.description')"
          :maxlength="5000"
        />
        <button
          class="edu-button edu-button--primary"
          :disabled="busy || uncertain"
        >
          {{ t('education.addModule') }}
        </button>
      </form>
    </template>
  </section>
</template>
