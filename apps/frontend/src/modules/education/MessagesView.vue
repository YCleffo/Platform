<script setup lang="ts">
import { ref } from 'vue';
import { educationApi } from './api';
import { useEducationResource } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
const { t } = useEducationI18n();
const offset = ref(0);
const { data, loading, error, refresh } = useEducationResource(
  (org, signal) => educationApi.threads(org, offset.value, signal),
  [offset],
);
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
      <h1>{{ t('education.messages') }}</h1>
      <RouterLink
        to="/messages/new"
        class="edu-button edu-button--primary"
      >
        {{ t('education.writeMessage') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      :empty="data && !data.items.length ? t('education.noThreads') : undefined"
      @retry="refresh"
    />
    <ul
      v-if="data"
      class="edu-list"
    >
      <li
        v-for="item in data.items"
        :key="item.id"
      >
        <RouterLink
          :to="`/messages/${item.id}`"
          class="edu-list__item"
        >
          <strong>{{ item.subject }}</strong>
          <span
            v-if="item.unreadCount"
            class="edu-status"
          >
            {{ item.unreadCount }}
          </span>
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
