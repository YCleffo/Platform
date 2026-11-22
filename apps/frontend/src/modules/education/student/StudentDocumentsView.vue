<script setup lang="ts">
import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import {
  storageApi,
  storageVersionConflict,
  type StorageSpace,
  type StorageFile,
  type FileVersion,
  type FileConflict,
} from './storage';
import { studentSession } from './session';
import type { TransferProgress } from '../fileTransfer';
import { useEducationError } from '../resources';
import { useEducationI18n } from '../i18n';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import EducationState from '../EducationState.vue';
import type { Page } from '../api';
import { ApiError } from '@/shared/api/errors';

const { t, locale } = useEducationI18n(),
  issue = useEducationError();
const org = computed(() => studentSession.organizationId);
const data = shallowRef<{
  space: StorageSpace;
  files: StorageFile[];
  conflicts: Page<FileConflict>;
} | null>(null);
const loading = ref(false),
  error = shallowRef<unknown>(null),
  uploadBusy = ref(false),
  uploadError = ref(''),
  actionMessage = ref('');
const transferProgress = ref<TransferProgress | null>(null);
const failedUpload = shallowRef<{ file: File; path: string; review: boolean } | null>(null);
const downloading = ref<string | null>(null);
const downloadProgress = ref<TransferProgress | null>(null);
const folder = ref(''),
  uploadFolder = ref(''),
  offset = ref(0),
  conflictOffset = ref(0);
const versionFile = ref<StorageFile | null>(null),
  fileVersions = ref<FileVersion[]>([]),
  versionLoading = ref(false),
  versionError = ref('');
const renameTarget = ref<StorageFile | null>(null),
  newPath = ref('');
let active = true,
  generation = 0,
  versionGeneration = 0;
let controller: AbortController | null = null,
  transferController: AbortController | null = null,
  downloadController: AbortController | null = null;
function context() {
  const id = org.value,
    epoch = coreAdminSession.epoch(),
    revision = generation;
  return {
    org: id,
    current: () =>
      active && id === org.value && epoch === coreAdminSession.epoch() && revision === generation,
  };
}
async function refresh(): Promise<void> {
  controller?.abort();
  const request = new AbortController();
  controller = request;
  const ctx = context();
  data.value = null;
  error.value = null;
  loading.value = !!ctx.org && !!coreAdminSession.accessToken();
  if (!ctx.org || !coreAdminSession.accessToken()) return;
  try {
    const [space, files, conflicts] = await Promise.all([
      storageApi.space(ctx.org, request.signal),
      storageApi.allFiles(ctx.org, request.signal),
      storageApi.conflicts(ctx.org, conflictOffset.value, request.signal),
    ]);
    if (ctx.current() && !request.signal.aborted) data.value = { space, files, conflicts };
  } catch (failure) {
    if (ctx.current() && !request.signal.aborted) error.value = failure;
  } finally {
    if (ctx.current() && !request.signal.aborted) loading.value = false;
  }
}
watch(
  [org, coreAdminSessionEpoch],
  () => {
    generation++;
    versionGeneration++;
    controller?.abort();
    transferController?.abort();
    downloadController?.abort();
    transferController = null;
    downloadController = null;
    transferProgress.value = null;
    failedUpload.value = null;
    downloading.value = null;
    downloadProgress.value = null;
    data.value = null;
    versionFile.value = null;
    renameTarget.value = null;
    fileVersions.value = [];
    uploadBusy.value = false;
    versionLoading.value = false;
    folder.value = '';
    uploadFolder.value = '';
    offset.value = 0;
    conflictOffset.value = 0;
    uploadError.value = '';
    versionError.value = '';
    actionMessage.value = '';
    void refresh();
  },
  { immediate: true, flush: 'sync' },
);
watch(conflictOffset, () => {
  void refresh();
});
watch(folder, () => {
  offset.value = 0;
  uploadFolder.value = folder.value;
});
onScopeDispose(() => {
  active = false;
  controller?.abort();
  transferController?.abort();
  downloadController?.abort();
});
const quotaPercent = computed(() =>
  data.value?.space.maxBytes
    ? Math.min(100, Math.round((data.value.space.totalBytes / data.value.space.maxBytes) * 100))
    : 0,
);
const folders = computed(() => {
  const paths = new Set<string>();
  for (const file of data.value?.files ?? []) {
    const parts = file.path.split('/');
    parts.pop();
    while (parts.length) {
      paths.add(parts.join('/'));
      parts.pop();
    }
  }
  return [...paths].sort();
});
const childFolders = computed(() =>
  folders.value.filter(
    (path) => (path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '') === folder.value,
  ),
);
const matchingFiles = computed(() =>
  (data.value?.files ?? []).filter(
    (file) =>
      (file.path.includes('/') ? file.path.slice(0, file.path.lastIndexOf('/')) : '') ===
      folder.value,
  ),
);
const visibleFiles = computed(() => matchingFiles.value.slice(offset.value, offset.value + 50));
const conflicts = computed(() =>
  (data.value?.conflicts.items ?? []).filter((conflict) => !conflict.resolvedAt),
);
const parentFolder = computed(() =>
  folder.value.includes('/') ? folder.value.slice(0, folder.value.lastIndexOf('/')) : '',
);
async function openVersions(file: StorageFile): Promise<void> {
  const ctx = context(),
    request = ++versionGeneration;
  versionFile.value = file;
  fileVersions.value = [];
  versionLoading.value = true;
  versionError.value = '';
  try {
    const result = await storageApi.fileVersions(ctx.org, file.path);
    if (ctx.current() && request === versionGeneration) fileVersions.value = result.items;
  } catch (failure) {
    if (ctx.current() && request === versionGeneration) versionError.value = issue(failure);
  } finally {
    if (ctx.current() && request === versionGeneration) versionLoading.value = false;
  }
}
function closeVersions(): void {
  versionGeneration++;
  versionFile.value = null;
  fileVersions.value = [];
}
async function restoreVersion(file: StorageFile, version: number): Promise<void> {
  if (versionLoading.value || !window.confirm(t('education.restoreConfirm'))) return;
  const ctx = context();
  versionLoading.value = true;
  versionError.value = '';
  try {
    await storageApi.restoreFile(ctx.org, file.path, version);
    if (ctx.current()) {
      closeVersions();
      actionMessage.value = t('education.versionRestored');
      await refresh();
    }
  } catch (failure) {
    if (ctx.current()) versionError.value = issue(failure);
  } finally {
    if (ctx.current()) versionLoading.value = false;
  }
}
async function action(
  task: (id: string) => Promise<unknown>,
  success: string,
  accept = () => undefined,
): Promise<void> {
  if (uploadBusy.value) return;
  const ctx = context();
  uploadBusy.value = true;
  uploadError.value = '';
  actionMessage.value = '';
  try {
    await task(ctx.org);
    if (ctx.current()) {
      accept();
      actionMessage.value = success;
      await refresh();
    }
  } catch (failure) {
    if (ctx.current()) uploadError.value = issue(failure);
  } finally {
    if (ctx.current()) uploadBusy.value = false;
  }
}
async function deleteFile(file: StorageFile): Promise<void> {
  if (window.confirm(t('education.confirmDelete')))
    await action((id) => storageApi.deleteFile(id, file.path), t('education.fileDeleted'));
}
async function renameFile(): Promise<void> {
  const target = renameTarget.value;
  if (!target || !newPath.value.trim()) return;
  await action(
    (id) => storageApi.renameFile(id, target.path, newPath.value.trim()),
    t('education.fileRenamed'),
    () => {
      renameTarget.value = null;
    },
  );
}
async function resolveConflict(conflict: FileConflict): Promise<void> {
  if (window.confirm(t('education.keepServerConfirm')))
    await action(
      (id) => storageApi.resolveConflict(id, conflict.id),
      t('education.conflictResolved'),
    );
}
async function upload(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement,
    file = input.files?.[0];
  input.value = '';
  if (!file || uploadBusy.value || downloading.value !== null) return;
  const path = [uploadFolder.value.trim().replace(/^\/+|\/+$/g, ''), file.name]
    .filter(Boolean)
    .join('/');
  if (
    data.value?.files.some((item) => item.path === path) &&
    !window.confirm(t('education.overwriteFileConfirm'))
  )
    return;
  await uploadFile(file, path);
}
async function retryUpload(): Promise<void> {
  const pending = failedUpload.value;
  if (!pending) return;
  if (pending.review && (!data.value || !window.confirm(t('education.retryOverwriteConfirm'))))
    return;
  await uploadFile(pending.file, pending.path);
}
async function uploadFile(file: File, path: string): Promise<void> {
  if (uploadBusy.value || downloading.value !== null) return;
  const ctx = context();
  transferController?.abort();
  const request = new AbortController();
  transferController = request;
  uploadBusy.value = true;
  failedUpload.value = null;
  uploadError.value = '';
  actionMessage.value = '';
  try {
    transferProgress.value = { loaded: 0, total: file.size, phase: 'hashing' };
    await storageApi.uploadFile(ctx.org, path, file, {
      signal: request.signal,
      progress: (value) => {
        if (ctx.current() && !request.signal.aborted) transferProgress.value = value;
      },
    });
    if (ctx.current()) {
      actionMessage.value = t('education.uploaded');
      await refresh();
    }
  } catch (failure) {
    if (!ctx.current()) return;
    if (request.signal.aborted) {
      actionMessage.value = t('education.uploadRequestStopped');
      await refresh();
    } else {
      uploadError.value = issue(failure);
      const review = storageVersionConflict(failure);
      if (!(failure instanceof ApiError && (failure.status === 401 || failure.status === 403))) {
        failedUpload.value = { file, path, review };
      }
      if (review) await refresh();
    }
  } finally {
    if (transferController === request) {
      transferController = null;
      transferProgress.value = null;
    }
    if (ctx.current()) uploadBusy.value = false;
  }
}
async function download(file: StorageFile): Promise<void> {
  if (uploadBusy.value || downloadController) return;
  const ctx = context();
  const request = new AbortController();
  downloadController = request;
  downloading.value = file.path;
  downloadProgress.value = { loaded: 0, total: file.sizeBytes ?? 0, phase: 'downloading' };
  uploadError.value = '';
  actionMessage.value = '';
  try {
    const outcome = await storageApi.downloadFile(ctx.org, file.path, request.signal, (value) => {
      if (ctx.current() && !request.signal.aborted) downloadProgress.value = value;
    });
    if (ctx.current()) {
      actionMessage.value = t(
        outcome === 'verified' ? 'education.downloadVerified' : 'education.downloadInBrowser',
      );
    }
  } catch (failure) {
    if (!ctx.current()) return;
    if (request.signal.aborted) actionMessage.value = t('education.downloadStopped');
    else uploadError.value = issue(failure);
  } finally {
    if (downloadController === request) {
      downloadController = null;
      downloading.value = null;
      downloadProgress.value = null;
    }
  }
}
function formatBytes(value: number): string {
  return value < 1024
    ? `${value} B`
    : value < 1024 * 1024
      ? `${Math.round(value / 1024)} KB`
      : `${Math.round(value / 1024 / 1024)} MB`;
}
function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleString(locale.value) : '—';
}
</script>

<template>
  <section class="edu-documents-view">
    <header class="edu-page-title">
      <h2>{{ t('education.documents') }}</h2>
      <button
        type="button"
        class="edu-button"
        :disabled="loading || uploadBusy"
        @click="refresh"
      >
        {{ t('education.refresh') }}
      </button>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <p
      v-if="uploadError"
      class="edu-error"
      role="alert"
    >
      {{ uploadError }}
    </p>
    <button
      v-if="failedUpload"
      type="button"
      class="edu-button"
      :disabled="uploadBusy || downloading !== null || (failedUpload.review && !data)"
      @click="retryUpload"
    >
      {{ t('education.retryUpload', { file: failedUpload.path }) }}
    </button>
    <p
      v-if="actionMessage"
      class="edu-success-banner"
      role="status"
    >
      {{ actionMessage }}
    </p>
    <template v-if="data">
      <div class="edu-quota-card edu-card">
        <div class="edu-quota-info">
          <span>
            {{
              t('education.storageUsed', {
                used: formatBytes(data.space.totalBytes),
                total: formatBytes(data.space.maxBytes),
              })
            }}
          </span>
          <strong>{{ quotaPercent }}%</strong>
        </div>
        <progress
          :value="data.space.totalBytes"
          :max="Math.max(1, data.space.maxBytes)"
          :aria-label="t('education.documents')"
        />
        <p class="edu-subtle">
          {{ t('education.fileCount', { used: data.space.fileCount, total: data.space.maxFiles }) }}
        </p>
        <p
          v-if="quotaPercent >= 85"
          class="edu-error"
        >
          {{ t('education.quotaWarning') }}
        </p>
      </div>
      <div class="edu-toolbar">
        <AppDropdown
          id="documents-folder"
          v-model="folder"
          :label="t('education.folder')"
          :options="[
            { value: '', label: t('education.rootFolder') },
            ...folders.map((path) => ({ value: path, label: path })),
          ]"
        />
        <AppInput
          id="upload-folder"
          v-model="uploadFolder"
          :label="t('education.uploadFolderHint')"
          maxlength="400"
        />
        <label class="edu-button edu-button--primary">
          {{ uploadBusy ? t('education.loading') : t('education.uploadFile') }}
          <input
            type="file"
            class="edu-visually-hidden"
            :disabled="uploadBusy || downloading !== null"
            @change="upload"
          />
        </label>
        <p>{{ t('education.uploadReselectHint') }}</p>
        <div
          v-if="uploadBusy && transferProgress"
          class="edu-transfer-progress"
          role="status"
        >
          <label for="document-transfer-progress">
            {{ t(`education.transfer_${transferProgress.phase}`) }}
          </label>
          <progress
            id="document-transfer-progress"
            :value="transferProgress.loaded"
            :max="transferProgress.total || 1"
          ></progress>
          <span>
            {{ formatBytes(transferProgress.loaded) }} / {{ formatBytes(transferProgress.total) }}
          </span>
          <button
            v-if="transferProgress.phase !== 'verifying'"
            class="edu-button"
            type="button"
            @click="transferController?.abort()"
          >
            {{ t('education.cancelTransfer') }}
          </button>
        </div>
        <div
          v-if="downloading !== null && downloadProgress"
          class="edu-transfer-progress"
          role="status"
        >
          <label for="document-download-progress">{{ t('education.transfer_downloading') }}</label>
          <progress
            id="document-download-progress"
            :value="downloadProgress.loaded"
            :max="downloadProgress.total || 1"
          ></progress>
          <span>
            {{ formatBytes(downloadProgress.loaded) }} / {{ formatBytes(downloadProgress.total) }}
          </span>
          <button
            class="edu-button"
            type="button"
            @click="downloadController?.abort()"
          >
            {{ t('education.cancelTransfer') }}
          </button>
        </div>
      </div>
      <button
        v-if="folder"
        type="button"
        class="edu-button"
        @click="folder = parentFolder"
      >
        {{ t('education.back') }}
      </button>
      <ul
        v-if="childFolders.length"
        class="edu-list"
      >
        <li
          v-for="path in childFolders"
          :key="path"
          class="edu-list__item"
        >
          <button
            type="button"
            class="edu-button"
            @click="folder = path"
          >
            {{ t('education.folder') }}: {{ path.split('/').pop() }}
          </button>
        </li>
      </ul>
      <EducationState
        v-if="!matchingFiles.length && !childFolders.length"
        :empty="t('education.storageEmpty')"
      />
      <ul class="edu-list">
        <li
          v-for="file in visibleFiles"
          :key="file.id"
          class="edu-list__item"
        >
          <div class="edu-list__main">
            <strong>{{ file.path.split('/').pop() }}</strong>
            <p class="edu-subtle">
              v{{ file.version ?? 1 }} · {{ formatBytes(file.sizeBytes ?? 0) }} ·
              {{ formatDate(file.createdAt) }}
            </p>
          </div>
          <div class="edu-form-actions">
            <button
              type="button"
              class="edu-button edu-button--small"
              :disabled="uploadBusy || downloading !== null"
              @click="download(file)"
            >
              {{ t(downloading === file.path ? 'education.loading' : 'education.download') }}
            </button>
            <button
              type="button"
              class="edu-button edu-button--small"
              :disabled="uploadBusy"
              @click="openVersions(file)"
            >
              {{ t('education.fileVersions') }}
            </button>
            <button
              type="button"
              class="edu-button edu-button--small"
              :disabled="uploadBusy"
              @click="
                renameTarget = file;
                newPath = file.path;
              "
            >
              {{ t('education.renameFile') }}
            </button>
            <button
              type="button"
              class="edu-button edu-button--small"
              :disabled="uploadBusy"
              @click="deleteFile(file)"
            >
              {{ t('education.deleteFile') }}
            </button>
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
          v-if="offset + 50 < matchingFiles.length"
          type="button"
          class="edu-button"
          @click="offset += 50"
        >
          {{ t('education.loadMore') }}
        </button>
      </div>
      <section
        v-if="conflicts.length || conflictOffset || data.conflicts.nextOffset !== null"
        class="edu-card"
      >
        <h3>{{ t('education.storageConflicts') }}</h3>
        <p class="edu-subtle">{{ t('education.conflictHint') }}</p>
        <ul class="edu-list">
          <li
            v-for="conflict in conflicts"
            :key="conflict.id"
            class="edu-list__item"
          >
            <div>
              <strong>{{ conflict.path }}</strong>
              <p class="edu-subtle">
                {{ t('education.versionNumber', { version: conflict.serverVersion ?? '—' }) }} ·
                {{ formatDate(conflict.createdAt) }}
              </p>
            </div>
            <button
              type="button"
              class="edu-button"
              :disabled="uploadBusy"
              @click="resolveConflict(conflict)"
            >
              {{ t('education.keepServer') }}
            </button>
          </li>
        </ul>
        <div class="edu-form-actions">
          <button
            v-if="conflictOffset"
            type="button"
            class="edu-button"
            @click="conflictOffset = Math.max(0, conflictOffset - 50)"
          >
            {{ t('education.back') }}
          </button>
          <button
            v-if="data.conflicts.nextOffset !== null"
            type="button"
            class="edu-button"
            @click="conflictOffset = data.conflicts.nextOffset ?? 0"
          >
            {{ t('education.loadMore') }}
          </button>
        </div>
      </section>
    </template>
    <div
      v-if="renameTarget"
      class="edu-modal-backdrop"
      @click.self="renameTarget = null"
    >
      <form
        class="edu-modal edu-card"
        role="dialog"
        aria-modal="true"
        :aria-label="t('education.renameFile')"
        @submit.prevent="renameFile"
      >
        <h3>{{ t('education.renameFile') }}</h3>
        <AppInput
          id="rename-file-path"
          v-model="newPath"
          :label="t('education.newFilePath')"
          maxlength="500"
          required
        />
        <div class="edu-form-actions">
          <button
            type="submit"
            class="edu-button edu-button--primary"
            :disabled="uploadBusy"
          >
            {{ t('education.save') }}
          </button>
          <button
            type="button"
            class="edu-button"
            :disabled="uploadBusy"
            @click="renameTarget = null"
          >
            {{ t('education.cancel') }}
          </button>
        </div>
      </form>
    </div>
    <div
      v-if="versionFile"
      class="edu-modal-backdrop"
      @click.self="closeVersions"
    >
      <div
        class="edu-modal edu-card"
        role="dialog"
        aria-modal="true"
        :aria-label="t('education.fileVersions')"
      >
        <h3>{{ t('education.fileVersions') }}: {{ versionFile.path }}</h3>
        <p
          v-if="versionLoading"
          class="edu-subtle"
        >
          {{ t('education.loading') }}
        </p>
        <p
          v-if="versionError"
          class="edu-error"
          role="alert"
        >
          {{ versionError }}
        </p>
        <ul class="edu-list">
          <li
            v-for="version in fileVersions"
            :key="version.id"
            class="edu-list__item"
          >
            <div>
              <strong>{{ t('education.versionNumber', { version: version.version }) }}</strong>
              <p class="edu-subtle">
                {{ formatBytes(version.sizeBytes) }} · {{ formatDate(version.createdAt) }}
              </p>
            </div>
            <button
              type="button"
              class="edu-button"
              :disabled="versionLoading || uploadBusy || version.version === versionFile.version"
              @click="restoreVersion(versionFile, version.version)"
            >
              {{
                t(
                  version.version === versionFile.version
                    ? 'education.currentVersion'
                    : 'education.restoreVersion',
                )
              }}
            </button>
          </li>
        </ul>
        <button
          type="button"
          class="edu-button"
          @click="closeVersions"
        >
          {{ t('education.back') }}
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.edu-documents-view {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}
.edu-quota-card {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.875rem;
}
.edu-quota-info,
.edu-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: end;
  flex-wrap: wrap;
  gap: 0.75rem;
}
progress {
  width: 100%;
  accent-color: var(--accent);
}
.edu-success-banner {
  color: var(--text-1);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 0.5rem 1rem;
}
.edu-button--small {
  padding: 0.25rem 0.5rem;
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-emphasis-weight);
}
.edu-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgb(0 0 0 / 40%);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}
.edu-modal {
  width: calc(100% - 2rem);
  max-width: 600px;
  max-height: 85vh;
  overflow-y: auto;
}
.edu-list__main,
strong {
  overflow-wrap: anywhere;
}
</style>
