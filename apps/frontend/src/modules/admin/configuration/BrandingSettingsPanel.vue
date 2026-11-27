<script setup lang="ts">
import { computed, onScopeDispose, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { coreAdminSessionEpoch } from '@/core/admin/session';
import { loadPlatformBranding, platformBranding } from '@/shared/branding';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppFileDropzone from '@/shared/ui/fields/AppFileDropzone.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import { brandingApi } from './brandingApi';

const { t } = useI18n();
const auth = useAdminAuthStore();
const canManage = computed(() => auth.can('core.settings.manage'));
const name = ref(platformBranding.name);
const file = ref<File | null>(null);
const busy = ref(false);
const uploading = ref(false);
const progress = ref(0);
const error = ref('');
const saved = ref(false);
let controller: AbortController | null = null;
let active = true;

watch(
  () => platformBranding.name,
  (value) => {
    name.value = value;
  },
);
watch(coreAdminSessionEpoch, () => {
  controller?.abort();
  error.value = '';
  saved.value = false;
});
onScopeDispose(() => {
  active = false;
  controller?.abort();
});
void loadPlatformBranding().catch((caught) => {
  if (active) error.value = resolveErrorMessage(caught);
});

async function run(action: () => Promise<unknown>): Promise<void> {
  if (!canManage.value || busy.value) return;
  busy.value = true;
  error.value = '';
  saved.value = false;
  try {
    await action();
    if (active) saved.value = true;
  } catch (caught) {
    if (active && !(caught instanceof DOMException && caught.name === 'AbortError')) {
      error.value =
        caught instanceof TypeError ? t('branding.invalidIcon') : resolveErrorMessage(caught);
    }
  } finally {
    if (active) busy.value = false;
  }
}

function onSelectFiles(files: File[]): void {
  const candidate = files[0] ?? null;
  file.value = null;
  error.value = '';
  saved.value = false;
  if (
    candidate &&
    (candidate.size < 1 || candidate.size > 524288 || !/\.(png|ico)$/i.test(candidate.name))
  ) {
    error.value = t('branding.invalidIcon');
    return;
  }
  file.value = candidate;
}

async function upload(): Promise<void> {
  const selected = file.value;
  if (!selected) return;
  controller = new AbortController();
  const signal = controller.signal;
  progress.value = 0;
  uploading.value = true;
  try {
    await run(() =>
      brandingApi.uploadIcon(selected, signal, (value) => {
        if (active) progress.value = value;
      }),
    );
  } finally {
    uploading.value = false;
  }
}
</script>

<template>
  <section class="config-page branding-settings">
    <h2>{{ t('branding.title') }}</h2>
    <form
      class="config-card"
      @submit.prevent="run(() => brandingApi.rename(name.trim()))"
    >
      <AppInput
        id="platform-display-name"
        v-model="name"
        :label="t('branding.name')"
        required
        maxlength="100"
        :disabled="busy || !canManage"
      />
      <AppButton
        type="submit"
        :loading="busy"
        :disabled="!canManage || !name.trim() || name.trim() === platformBranding.name"
      >
        {{ t('common.save') }}
      </AppButton>
    </form>
    <article class="config-card branding-settings__icon">
      <h3>{{ t('branding.favicon') }}</h3>
      <img
        v-if="platformBranding.favicon"
        :src="platformBranding.favicon.url"
        alt=""
        width="48"
        height="48"
      />
      <p>{{ t('branding.iconHint') }}</p>
      <AppFileDropzone
        v-model="file"
        accept=".png,.ico,image/png,image/x-icon,image/vnd.microsoft.icon"
        :label="t('branding.chooseIcon')"
        :hint="t('branding.iconHint')"
        :disabled="busy || !canManage"
        :busy="busy && uploading"
        :progress="busy && uploading ? progress : null"
        :status-text="t('branding.uploadProgress')"
        cancellable
        @select="onSelectFiles"
        @clear="
          file = null;
          error = '';
          saved = false;
        "
        @cancel="controller?.abort()"
      />
      <div class="config-actions">
        <AppButton
          :disabled="!file || busy || !canManage"
          @click="upload"
        >
          {{ t('branding.upload') }}
        </AppButton>
        <AppButton
          v-if="platformBranding.favicon"
          variant="secondary"
          :disabled="busy || !canManage"
          @click="run(() => brandingApi.removeIcon())"
        >
          {{ t('branding.removeIcon') }}
        </AppButton>
      </div>
    </article>
    <p
      v-if="error"
      role="alert"
    >
      {{ error }}
    </p>
    <p
      v-if="saved"
      role="status"
    >
      {{ t('branding.saved') }}
    </p>
  </section>
</template>

<style scoped>
.branding-settings {
  max-width: 48rem;
}
.branding-settings .config-card {
  display: grid;
  gap: 1rem;
}
.branding-settings__icon img {
  object-fit: contain;
}
.branding-settings input[type='file'] {
  max-width: 100%;
}
.branding-settings progress {
  width: 100%;
}
.branding-settings .config-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}
</style>
