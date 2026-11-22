<script setup lang="ts">
import { ref } from 'vue';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { coreAccount } from '@/shared/api/core/account';
import { useTheme } from '@/app/theme';
import { applyAccountLocale, setLocale } from '@/shared/i18n';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import { educationApi } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationMutation, useEducationError } from './resources';
import { useEducationI18n } from './i18n';
import EducationAvatar from './EducationAvatar.vue';
const workspace = useEducationWorkspace();
const auth = useAdminAuthStore();
const { t } = useEducationI18n();
const issue = useEducationError();
const { theme, setTheme } = useTheme();
const name = ref(workspace.header!.displayName ?? '');
const avatar = ref(workspace.header!.avatarFileId);
const removeAvatar = ref(false);
const file = ref<File | null>(null);
const locale = ref(workspace.header!.locale.toLowerCase().startsWith('ru') ? 'ru-RU' : 'en-US');
const timezone = ref(workspace.header!.timezone);
const localError = ref('');
const saved = ref(false);
const mutation = useEducationMutation();
const { busy, error, uncertain } = mutation;
async function save(): Promise<void> {
  localError.value = '';
  saved.value = false;
  if (file.value && file.value.size > 2 * 1024 * 1024) {
    localError.value = t('education.fileHint');
    return;
  }
  await mutation.run(
    async () => {
      const uploaded = file.value
        ? await educationApi.upload(workspace.organizationId, file.value, undefined, true)
        : null;
      return educationApi.saveProfile(
        workspace.organizationId,
        name.value.trim() || null,
        uploaded?.id ?? (removeAvatar.value ? null : avatar.value),
      );
    },
    (result) => {
      workspace.header = result;
      avatar.value = result.avatarFileId;
      removeAvatar.value = false;
      file.value = null;
      saved.value = true;
    },
  );
}
async function savePreferences(): Promise<void> {
  saved.value = false;
  localError.value = '';
  try {
    new Intl.DateTimeFormat('en', { timeZone: timezone.value });
  } catch {
    localError.value = t('education.invalid');
    return;
  }
  const requestedLocale = locale.value;
  const languageChanged =
    requestedLocale !==
    (workspace.header!.locale.toLowerCase().startsWith('ru') ? 'ru-RU' : 'en-US');
  await mutation.run(
    () => coreAccount.patchPreferences({ locale: requestedLocale, timezone: timezone.value }),
    async (result) => {
      applyAccountLocale(result.locale);
      if (languageChanged) setLocale(requestedLocale === 'ru-RU' ? 'ru' : 'en');
      await workspace.refreshHeader(true);
      saved.value = true;
    },
  );
}
async function reconcile(): Promise<void> {
  await workspace.refreshHeader();
  if (!workspace.headerError && workspace.header) {
    uncertain.value = false;
    error.value = null;
    name.value = workspace.header.displayName ?? '';
    avatar.value = workspace.header.avatarFileId;
    locale.value = workspace.header.locale.toLowerCase().startsWith('ru') ? 'ru-RU' : 'en-US';
    timezone.value = workspace.header.timezone;
  }
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ t('education.more') }}</h1>
      <button
        type="button"
        class="edu-button"
        @click="auth.logout()"
      >
        {{ t('education.signOut') }}
      </button>
    </header>
    <nav
      class="edu-toolbar"
      :aria-label="t('education.more')"
    >
      <RouterLink
        to="/programs"
        class="edu-button"
      >
        {{ t('education.programs') }}
      </RouterLink>
      <RouterLink
        to="/homework"
        class="edu-button"
      >
        {{ t('education.homework') }}
      </RouterLink>
      <RouterLink
        to="/messages"
        class="edu-button"
      >
        {{ t('education.messages') }}
      </RouterLink>
      <RouterLink
        to="/materials"
        class="edu-button"
      >
        {{ t('education.materials') }}
      </RouterLink>
      <RouterLink
        to="/notifications"
        class="edu-button"
      >
        {{ t('education.notifications') }}
      </RouterLink>
      <RouterLink
        to="/attention"
        class="edu-button"
      >
        {{ t('education.attention') }}
      </RouterLink>
    </nav>
    <p
      v-if="error || localError"
      class="edu-error"
      role="alert"
    >
      {{ localError || (uncertain ? t('education.uncertain') : issue(error)) }}
    </p>
    <button
      v-if="uncertain"
      type="button"
      class="edu-button"
      @click="reconcile"
    >
      {{ t('education.refresh') }}
    </button>
    <p
      v-if="saved"
      role="status"
    >
      {{ t('education.saved') }}
    </p>
    <form
      class="edu-form edu-card"
      @submit.prevent="save"
    >
      <h2>{{ t('education.profile') }}</h2>
      <EducationAvatar />
      <AppInput
        id="education-display-name"
        v-model="name"
        :label="t('education.displayName')"
        :maxlength="160"
        :disabled="busy || uncertain"
      />
      <label for="education-avatar-file">
        {{ t('education.avatar') }}
        <input
          id="education-avatar-file"
          class="edu-file-input"
          type="file"
          accept="image/png,image/jpeg,image/webp"
          :disabled="busy || uncertain"
          @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null"
        />
        <small>{{ t('education.fileHint') }}</small>
      </label>
      <label v-if="avatar">
        <input
          type="checkbox"
          v-model="removeAvatar"
          :disabled="busy || uncertain"
        />
        {{ t('education.removeAvatar') }}
      </label>
      <button
        type="submit"
        class="edu-button edu-button--primary"
        :disabled="busy || uncertain"
      >
        {{ t('education.save') }}
      </button>
    </form>
    <form
      class="edu-form edu-card"
      @submit.prevent="savePreferences"
    >
      <h2>{{ t('education.language') }}</h2>
      <AppDropdown
        id="education-language"
        v-model="locale"
        :label="t('education.language')"
        :options="[
          { value: 'ru-RU', label: 'Русский' },
          { value: 'en-US', label: 'English' },
        ]"
        :disabled="busy || uncertain"
      />
      <AppInput
        id="education-timezone"
        v-model="timezone"
        :label="t('education.timezone')"
        required
        :disabled="busy || uncertain"
      />
      <button
        type="submit"
        class="edu-button edu-button--primary"
        :disabled="busy || uncertain"
      >
        {{ t('education.save') }}
      </button>
    </form>
    <div class="edu-card">
      <h2>{{ t('education.appearance') }}</h2>
      <div class="edu-toolbar">
        <button
          type="button"
          class="edu-button"
          :aria-pressed="theme === 'light'"
          @click="setTheme('light')"
        >
          {{ t('education.light') }}
        </button>
        <button
          type="button"
          class="edu-button"
          :aria-pressed="theme === 'dark'"
          @click="setTheme('dark')"
        >
          {{ t('education.dark') }}
        </button>
      </div>
    </div>
  </section>
</template>
