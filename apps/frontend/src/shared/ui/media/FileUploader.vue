<script setup lang="ts">
import { onScopeDispose, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { computeBlobSha256, mediaApi } from '@/shared/api/mediaApi';
import { useOperationsCenter, type UploadTrackerHandle } from '@/modules/workspace/useOperationsCenter';
import AppFileDropzone from '@/shared/ui/fields/AppFileDropzone.vue';
import type { MediaKind } from './types';

export interface UploadedMediaPayload {
  id: string;
  url: string;
  mimeType: string;
  file: File;
}

const props = withDefaults(
  defineProps<{
    organizationId: string;
    kind?: MediaKind | 'auto';
    accept?: string;
    multiple?: boolean;
    compact?: boolean;
    disabled?: boolean;
    maxSizeBytes?: number;
    label?: string;
    hint?: string;
  }>(),
  {
    kind: 'auto',
    accept: '',
    multiple: false,
    compact: false,
    disabled: false,
    maxSizeBytes: 100 * 1024 * 1024,
    label: '',
    hint: '',
  },
);

const emit = defineEmits<{
  (e: 'uploaded', payload: UploadedMediaPayload): void;
  (e: 'error', message: string): void;
  (e: 'cancel'): void;
}>();

const { t } = useI18n();
const operations = useOperationsCenter();

const busy = ref(false);
const progress = ref<number | null>(null);
const errorText = ref('');
let controller: AbortController | null = null;
let trackerHandle: UploadTrackerHandle | null = null;

function inferMediaKind(file: File): MediaKind {
  if (props.kind !== 'auto') return props.kind;
  const mime = (file.type || '').toLowerCase();
  if (mime.startsWith('image/')) return 'image';
  if (mime.startsWith('video/')) return 'video';
  if (mime.startsWith('audio/')) return 'audio';
  return 'document';
}

function cancelUpload(): void {
  if (trackerHandle) {
    void operations.cancelUpload(trackerHandle.id);
  } else {
    controller?.abort();
  }
  emit('cancel');
}

async function onFilesSelected(files: File[]): Promise<void> {
  if (!files.length || busy.value || props.disabled) return;
  errorText.value = '';
  const file = files[0]!;
  if (file.size <= 0 || file.size > props.maxSizeBytes) {
    errorText.value = t('media.unavailable');
    emit('error', errorText.value);
    return;
  }

  busy.value = true;
  progress.value = 0;
  const abort = new AbortController();
  controller = abort;
  const tracker = operations.registerUpload({
    organizationId: props.organizationId,
    label: file.name,
    total: file.size,
    abortController: abort,
  });
  trackerHandle = tracker;

  try {
    const kind = inferMediaKind(file);
    const sha256 = await computeBlobSha256(file);
    if (abort.signal.aborted) {
      throw new DOMException('Aborted', 'AbortError');
    }
    const reserved = await mediaApi.reserveUpload(props.organizationId, {
      kind,
      name: file.name,
      mime: file.type || 'application/octet-stream',
      sizeBytes: file.size,
      sha256,
    });
    tracker.setMediaId(reserved.id);

    await mediaApi.uploadBinary(props.organizationId, reserved.id, file, sha256, {
      signal: abort.signal,
      onProgress: (info) => {
        progress.value = info.percent;
        tracker.updateProgress(info);
      },
    });
    const playback = await mediaApi.getPlaybackUrl(props.organizationId, reserved.id);
    tracker.markCompleted();
    emit('uploaded', {
      id: reserved.id,
      url: playback.url,
      mimeType: playback.mime,
      file,
    });
  } catch (err) {
    if (abort.signal.aborted || (err instanceof DOMException && err.name === 'AbortError')) {
      tracker.markCancelled();
      errorText.value = t('media.uploadCancelled');
    } else {
      const msg = err instanceof Error ? err.message : 'Upload failed';
      tracker.markFailed(msg);
      errorText.value = msg;
      emit('error', msg);
    }
  } finally {
    if (controller === abort) {
      controller = null;
      trackerHandle = null;
    }
    busy.value = false;
    progress.value = null;
  }
}

onScopeDispose(() => {
  if (trackerHandle) {
    void operations.cancelUpload(trackerHandle.id);
  } else {
    controller?.abort();
  }
});
</script>

<template>
  <AppFileDropzone
    :accept="accept"
    :multiple="multiple"
    :compact="compact"
    :disabled="disabled"
    :busy="busy"
    :progress="progress"
    :status-text="busy ? t('media.uploading') : ''"
    :cancellable="busy"
    :label="label || t('media.uploadFile')"
    :hint="hint"
    :error="errorText"
    @select="onFilesSelected"
    @cancel="cancelUpload"
  />
</template>
