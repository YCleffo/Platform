<script setup lang="ts">
import { ref } from 'vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import { educationApi } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationResource, useEducationMutation, useEducationError } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
import { useRouter } from 'vue-router';
const workspace = useEducationWorkspace();
const router = useRouter();
const { t } = useEducationI18n();
const issue = useEducationError();
const offset = ref(0);
const name = ref('');
const description = ref('');
const creating = ref(false);
const { data, loading, error, refresh } = useEducationResource(
  (org, signal) => educationApi.programs(org, offset.value, signal),
  [offset],
);
const mutation = useEducationMutation();
const { busy, error: actionError, uncertain } = mutation;
async function create(): Promise<void> {
  await mutation.run(
    () =>
      educationApi.createProgram(workspace.organizationId, {
        name: name.value,
        description: description.value,
      }),
    (result) => router.push(`/programs/${result.id}`).then(() => {}),
  );
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <div>
        <h1>{{ t('education.programs') }}</h1>
        <p class="edu-subtle">{{ t('education.catalogHistoryHint') }}</p>
      </div>
      <button
        class="edu-button edu-button--primary"
        type="button"
        :aria-expanded="creating"
        @click="creating = !creating"
      >
        {{ t('education.createProgram') }}
      </button>
    </header>
    <form
      v-if="creating"
      class="edu-form edu-card"
      @submit.prevent="create"
    >
      <AppInput
        id="program-name"
        v-model="name"
        :label="t('education.name')"
        :maxlength="160"
        required
        :disabled="busy || uncertain"
      />
      <label for="program-description">
        {{ t('education.description') }}
        <textarea
          id="program-description"
          v-model="description"
          maxlength="5000"
          :disabled="busy || uncertain"
        ></textarea>
      </label>
      <p
        v-if="actionError"
        class="edu-error"
        role="alert"
      >
        {{ uncertain ? t('education.uncertain') : issue(actionError) }}
      </p>
      <button
        class="edu-button edu-button--primary"
        :disabled="busy || uncertain"
      >
        {{ t(busy ? 'education.loading' : 'education.save') }}
      </button>
    </form>
    <EducationState
      :loading="loading && !data"
      :error="error"
      :empty="data && !data.items.length ? t('education.noPrograms') : undefined"
      @retry="refresh"
    />
    <ul
      v-if="data"
      class="edu-list"
    >
      <li
        v-for="program in data.items"
        :key="program.id"
      >
        <RouterLink
          :to="`/programs/${program.id}`"
          class="edu-list__item"
        >
          <div>
            <strong>{{ program.name }}</strong>
            <p class="edu-subtle">{{ program.description }}</p>
          </div>
          <span
            v-if="program.archivedAt"
            class="edu-status"
          >
            {{ t('education.archived') }}
          </span>
          <span class="edu-link">{{ t('education.open') }}</span>
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
        v-if="data?.nextOffset !== null && data?.nextOffset !== undefined"
        type="button"
        class="edu-button"
        @click="offset = data!.nextOffset!"
      >
        {{ t('education.loadMore') }}
      </button>
    </div>
  </section>
</template>
