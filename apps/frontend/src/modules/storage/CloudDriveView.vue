<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  cloudDriveApi,
  type DriveFile,
  type DriveSpace,
  type DriveFileVersion,
  type DriveConflict,
  type ConflictResolutionStrategy,
} from '@/shared/api/storage/drive';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useToast } from '@/shared/composables/useToast';
import { formatDateTime } from '@/shared/formatting/date';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';

const { t } = useI18n();
const toast = useToast();

const loading = ref(true);
const error = ref('');
const saving = ref(false);

const space = ref<DriveSpace | null>(null);
const files = ref<DriveFile[]>([]);
const conflicts = ref<DriveConflict[]>([]);

// Upload state
const isDragging = ref(false);
const uploadFileInput = ref<HTMLInputElement | null>(null);

// Share / Presigned link modal state
const showShareModal = ref(false);
const sharingFile = ref<DriveFile | null>(null);
const shareTtlSeconds = ref(3600);
const generatedLink = ref<{ url: string; expiresAt: string } | null>(null);
const generatingLink = ref(false);

// Version history modal state
const showVersionsModal = ref(false);
const versioningFile = ref<DriveFile | null>(null);
const fileVersions = ref<DriveFileVersion[]>([]);
const loadingVersions = ref(false);

// Rename modal state
const showRenameModal = ref(false);
const renamingFile = ref<DriveFile | null>(null);
const newFileName = ref('');

// Delete modal state
const showDeleteModal = ref(false);
const deletingFile = ref<DriveFile | null>(null);

// Conflict resolution modal state
const showConflictModal = ref(false);
const activeConflict = ref<DriveConflict | null>(null);

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`;
}

const spaceUsagePercent = computed(() => {
  if (!space.value || space.value.bytesLimit === 0) return 0;
  return Math.min(100, Math.round((space.value.bytesUsed / space.value.bytesLimit) * 100));
});

async function loadDriveData(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const [spaceData, filesData, conflictsData] = await Promise.all([
      cloudDriveApi.space(),
      cloudDriveApi.listFiles(),
      cloudDriveApi.listConflicts(),
    ]);
    space.value = spaceData;
    files.value = filesData;
    conflicts.value = conflictsData;
  } catch (caught) {
    error.value = resolveErrorMessage(caught);
  } finally {
    loading.value = false;
  }
}

async function handleFileUpload(fileList: FileList | null): Promise<void> {
  if (!fileList || fileList.length === 0) return;
  const file = fileList[0];
  if (!file) return;

  saving.value = true;
  try {
    // Compute SHA-256
    const arrayBuffer = await file.arrayBuffer();
    const hashBuffer = await crypto.subtle.digest('SHA-256', arrayBuffer);
    const hashArray = Array.from(new Uint8Array(hashBuffer));
    const sha256 = hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');

    // Request ticket
    const ticket = await cloudDriveApi.requestUploadTicket({
      fileName: file.name,
      path: `/${file.name}`,
      sizeBytes: file.size,
      mimeType: file.type || 'application/octet-stream',
      sha256,
    });

    // Add to files listing
    files.value.unshift({
      id: ticket.fileId,
      name: file.name,
      path: `/${file.name}`,
      sizeBytes: file.size,
      mimeType: file.type || 'application/octet-stream',
      sha256,
      version: 1,
      updatedAt: new Date().toISOString(),
      isDeleted: false,
    });

    if (space.value) {
      space.value.bytesUsed += file.size;
      space.value.filesCount += 1;
    }

    toast.success(t('drive.uploadSuccess'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
    if (uploadFileInput.value) uploadFileInput.value.value = '';
  }
}

function onDrop(event: DragEvent): void {
  isDragging.value = false;
  if (event.dataTransfer?.files) {
    void handleFileUpload(event.dataTransfer.files);
  }
}

function openShareModal(file: DriveFile): void {
  sharingFile.value = file;
  shareTtlSeconds.value = 3600;
  generatedLink.value = null;
  showShareModal.value = true;
  void generatePresignedLink();
}

async function generatePresignedLink(): Promise<void> {
  if (!sharingFile.value) return;
  generatingLink.value = true;
  try {
    generatedLink.value = await cloudDriveApi.generatePresignedDownloadUrl(
      sharingFile.value.id,
      shareTtlSeconds.value,
    );
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    generatingLink.value = false;
  }
}

async function copyShareLink(): Promise<void> {
  if (!generatedLink.value) return;
  try {
    await navigator.clipboard.writeText(generatedLink.value.url);
    toast.success(t('drive.linkCopied'));
  } catch {
    toast.info(t('developer.copied'));
  }
}

async function openVersionsModal(file: DriveFile): Promise<void> {
  versioningFile.value = file;
  showVersionsModal.value = true;
  loadingVersions.value = true;
  try {
    fileVersions.value = await cloudDriveApi.listVersions(file.id);
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    loadingVersions.value = false;
  }
}

async function handleRestoreVersion(versionNumber: number): Promise<void> {
  if (!versioningFile.value) return;
  saving.value = true;
  try {
    const restored = await cloudDriveApi.restoreVersion(versioningFile.value.id, versionNumber);
    const idx = files.value.findIndex((f) => f.id === versioningFile.value!.id);
    if (idx !== -1) {
      files.value[idx] = restored;
    }
    showVersionsModal.value = false;
    toast.success(t('drive.fileRestored'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

function openRenameModal(file: DriveFile): void {
  renamingFile.value = file;
  newFileName.value = file.name;
  showRenameModal.value = true;
}

async function handleRenameFile(): Promise<void> {
  if (!renamingFile.value || !newFileName.value.trim()) return;
  saving.value = true;
  try {
    const updated = await cloudDriveApi.renameFile(renamingFile.value.id, newFileName.value.trim());
    const idx = files.value.findIndex((f) => f.id === renamingFile.value!.id);
    if (idx !== -1) {
      files.value[idx] = updated;
    }
    showRenameModal.value = false;
    toast.success(t('drive.fileRenamed'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

function openDeleteModal(file: DriveFile): void {
  deletingFile.value = file;
  showDeleteModal.value = true;
}

async function handleDeleteFile(): Promise<void> {
  if (!deletingFile.value) return;
  saving.value = true;
  try {
    await cloudDriveApi.deleteFile(deletingFile.value.id);
    files.value = files.value.filter((f) => f.id !== deletingFile.value!.id);
    showDeleteModal.value = false;
    toast.success(t('drive.fileDeleted'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

function openConflictModal(conflict: DriveConflict): void {
  activeConflict.value = conflict;
  showConflictModal.value = true;
}

async function handleResolveConflict(strategy: ConflictResolutionStrategy): Promise<void> {
  if (!activeConflict.value) return;
  saving.value = true;
  try {
    await cloudDriveApi.resolveConflict(activeConflict.value.id, strategy);
    conflicts.value = conflicts.value.filter((c) => c.id !== activeConflict.value!.id);
    showConflictModal.value = false;
    toast.success(t('drive.conflictResolved'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  void loadDriveData();
});
</script>

<template>
  <div class="cloud-drive">
    <header class="cloud-drive__header">
      <div>
        <h1 class="cloud-drive__title">
          {{ $t('drive.title') }}
        </h1>
        <p class="cloud-drive__subtitle">
          {{ $t('drive.subtitle') }}
        </p>
      </div>

      <div class="cloud-drive__header-actions">
        <input
          ref="uploadFileInput"
          type="file"
          class="cloud-drive__hidden-input"
          @change="handleFileUpload(($event.target as HTMLInputElement).files)"
        />
        <AppButton
          variant="primary"
          size="m"
          :loading="saving"
          @click="uploadFileInput?.click()"
        >
          {{ $t('drive.uploadFile') }}
        </AppButton>
      </div>
    </header>

    <!-- Quota & Storage Gauge -->
    <AppCard
      v-if="space"
      class="cloud-drive__quota-card"
    >
      <div class="cloud-drive__quota-header">
        <span class="cloud-drive__quota-title">{{ $t('drive.storageSpace') }}</span>
        <span class="cloud-drive__quota-stats">
          {{
            $t('drive.usedOf', {
              used: formatBytes(space.bytesUsed),
              limit: formatBytes(space.bytesLimit),
            })
          }}
          ({{ spaceUsagePercent }}%)
        </span>
      </div>
      <div class="cloud-drive__progress-bar">
        <div
          class="cloud-drive__progress-fill"
          :style="{ width: `${spaceUsagePercent}%` }"
        />
      </div>
      <div class="cloud-drive__quota-meta">
        <span>{{ space.filesCount }} files</span>
        <span>{{ space.versionCount }} total versions</span>
      </div>
    </AppCard>

    <!-- Conflict Warning Banner -->
    <div
      v-if="conflicts.length > 0"
      class="cloud-drive__conflict-banner"
    >
      <div class="cloud-drive__conflict-info">
        <strong>⚠️ {{ $t('drive.conflicts') }} ({{ conflicts.length }})</strong>
        <span>Some files have conflicting changes from different devices.</span>
      </div>
      <AppButton
        variant="secondary"
        size="s"
        @click="openConflictModal(conflicts[0]!)"
      >
        {{ $t('drive.resolveConflict') }}
      </AppButton>
    </div>

    <!-- Drag & Drop Upload Zone -->
    <div
      class="cloud-drive__dropzone"
      :class="{ 'cloud-drive__dropzone--active': isDragging }"
      @dragover.prevent="isDragging = true"
      @dragleave.prevent="isDragging = false"
      @drop.prevent="onDrop"
      @click="uploadFileInput?.click()"
    >
      <div class="cloud-drive__dropzone-content">
        <span class="cloud-drive__dropzone-icon">☁️</span>
        <span class="cloud-drive__dropzone-text">{{ $t('drive.dropToUpload') }}</span>
      </div>
    </div>

    <AppErrorState
      v-if="error"
      :message="error"
      :action-label="$t('common.retry')"
      @action="loadDriveData"
    />

    <AppCard
      v-else-if="loading"
      class="cloud-drive__card"
    >
      <AppSkeleton height="300px" />
    </AppCard>

    <!-- Files Table -->
    <div
      v-else-if="files.length > 0"
      class="cloud-drive__table-wrapper"
    >
      <table class="cloud-drive__table">
        <thead>
          <tr>
            <th>{{ $t('drive.fileName') }}</th>
            <th>{{ $t('drive.fileSize') }}</th>
            <th>{{ $t('drive.fileVersion') }}</th>
            <th>{{ $t('drive.lastModified') }}</th>
            <th class="cloud-drive__align-right">
              {{ $t('admin.actions') }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="file in files"
            :key="file.id"
          >
            <td class="cloud-drive__file-cell">
              <span class="cloud-drive__file-icon">📄</span>
              <div class="cloud-drive__file-meta">
                <strong class="cloud-drive__file-name">{{ file.name }}</strong>
                <span class="cloud-drive__file-path">{{ file.path }}</span>
              </div>
            </td>
            <td class="cloud-drive__muted-cell">
              {{ formatBytes(file.sizeBytes) }}
            </td>
            <td>
              <AppBadge
                variant="neutral"
                size="s"
              >
                v{{ file.version }}
              </AppBadge>
            </td>
            <td class="cloud-drive__muted-cell">
              {{ formatDateTime(file.updatedAt) }}
            </td>
            <td class="cloud-drive__align-right">
              <div class="cloud-drive__btn-group">
                <AppButton
                  variant="secondary"
                  size="s"
                  @click="openShareModal(file)"
                >
                  {{ $t('drive.generateLink') }}
                </AppButton>
                <AppButton
                  variant="secondary"
                  size="s"
                  @click="openVersionsModal(file)"
                >
                  {{ $t('drive.versions') }}
                </AppButton>
                <AppButton
                  variant="secondary"
                  size="s"
                  @click="openRenameModal(file)"
                >
                  {{ $t('drive.renameFile') }}
                </AppButton>
                <AppButton
                  variant="danger"
                  size="s"
                  @click="openDeleteModal(file)"
                >
                  {{ $t('admin.delete') }}
                </AppButton>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <AppEmptyState
      v-else
      :title="$t('drive.noFiles')"
      :subtitle="$t('drive.subtitle')"
    />

    <!-- MODAL: SHARE / PRESIGNED LINK -->
    <AppDialog
      v-model="showShareModal"
      :title="$t('drive.generateLink')"
    >
      <div
        v-if="sharingFile"
        class="cloud-drive__dialog-body"
      >
        <p>
          <strong>File:</strong>
          {{ sharingFile.name }}
        </p>
        <div class="cloud-drive__field">
          <label class="cloud-drive__field-label">{{ $t('drive.linkTtl') }}</label>
          <select
            v-model="shareTtlSeconds"
            class="cloud-drive__select"
            @change="generatePresignedLink"
          >
            <option :value="900">15 minutes</option>
            <option :value="3600">1 hour</option>
            <option :value="86400">24 hours</option>
            <option :value="604800">7 days</option>
          </select>
        </div>

        <div
          v-if="generatedLink"
          class="cloud-drive__link-box"
        >
          <code class="cloud-drive__link-code">{{ generatedLink.url }}</code>
          <AppButton
            variant="primary"
            size="s"
            @click="copyShareLink"
          >
            {{ $t('drive.copyLink') }}
          </AppButton>
        </div>

        <div class="cloud-drive__form-actions">
          <AppButton
            variant="secondary"
            @click="showShareModal = false"
          >
            {{ $t('common.close') }}
          </AppButton>
        </div>
      </div>
    </AppDialog>

    <!-- MODAL: VERSION HISTORY -->
    <AppDialog
      v-model="showVersionsModal"
      :title="$t('drive.versions')"
      size="l"
    >
      <div
        v-if="versioningFile"
        class="cloud-drive__dialog-body"
      >
        <p>
          <strong>{{ versioningFile.name }}</strong>
          (current version: v{{ versioningFile.version }})
        </p>
        <AppSkeleton
          v-if="loadingVersions"
          height="150px"
        />
        <div
          v-else-if="fileVersions.length > 0"
          class="cloud-drive__versions-list"
        >
          <div
            v-for="ver in fileVersions"
            :key="ver.id"
            class="cloud-drive__version-item"
          >
            <div class="cloud-drive__version-info">
              <strong>v{{ ver.versionNumber }}</strong>
              <span class="cloud-drive__muted-cell">{{ formatBytes(ver.sizeBytes) }}</span>
              <span class="cloud-drive__muted-cell">{{ formatDateTime(ver.createdAt) }}</span>
              <code class="cloud-drive__version-sha">{{ ver.sha256.slice(0, 12) }}…</code>
            </div>
            <AppButton
              v-if="ver.versionNumber !== versioningFile.version"
              variant="secondary"
              size="s"
              :loading="saving"
              @click="handleRestoreVersion(ver.versionNumber)"
            >
              {{ $t('drive.restoreVersion') }}
            </AppButton>
          </div>
        </div>
        <p
          v-else
          class="cloud-drive__muted-cell"
        >
          {{ $t('drive.noVersions') }}
        </p>

        <div class="cloud-drive__form-actions">
          <AppButton
            variant="secondary"
            @click="showVersionsModal = false"
          >
            {{ $t('common.close') }}
          </AppButton>
        </div>
      </div>
    </AppDialog>

    <!-- MODAL: RENAME FILE -->
    <AppDialog
      v-model="showRenameModal"
      :title="$t('drive.renameFile')"
    >
      <form
        class="cloud-drive__dialog-body"
        @submit.prevent="handleRenameFile"
      >
        <AppInput
          id="drive-rename-name"
          v-model="newFileName"
          :label="$t('drive.fileName')"
          required
        />
        <div class="cloud-drive__form-actions">
          <AppButton
            variant="secondary"
            @click="showRenameModal = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton
            variant="primary"
            type="submit"
            :loading="saving"
            :disabled="!newFileName.trim()"
          >
            {{ $t('common.save') }}
          </AppButton>
        </div>
      </form>
    </AppDialog>

    <!-- MODAL: DELETE FILE -->
    <AppDialog
      v-model="showDeleteModal"
      :title="$t('admin.delete')"
    >
      <div
        v-if="deletingFile"
        class="cloud-drive__dialog-body"
      >
        <p class="cloud-drive__danger-text">
          {{ $t('drive.deleteConfirm') }}
        </p>
        <p>
          <strong>File:</strong>
          {{ deletingFile.name }}
        </p>
        <div class="cloud-drive__form-actions">
          <AppButton
            variant="secondary"
            @click="showDeleteModal = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton
            variant="danger"
            :loading="saving"
            @click="handleDeleteFile"
          >
            {{ $t('admin.delete') }}
          </AppButton>
        </div>
      </div>
    </AppDialog>

    <!-- MODAL: RESOLVE CONFLICT -->
    <AppDialog
      v-model="showConflictModal"
      :title="$t('drive.resolveConflict')"
    >
      <div
        v-if="activeConflict"
        class="cloud-drive__dialog-body"
      >
        <p>
          <strong>Conflict on:</strong>
          {{ activeConflict.fileName }}
        </p>
        <p>
          Server version: v{{ activeConflict.serverVersion }} | Client version: v{{
            activeConflict.clientVersion
          }}
        </p>
        <div class="cloud-drive__conflict-actions">
          <AppButton
            variant="secondary"
            :loading="saving"
            @click="handleResolveConflict('keep-mine')"
          >
            {{ $t('drive.keepMine') }}
          </AppButton>
          <AppButton
            variant="secondary"
            :loading="saving"
            @click="handleResolveConflict('keep-theirs')"
          >
            {{ $t('drive.keepTheirs') }}
          </AppButton>
          <AppButton
            variant="primary"
            :loading="saving"
            @click="handleResolveConflict('keep-both')"
          >
            {{ $t('drive.keepBoth') }}
          </AppButton>
        </div>
      </div>
    </AppDialog>
  </div>
</template>

<style scoped>
.cloud-drive {
  display: grid;
  gap: var(--space-20);
}

.cloud-drive__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  flex-wrap: wrap;
  gap: var(--space-16);
  border-bottom: 1px solid var(--border);
  padding-bottom: var(--space-16);
}

.cloud-drive__title {
  color: var(--text-1);
  margin: 0;
}

.cloud-drive__subtitle {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-2);
  margin: var(--space-4) 0 0 0;
}

.cloud-drive__hidden-input {
  display: none;
}

/* Quota */
.cloud-drive__quota-card {
  display: grid;
  gap: var(--space-8);
  padding: var(--space-16);
}

.cloud-drive__quota-header {
  display: flex;
  justify-content: space-between;
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.cloud-drive__quota-title {
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-1);
}

.cloud-drive__quota-stats {
  color: var(--text-2);
}

.cloud-drive__progress-bar {
  width: 100%;
  height: 8px;
  background: var(--surface-2);
  border-radius: var(--radius-control-s);
  overflow: hidden;
}

.cloud-drive__progress-fill {
  height: 100%;
  background: var(--accent);
  border-radius: var(--radius-control-s);
  transition: var(--theme-transition);
}

.cloud-drive__quota-meta {
  display: flex;
  justify-content: space-between;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
}

/* Conflict Banner */
.cloud-drive__conflict-banner {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: var(--space-12) var(--space-16);
  background: var(--warning-soft);
  border: 1px solid var(--warning);
  border-radius: var(--radius-card-m);
  gap: var(--space-12);
}

.cloud-drive__conflict-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

/* Dropzone */
.cloud-drive__dropzone {
  border: 2px dashed var(--border);
  border-radius: var(--radius-card-m);
  padding: var(--space-24);
  text-align: center;
  cursor: pointer;
  background: var(--surface-2);
  transition: var(--theme-transition);
}

.cloud-drive__dropzone:hover,
.cloud-drive__dropzone--active {
  border-color: var(--accent);
  background: var(--surface-hover);
}

.cloud-drive__dropzone-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: var(--space-8);
}

.cloud-drive__dropzone-icon {
  font-size: var(--type-display-size);
  line-height: var(--type-display-line);
  font-weight: var(--type-display-weight);
}

.cloud-drive__dropzone-text {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  color: var(--text-2);
}

/* Table */
.cloud-drive__table-wrapper {
  overflow-x: auto;
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
  background: var(--surface);
}

.cloud-drive__table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
}

.cloud-drive__table th {
  padding: var(--space-12) var(--space-16);
  background: var(--surface-2);
  border-bottom: 1px solid var(--border);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
  text-transform: uppercase;
}

.cloud-drive__table td {
  padding: var(--space-12) var(--space-16);
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.cloud-drive__table tr:last-child td {
  border-bottom: none;
}

.cloud-drive__file-cell {
  display: flex;
  align-items: center;
  gap: var(--space-12);
}

.cloud-drive__file-icon {
  font-size: var(--type-metric-number-size);
  line-height: var(--type-metric-number-line);
  font-weight: var(--type-metric-number-weight);
}

.cloud-drive__file-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.cloud-drive__file-name {
  color: var(--text-1);
}

.cloud-drive__file-path {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
}

.cloud-drive__muted-cell {
  color: var(--text-2);
}

.cloud-drive__align-right {
  text-align: right;
}

.cloud-drive__btn-group {
  display: inline-flex;
  gap: var(--space-6);
  justify-content: flex-end;
}

/* Dialogs */
.cloud-drive__dialog-body {
  display: grid;
  gap: var(--space-16);
  padding: var(--space-16) 0;
}

.cloud-drive__field {
  display: grid;
  gap: var(--space-4);
}

.cloud-drive__field-label {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-1);
}

.cloud-drive__select {
  min-height: 40px;
  padding: 0 var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  color: var(--text-1);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
}

.cloud-drive__link-box {
  display: flex;
  gap: var(--space-8);
  align-items: center;
  background: var(--surface-2);
  padding: var(--space-12);
  border-radius: var(--radius-control-m);
}

.cloud-drive__link-code {
  flex: 1;
  font-family: monospace;
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  word-break: break-all;
  color: var(--accent);
}

.cloud-drive__danger-text {
  color: var(--danger);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  margin: 0;
}

.cloud-drive__versions-list {
  display: grid;
  gap: var(--space-8);
}

.cloud-drive__version-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: var(--space-8) var(--space-12);
  background: var(--surface-2);
  border-radius: var(--radius-control-s);
}

.cloud-drive__version-info {
  display: flex;
  gap: var(--space-12);
  align-items: center;
}

.cloud-drive__version-sha {
  font-family: monospace;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}

.cloud-drive__conflict-actions {
  display: flex;
  gap: var(--space-12);
  justify-content: flex-end;
}

.cloud-drive__form-actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-12);
  margin-top: var(--space-8);
}
</style>
