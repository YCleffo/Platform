<script setup lang="ts">
import { onScopeDispose, ref, shallowRef, watch } from 'vue';
import { coreAdminSessionEpoch } from '@/core/admin/session';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppFileDropzone from '@/shared/ui/fields/AppFileDropzone.vue';
import MediaPreviewDialog from '@/shared/ui/media/MediaPreviewDialog.vue';
import { mediaController } from '@/shared/media/mediaController';
import { homeworkApi, type HomeworkFile } from './api';
import { useHomeworkMutation } from './resources';
import type { TransferProgress } from '../fileTransfer';
import { useEducationError } from '../resources';
import { useEducationI18n } from '../i18n';
import {
  useOperationsCenter,
  type UploadTrackerHandle,
} from '@/modules/workspace/useOperationsCenter';
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
const operations = useOperationsCenter();
const { busy, error, uncertain, run } = useHomeworkMutation(
  () => props.organizationId,
  () => props.assignmentId,
);
const validation = ref('');
const progress = shallowRef<TransferProgress | null>(null);
const downloading = ref<string | null>(null);
const downloadError = shallowRef<unknown>(null);
const previewState = shallowRef<{ open: boolean; url: string; name: string; sizeBytes: number } | null>(
  null,
);
const urls = new Set<string>();
let uploadController: AbortController | null = null;
let uploadHandle: UploadTrackerHandle | null = null;
let downloadController: AbortController | null = null;

function clearTransfers(): void {
  if (uploadHandle) {
    void operations.cancelUpload(uploadHandle.id);
    uploadHandle = null;
  } else {
    uploadController?.abort();
  }
  downloadController?.abort();
  uploadController = null;
  downloadController = null;
  progress.value = null;
  downloading.value = null;
  validation.value = '';
  downloadError.value = null;
  urls.forEach((url) => mediaController.revokeObjectUrl(url));
  urls.clear();
  previewState.value = null;
}

function closePreview(): void {
  if (previewState.value?.url) {
    urls.delete(previewState.value.url);
    mediaController.revokeObjectUrl(previewState.value.url);
  }
  previewState.value = null;
}

async function previewFile(file: HomeworkFile): Promise<void> {
  if (busy.value || downloadController) return;
  const request = new AbortController();
  downloadController = request;
  downloadError.value = null;
  downloading.value = file.id;
  try {
    const blob = await homeworkApi.download(props.organizationId, file.id, request.signal);
    if (downloadController !== request || request.signal.aborted) return;
    const url = mediaController.trackObjectUrl(URL.createObjectURL(blob));
    urls.add(url);
    previewState.value = { open: true, url, name: file.name, sizeBytes: file.sizeBytes };
  } catch (failure) {
    if (downloadController === request && !request.signal.aborted) downloadError.value = failure;
  } finally {
    if (downloadController === request) {
      downloadController = null;
      downloading.value = null;
    }
  }
}

function cancelHomeworkUpload(): void {
  if (uploadHandle) {
    void operations.cancelUpload(uploadHandle.id);
  } else {
    uploadController?.abort();
  }
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

async function onFilesSelected(files: File[]): Promise<void> {
  const file = files[0];
  validation.value = '';
  if (!file || busy.value || uncertain.value || props.disabled) return;
  if (
    !file.size ||
    file.size > 25 * 1024 * 1024 ||
    !/\.(pdf|png|jpe?g|webp|txt|mp3|wav|ogg|m4a|mp4|webm)$/i.test(file.name)
  ) {
    validation.value = t('education.homeworkFileHint');
    return;
  }
  const request = new AbortController();
  uploadController = request;
  const tracker = operations.registerUpload({
    organizationId: props.organizationId,
    label: file.name,
    total: file.size,
    abortController: request,
  });
  uploadHandle = tracker;
  downloadError.value = null;
  progress.value = { loaded: 0, total: file.size, phase: 'hashing' };
  try {
    await run(
      async () => {
        try {
          await homeworkApi.upload(props.organizationId, props.assignmentId, file, props.student, {
            signal: request.signal,
            progress: (value) => {
              if (uploadController === request && !request.signal.aborted) {
                progress.value = value;
                tracker.updateProgress({
                  loaded: value.loaded,
                  total: value.total,
                  lengthComputable: value.total > 0,
                  percent: value.total > 0 ? Math.round((value.loaded / value.total) * 100) : null,
                });
              }
            },
          });
        } catch (failure) {
          if (!request.signal.aborted) throw failure;
        }
        return !request.signal.aborted;
      },
      (uploaded) => {
        if (uploaded) {
          tracker.markCompleted();
          emit('changed');
        } else {
          tracker.markCancelled();
        }
      },
    );
  } catch (failure) {
    if (request.signal.aborted) {
      tracker.markCancelled();
    } else {
      tracker.markFailed(failure instanceof Error ? failure.message : 'Upload failed');
    }
    throw failure;
  } finally {
    if (uploadController === request) {
      uploadController = null;
      uploadHandle = null;
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
          variant="secondary"
          :disabled="busy || downloading !== null"
          @click="previewFile(file)"
        >
          {{ t('media.preview') }}
        </AppButton>
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
    <AppFileDropzone
      v-if="editable"
      compact
      accept=".pdf,.png,.jpg,.jpeg,.webp,.txt,.mp3,.wav,.ogg,.m4a,.mp4,.webm"
      :label="t('education.homeworkUpload')"
      :hint="t('education.homeworkFileHint')"
      :disabled="busy || uncertain || disabled"
      :busy="Boolean(progress)"
      :progress="progress ? Math.round((progress.loaded / (progress.total || 1)) * 100) : null"
      :status-text="progress ? t(`education.transfer_${progress.phase}`) : ''"
      :cancellable="Boolean(progress && progress.phase !== 'verifying')"
      :error="validation"
      @select="onFilesSelected"
      @cancel="cancelHomeworkUpload"
    />
    <p
      v-if="error || downloadError"
      class="edu-error"
      role="alert"
    >
      {{ issue(error || downloadError) }}
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
    <MediaPreviewDialog
      v-if="previewState?.open"
      :open="previewState.open"
      :src="previewState.url"
      :name="previewState.name"
      :size-bytes="previewState.sizeBytes"
      @close="closePreview"
    />
  </section>
</template>
