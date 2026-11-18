<script setup lang="ts">
import { onScopeDispose, ref, shallowRef, watch } from 'vue';
import { coreAdminSessionEpoch } from '@/core/admin/session';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import { homeworkApi, type HomeworkFile } from './api';
import { useHomeworkMutation } from './resources';
import type { TransferProgress } from '../fileTransfer';
import { useEducationError } from '../resources';
import { useEducationI18n } from '../i18n';
const props = defineProps<{
  organizationId: string;
  assignmentId: string;
  files: HomeworkFile[];
  editable?: boolean;
  disabled?: boolean;
  student?: boolean;
  title: string;
}>();
const emit = defineEmits<{ changed: [] }>();
const { t } = useEducationI18n();
const issue = useEducationError();
const { busy, error, uncertain, run } = useHomeworkMutation(
  () => props.organizationId,
  () => props.assignmentId,
);
const validation = ref('');
const progress = shallowRef<TransferProgress | null>(null);
const downloading = ref<string | null>(null);
const downloadError = shallowRef<unknown>(null);
const urls = new Set<string>();
let uploadController: AbortController | null = null;
let downloadController: AbortController | null = null;

function clearTransfers(): void {
  uploadController?.abort();
  downloadController?.abort();
  uploadController = null;
  downloadController = null;
  progress.value = null;
  downloading.value = null;
  validation.value = '';
  downloadError.value = null;
  urls.forEach((url) => URL.revokeObjectURL(url));
  urls.clear();
}

watch(
  [
    () => props.organizationId,
    () => props.assignmentId,
    () => props.student,
    coreAdminSessionEpoch,
  ],
  clearTransfers,
  { flush: 'sync' },
);
onScopeDispose(clearTransfers);

async function upload(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement,
    file = input.files?.[0];
  input.value = '';
  validation.value = '';
  if (!file || busy.value || uncertain.value || props.disabled) return;
  if (
    !file.size ||
    file.size > 10 * 1024 * 1024 ||
    !/\.(pdf|png|jpe?g|webp|txt)$/i.test(file.name)
  ) {
    validation.value = t('education.homeworkFileHint');
    return;
  }
  const request = new AbortController();
  uploadController = request;
  downloadError.value = null;
  progress.value = { loaded: 0, total: file.size, phase: 'hashing' };
  try {
    await run(
      async () => {
        try {
          await homeworkApi.upload(props.organizationId, props.assignmentId, file, props.student, {
            signal: request.signal,
            progress: (value) => {
              if (uploadController === request && !request.signal.aborted) progress.value = value;
            },
          });
        } catch (failure) {
          if (!request.signal.aborted) throw failure;
        }
        return !request.signal.aborted;
      },
      (uploaded) => {
        if (uploaded) emit('changed');
      },
    );
  } finally {
    if (uploadController === request) {
      uploadController = null;
      progress.value = null;
    }
  }
}

async function download(file: HomeworkFile): Promise<void> {
  if (busy.value || downloadController) return;
  const request = new AbortController();
  downloadController = request;
  downloadError.value = null;
  downloading.value = file.id;
  try {
    const blob = await homeworkApi.download(props.organizationId, file.id, request.signal);
    if (downloadController !== request || request.signal.aborted) return;
    const url = URL.createObjectURL(blob);
    urls.add(url);
    const link = document.createElement('a');
    link.href = url;
    link.download = file.name;
    link.click();
    window.setTimeout(() => {
      if (urls.delete(url)) URL.revokeObjectURL(url);
    }, 1000);
  } catch (failure) {
    if (downloadController === request && !request.signal.aborted) downloadError.value = failure;
  } finally {
    if (downloadController === request) {
      downloadController = null;
      downloading.value = null;
    }
  }
}
async function remove(file: HomeworkFile): Promise<void> {
  await run(
    () => homeworkApi.removeFile(props.organizationId, props.assignmentId, file.id),
    () => emit('changed'),
  );
}
</script>
<template>
  <section class="edu-homework-files">
    <h3>{{ title }}</h3>
    <ul
      v-if="files.length"
      class="edu-list"
    >
      <li
        v-for="file in files"
        :key="file.id"
        class="edu-list__item"
      >
        <button
          type="button"
          class="edu-link"
          :disabled="busy || downloading !== null"
          @click="download(file)"
        >
          {{ downloading === file.id ? t('education.loading') : file.name }}
          · {{ Math.ceil(file.sizeBytes / 1024) }} KB
        </button>
        <AppButton
          v-if="editable && student"
          variant="secondary"
          :disabled="busy || uncertain || disabled"
          @click="remove(file)"
        >
          {{ t('education.homeworkDeleteFile') }}
        </AppButton>
      </li>
    </ul>
    <label
      v-if="editable"
      class="edu-homework-upload"
    >
      <span>{{ t('education.homeworkUpload') }}</span>
      <input
        type="file"
        :disabled="busy || uncertain || disabled"
        accept=".pdf,.png,.jpg,.jpeg,.webp,.txt"
        @change="upload"
      />
      <small class="edu-subtle">{{ t('education.homeworkFileHint') }}</small>
    </label>
    <div
      v-if="progress"
      class="edu-homework-upload"
      role="status"
    >
      <label>
        {{ t(`education.transfer_${progress.phase}`) }}
        <progress
          :value="progress.loaded"
          :max="progress.total || 1"
        />
      </label>
      <button
        v-if="progress.phase !== 'verifying'"
        type="button"
        class="edu-link"
        @click="uploadController?.abort()"
      >
        {{ t('education.cancelTransfer') }}
      </button>
    </div>
    <p
      v-if="validation || error || downloadError"
      class="edu-error"
      role="alert"
    >
      {{ validation || issue(error || downloadError) }}
    </p>
    <p
      v-if="uncertain"
      role="alert"
    >
      {{ t('education.homeworkUncertain') }}
      <button
        type="button"
        class="edu-link"
        @click="
          emit('changed');
          uncertain = false;
        "
      >
        {{ t('education.homeworkReload') }}
      </button>
    </p>
  </section>
</template>
