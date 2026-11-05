<script setup lang="ts">
import { nextTick, onScopeDispose, ref, shallowRef, watch } from 'vue';
import { useRoute } from 'vue-router';
import { coreAdminSession } from '@/core/admin/session';
import { formatZonedTime } from '@/shared/formatting/calendar';
import { educationApi } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationMutation, useEducationResource, useEducationError } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
const route = useRoute();
const workspace = useEducationWorkspace();
const { t, locale } = useEducationI18n();
const issue = useEducationError();
const id = String(route.params.id);
const offset = ref(0);
const body = ref('');
const readError = shallowRef<unknown>(null);
let active = true;
const { data, loading, error, refresh } = useEducationResource(
  async (org, signal) => ({
    thread: await educationApi.thread(org, id, signal),
    messages: await educationApi.messages(org, id, offset.value, signal),
  }),
  [offset],
);
const mutation = useEducationMutation();
const { busy, error: actionError, uncertain } = mutation;
async function markRead(): Promise<void> {
  const value = data.value;
  if (!value?.messages.items.length) return;
  const epoch = coreAdminSession.epoch();
  const revision = workspace.revision;
  const org = workspace.organizationId;
  readError.value = null;
  await nextTick();
  if (
    !active ||
    value !== data.value ||
    epoch !== coreAdminSession.epoch() ||
    revision !== workspace.revision
  )
    return;
  try {
    await educationApi.readThread(org, id, value.messages.items.at(-1)!.id);
    if (active && revision === workspace.revision) await workspace.refreshHeader();
  } catch (caught) {
    if (active && revision === workspace.revision && epoch === coreAdminSession.epoch())
      readError.value = caught;
  }
}
watch(data, () => {
  void markRead();
});
onScopeDispose(() => {
  active = false;
  body.value = '';
});
async function send(): Promise<void> {
  await mutation.run(
    () => educationApi.sendMessage(workspace.organizationId, id, body.value),
    async () => {
      body.value = '';
      if (offset.value !== 0) offset.value = 0;
      else await refresh();
    },
  );
}
async function reconcile(): Promise<void> {
  await refresh();
  if (!error.value) {
    uncertain.value = false;
    actionError.value = null;
  }
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <button
        type="button"
        class="edu-link"
        :disabled="loading"
        @click="refresh"
      >
        {{ t('education.refresh') }}
      </button>
      <h1>{{ data?.thread.subject ?? t('education.messages') }}</h1>
      <RouterLink
        to="/messages"
        class="edu-link"
      >
        {{ t('education.back') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data">
      <ul class="edu-list">
        <li
          v-for="message in data.messages.items"
          :key="message.id"
          class="edu-list__item"
          :class="{ 'edu-message--own': message.senderPersonId === workspace.header!.personId }"
        >
          <div>
            <strong>{{ message.senderName }}</strong>
            <p class="edu-message">{{ message.body ?? t('education.unavailableMessage') }}</p>
            <small>
              {{ formatZonedTime(message.createdAt, locale, workspace.header!.timezone) }}
            </small>
          </div>
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
          v-if="data.messages.nextOffset !== null"
          type="button"
          class="edu-button"
          @click="offset = data!.messages.nextOffset!"
        >
          {{ t('education.loadMore') }}
        </button>
      </div>
      <p
        v-if="readError"
        class="edu-error"
        role="alert"
      >
        {{ issue(readError) }}
        <button
          type="button"
          class="edu-link"
          @click="markRead"
        >
          {{ t('education.retry') }}
        </button>
      </p>
      <form
        class="edu-form"
        @submit.prevent="send"
      >
        <label for="education-reply">
          {{ t('education.message') }}
          <textarea
            id="education-reply"
            v-model="body"
            maxlength="5000"
            required
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
        <div class="edu-form-actions">
          <button
            type="submit"
            class="edu-button edu-button--primary"
            :disabled="busy || uncertain"
          >
            {{ t(busy ? 'education.loading' : 'education.send') }}
          </button>
          <button
            v-if="uncertain"
            type="button"
            class="edu-button"
            @click="reconcile"
          >
            {{ t('education.checkList') }}
          </button>
        </div>
      </form>
    </template>
  </section>
</template>
