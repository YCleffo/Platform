<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { coreAccount, type CorePreferences } from '@/shared/api/core/account';
import { useCoreAccountSettings } from '@/core/admin/accountSettingsStore';
import { useCorePresence } from '@/core/admin/presenceStore';
import { useAdminOperation } from '../useAdminOperation';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import {
  changedSettings,
  copySettings,
  resetSettings,
  type ProfileSettings,
} from './profileSettings';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppSwitch from '@/shared/ui/fields/AppSwitch.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';

const { t } = useI18n();
const store = useCoreAccountSettings();
const presence = useCorePresence();
const preferencesGuard = useAdminOperation();
const settingsGuard = useAdminOperation();
const preferences = ref<CorePreferences | null>(null);
const savedPreferences = ref<CorePreferences | null>(null);
const form = ref<ProfileSettings | null>(null);
const savedSettings = ref<ProfileSettings | null>(null);
const prefLoading = ref(true);
const prefSaving = ref(false);
const settingsLoading = ref(true);
const settingsSaving = ref(false);
const prefError = ref('');
const settingsError = ref('');
const prefSuccess = ref('');
const settingsSuccess = ref('');
const resetOpen = ref(false);
watch(
  preferences,
  () => {
    prefSuccess.value = '';
  },
  { deep: true, flush: 'sync' },
);
watch(
  form,
  () => {
    settingsSuccess.value = '';
  },
  { deep: true, flush: 'sync' },
);
const prefDirty = computed(
  () => JSON.stringify(preferences.value) !== JSON.stringify(savedPreferences.value),
);
const settingsDirty = computed(
  () =>
    form.value !== null &&
    savedSettings.value !== null &&
    Object.keys(changedSettings(savedSettings.value, form.value)).length > 0,
);
const localeValue = computed({
  get: () => preferences.value?.locale ?? 'default',
  set: (v: string | string[]) => {
    if (preferences.value) preferences.value.locale = v === 'default' ? null : String(v);
  },
});
const timezoneValue = computed({
  get: () => preferences.value?.timezone ?? 'default',
  set: (v: string | string[]) => {
    if (preferences.value) preferences.value.timezone = v === 'default' ? null : String(v);
  },
});
const localeOptions = computed(() => [
  { value: 'default', label: t('coreProfile.defaultValue') },
  { value: 'ru-RU', label: 'Русский' },
  { value: 'en-US', label: 'English' },
  ...(preferences.value?.locale && !['ru-RU', 'en-US'].includes(preferences.value.locale)
    ? [{ value: preferences.value.locale, label: preferences.value.locale }]
    : []),
]);
const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
let knownZones: string[];
try {
  knownZones = (
    Intl as typeof Intl & { supportedValuesOf?: (key: string) => string[] }
  ).supportedValuesOf?.('timeZone') ?? [
    'Europe/Moscow',
    'Asia/Yekaterinburg',
    'America/New_York',
    'Europe/London',
  ];
} catch {
  knownZones = ['Europe/Moscow', 'Asia/Yekaterinburg', 'America/New_York', 'Europe/London'];
}
const timezoneOptions = computed(() => [
  { value: 'default', label: t('coreProfile.defaultValue') },
  ...[
    ...new Set([
      'UTC',
      browserTimezone,
      ...knownZones,
      ...(preferences.value?.timezone ? [preferences.value.timezone] : []),
    ]),
  ]
    .sort()
    .map((zone) => ({ value: zone, label: zone.replaceAll('_', ' ') })),
]);
async function loadPreferences(): Promise<void> {
  const request = preferencesGuard.begin();
  prefLoading.value = true;
  prefError.value = '';
  try {
    const result = await coreAccount.preferences();
    if (preferencesGuard.current(request)) {
      savedPreferences.value = { ...result };
      preferences.value = { ...result };
    }
  } catch (error) {
    if (preferencesGuard.current(request)) prefError.value = resolveErrorMessage(error);
  } finally {
    if (preferencesGuard.current(request)) prefLoading.value = false;
  }
}
async function loadSettings(): Promise<void> {
  const request = settingsGuard.begin();
  settingsLoading.value = true;
  settingsError.value = '';
  try {
    const result = await store.load(true);
    if (settingsGuard.current(request)) {
      savedSettings.value = copySettings(result.settings);
      form.value = copySettings(result.settings);
    }
  } catch (error) {
    if (settingsGuard.current(request)) settingsError.value = resolveErrorMessage(error);
  } finally {
    if (settingsGuard.current(request)) settingsLoading.value = false;
  }
}
async function savePreferences(): Promise<void> {
  if (prefSaving.value || !prefDirty.value || !preferences.value || !savedPreferences.value) return;
  const patch: Partial<CorePreferences> = {};
  for (const key of ['locale', 'timezone'] as const)
    if (preferences.value[key] !== savedPreferences.value[key]) patch[key] = preferences.value[key];
  const request = preferencesGuard.begin();
  prefSaving.value = true;
  prefError.value = '';
  prefSuccess.value = '';
  try {
    const result = await coreAccount.patchPreferences(patch);
    if (preferencesGuard.current(request)) {
      preferences.value = { ...result };
      savedPreferences.value = { ...result };
      prefSuccess.value = 'coreProfile.saved';
    }
  } catch (error) {
    if (preferencesGuard.current(request)) prefError.value = resolveErrorMessage(error);
  } finally {
    if (preferencesGuard.current(request)) prefSaving.value = false;
  }
}
async function saveSettings(reset = false): Promise<void> {
  if (
    settingsSaving.value ||
    !form.value ||
    !savedSettings.value ||
    (!reset && !settingsDirty.value)
  )
    return;
  const patch = reset ? resetSettings : changedSettings(savedSettings.value, form.value);
  const request = settingsGuard.begin();
  settingsSaving.value = true;
  settingsError.value = '';
  settingsSuccess.value = '';
  try {
    const result = await store.patch(patch);
    if (settingsGuard.current(request)) {
      form.value = copySettings(result.settings);
      savedSettings.value = copySettings(result.settings);
      resetOpen.value = false;
      settingsSuccess.value = reset ? 'coreProfile.resetDone' : 'coreProfile.saved';
    }
  } catch (error) {
    if (settingsGuard.current(request)) settingsError.value = resolveErrorMessage(error);
  } finally {
    if (settingsGuard.current(request)) settingsSaving.value = false;
  }
}
onMounted(() => {
  void loadPreferences();
  void loadSettings();
});
</script>
<template>
  <section class="core-preferences">
    <AppCard>
      <h2>{{ $t('coreProfile.languageTime') }}</h2>
      <p>{{ $t('coreProfile.languageHint') }}</p>
      <AppSkeleton
        v-if="prefLoading"
        :lines="3"
      />
      <AppErrorState
        v-else-if="!preferences && prefError"
        :message="prefError"
      >
        <AppButton
          variant="secondary"
          @click="loadPreferences"
        >
          {{ $t('common.retry') }}
        </AppButton>
      </AppErrorState>
      <form
        v-else-if="preferences"
        class="core-preferences__form"
        @submit.prevent="savePreferences"
      >
        <div class="core-preferences__fields">
          <div>
            <label for="core-profile-locale">{{ $t('coreProfile.language') }}</label>
            <AppDropdown
              id="core-profile-locale"
              v-model="localeValue"
              :label="$t('coreProfile.language')"
              :options="localeOptions"
              :disabled="prefSaving"
            />
          </div>
          <div>
            <label for="core-profile-timezone">{{ $t('coreProfile.timezone') }}</label>
            <AppDropdown
              id="core-profile-timezone"
              v-model="timezoneValue"
              :label="$t('coreProfile.timezone')"
              :options="timezoneOptions"
              searchable
              :disabled="prefSaving"
            />
          </div>
        </div>
        <AppButton
          variant="secondary"
          :disabled="prefSaving"
          @click="preferences.timezone = browserTimezone"
        >
          {{ $t('coreProfile.useBrowserTimezone', { timezone: browserTimezone }) }}
        </AppButton>
        <div class="core-preferences__actions">
          <AppButton
            type="submit"
            :loading="prefSaving"
            :disabled="!prefDirty"
          >
            {{ $t('coreProfile.savePreferences') }}
          </AppButton>
          <AppButton
            variant="secondary"
            :disabled="prefSaving || !prefDirty"
            @click="preferences = savedPreferences ? { ...savedPreferences } : null"
          >
            {{ $t('coreProfile.cancelChanges') }}
          </AppButton>
        </div>
        <p
          v-if="prefError"
          role="alert"
        >
          {{ prefError }}
        </p>
        <p
          v-if="prefSuccess"
          role="status"
        >
          {{ $t(prefSuccess) }}
        </p>
      </form>
    </AppCard>
    <AppSkeleton
      v-if="settingsLoading"
      variant="card"
      :lines="6"
    />
    <AppErrorState
      v-else-if="!form && settingsError"
      :message="settingsError"
    >
      <AppButton
        variant="secondary"
        @click="loadSettings"
      >
        {{ $t('common.retry') }}
      </AppButton>
    </AppErrorState>
    <form
      v-else-if="form"
      class="core-preferences__settings"
      @submit.prevent="saveSettings(false)"
    >
      <div class="core-preferences__grid">
        <AppCard>
          <h2>{{ $t('coreProfile.interface') }}</h2>
          <p>{{ $t('coreProfile.interfaceHint') }}</p>
          <AppSwitch
            id="core-profile-animations"
            v-model="form.interface.animations"
            :label="$t('coreProfile.animations')"
            :disabled="settingsSaving"
          />
          <AppSwitch
            id="core-profile-transparency"
            v-model="form.interface.transparency"
            :label="$t('coreProfile.transparency')"
            :disabled="settingsSaving"
          />
          <RouterLink :to="{ name: 'settings' }">
            {{ $t('coreProfile.browserAppearance') }}
          </RouterLink>
        </AppCard>
        <AppCard>
          <h2>{{ $t('coreProfile.notifications') }}</h2>
          <p>{{ $t('coreProfile.notificationsHint') }}</p>
          <AppSwitch
            v-for="key in ['browser', 'sound', 'email'] as const"
            :id="`core-profile-${key}`"
            :key="key"
            v-model="form.notifications[key]"
            :label="$t(`coreProfile.channel_${key}`)"
            :disabled="settingsSaving"
          />
        </AppCard>
        <AppCard>
          <h2>{{ $t('coreProfile.privacy') }}</h2>
          <p>{{ $t('coreProfile.privacyHint') }}</p>
          <p role="status">{{ $t(`corePresence.${presence.state}`) }}</p>
          <AppSwitch
            id="core-profile-online"
            v-model="form.privacy.show_online_status"
            :label="$t('coreProfile.showOnline')"
            :disabled="settingsSaving"
          />
        </AppCard>
      </div>
      <AppCard class="core-preferences__save">
        <div class="core-preferences__actions">
          <AppButton
            type="submit"
            :loading="settingsSaving"
            :disabled="!settingsDirty"
          >
            {{ $t('coreProfile.saveSettings') }}
          </AppButton>
          <AppButton
            variant="secondary"
            :disabled="settingsSaving || !settingsDirty"
            @click="form = savedSettings ? copySettings(savedSettings) : null"
          >
            {{ $t('coreProfile.cancelChanges') }}
          </AppButton>
          <AppButton
            variant="secondary"
            :disabled="settingsSaving"
            @click="resetOpen = true"
          >
            {{ $t('coreProfile.resetSettings') }}
          </AppButton>
        </div>
        <p
          v-if="settingsError"
          role="alert"
        >
          {{ settingsError }}
        </p>
        <p
          v-if="settingsSuccess"
          role="status"
        >
          {{ $t(settingsSuccess) }}
        </p>
      </AppCard>
    </form>
    <AppConfirmDialog
      v-model="resetOpen"
      :title="$t('coreProfile.resetSettings')"
      :description="$t('coreProfile.resetHint')"
      :confirm-label="$t('coreProfile.resetSettings')"
      :busy="settingsSaving"
      @confirm="saveSettings(true)"
    >
      <p
        v-if="settingsError"
        role="alert"
      >
        {{ settingsError }}
      </p>
    </AppConfirmDialog>
  </section>
</template>
<style scoped>
.core-preferences,
.core-preferences__form,
.core-preferences__settings {
  display: grid;
  gap: var(--space-16);
}
.core-preferences :deep(.app-card) {
  display: grid;
  gap: var(--space-12);
}
.core-preferences h2 {
  color: var(--text-1);
  font-size: var(--type-h2-size);
  font-weight: var(--type-h2-weight);
  letter-spacing: var(--type-h2-tracking);
  line-height: var(--type-h2-line);
}
.core-preferences p {
  color: var(--text-2);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
}
.core-preferences__form {
  justify-items: start;
}
.core-preferences__fields {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr));
  gap: var(--space-16);
  width: 100%;
}
.core-preferences__fields > div {
  display: grid;
  gap: var(--space-8);
  min-width: 0;
}
.core-preferences__fields label {
  color: var(--text-2);
  font-size: var(--type-body-medium-emphasis-size);
  letter-spacing: var(--type-body-medium-emphasis-tracking);
  line-height: var(--type-body-medium-emphasis-line);
  font-weight: var(--type-body-medium-emphasis-weight);
}
.core-preferences__grid :deep(.app-card) {
  align-content: start;
}
.core-preferences__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}
.core-preferences__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 360px), 1fr));
  gap: var(--space-16);
}
.core-preferences__save p {
  margin-top: var(--space-12);
}
</style>
