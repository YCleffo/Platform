<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { computeBlobSha256, mediaApi, type MediaAsset } from '@/shared/api/mediaApi';
import { workspaceApi } from '@/shared/api/workspaceApi';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useToast } from '@/shared/composables/useToast';
import { formatDateTime } from '@/shared/formatting/date';
import {
  useOperationsCenter,
  type UploadTrackerHandle,
} from '@/modules/workspace/useOperationsCenter';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import AppFileDropzone from '@/shared/ui/fields/AppFileDropzone.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppContextMenu from '@/shared/ui/menus/AppContextMenu.vue';
import type { MenuItem } from '@/shared/ui/menus/types';
import MediaPreviewDialog from '@/shared/ui/media/MediaPreviewDialog.vue';
import type { MediaKind } from '@/shared/ui/media/types';

const props = defineProps<{
  organizationId?: string;
}>();

type SortField = 'name' | 'size' | 'date';
type SortDir = 'asc' | 'desc';
type KindFilter = 'all' | 'image' | 'video' | 'audio' | 'document';

const { t } = useI18n();
const toast = useToast();
const operations = useOperationsCenter();

const loading = ref(true);
const error = ref('');
const saving = ref(false);
const assets = ref<MediaAsset[]>([]);

// Navigation, search, filter, sort
const currentFolder = ref('');
const createdFolders = ref<string[]>([]);
const searchQuery = ref('');
const kindFilter = ref<KindFilter>('all');
const sortField = ref<SortField>('date');
const sortDir = ref<SortDir>('desc');

// Multi-selection
const selectedIds = ref<string[]>([]);

// Upload state
const uploadProgress = ref<number | null>(null);
let uploadController: AbortController | null = null;
let currentUploadHandle: UploadTrackerHandle | null = null;

// Modals
const showNewFolderModal = ref(false);
const newFolderName = ref('');

const showRenameModal = ref(false);
const renamingAsset = ref<MediaAsset | null>(null);
const newFileName = ref('');

const showMoveModal = ref(false);
const movingAssets = ref<MediaAsset[]>([]);
const targetMoveFolder = ref('');

const showInfoModal = ref(false);
const infoAsset = ref<MediaAsset | null>(null);

const showDeleteModal = ref(false);
const deletingAssets = ref<MediaAsset[]>([]);

const showPreviewModal = ref(false);
const previewingAsset = ref<MediaAsset | null>(null);
const previewingUrl = ref('');

// Context menu
const contextTarget = ref<MediaAsset | null>(null);

const activeOrgId = computed(() => props.organizationId || operations.organizationId || '');

function getAssetFolder(asset: MediaAsset): string {
  const raw = asset.metadata?.folderPath;
  return typeof raw === 'string' ? raw.replace(/^\/+|\/+$/g, '') : '';
}

function inferKind(file: File): MediaKind {
  const mime = (file.type || '').toLowerCase();
  if (mime.startsWith('image/')) return 'image';
  if (mime.startsWith('video/')) return 'video';
  if (mime.startsWith('audio/')) return 'audio';
  return 'document';
}

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`;
}

const breadcrumbs = computed(() => {
  if (!currentFolder.value) return [];
  const parts = currentFolder.value.split('/').filter(Boolean);
  return parts.map((name, idx) => ({
    name,
    path: parts.slice(0, idx + 1).join('/'),
  }));
});

const allKnownFolders = computed<string[]>(() => {
  const set = new Set<string>();
  const addAncestors = (folder: string) => {
    const parts = folder.split('/').filter(Boolean);
    for (let i = 1; i <= parts.length; i += 1) {
      set.add(parts.slice(0, i).join('/'));
    }
  };
  for (const f of createdFolders.value) addAncestors(f);
  for (const asset of assets.value) {
    const f = getAssetFolder(asset);
    if (f) addAncestors(f);
  }
  return Array.from(set).sort();
});

const childFolders = computed<Array<{ name: string; path: string }>>(() => {
  const prefix = currentFolder.value ? `${currentFolder.value}/` : '';
  const needle = searchQuery.value.trim().toLowerCase();
  const seen = new Set<string>();
  const result: Array<{ name: string; path: string }> = [];

  for (const fullPath of allKnownFolders.value) {
    if (!fullPath.startsWith(prefix)) continue;
    const rest = fullPath.slice(prefix.length);
    if (!rest || rest.includes('/')) continue;
    if (needle && !rest.toLowerCase().includes(needle)) continue;
    if (!seen.has(fullPath)) {
      seen.add(fullPath);
      result.push({ name: rest, path: fullPath });
    }
  }
  return result.sort((a, b) => a.name.localeCompare(b.name));
});

const visibleAssets = computed<MediaAsset[]>(() => {
  const needle = searchQuery.value.trim().toLowerCase();
  const filtered = assets.value.filter((asset) => {
    if (!needle && getAssetFolder(asset) !== currentFolder.value) {
      return false;
    }
    if (needle && !asset.originalName.toLowerCase().includes(needle)) {
      return false;
    }
    if (kindFilter.value !== 'all') {
      const k = asset.kind;
      if (kindFilter.value === 'audio' && k !== 'audio' && k !== 'voice') return false;
      if (kindFilter.value === 'video' && k !== 'video' && k !== 'video_note') return false;
      if (kindFilter.value !== 'audio' && kindFilter.value !== 'video' && k !== kindFilter.value) {
        return false;
      }
    }
    return true;
  });

  const dir = sortDir.value === 'asc' ? 1 : -1;
  return [...filtered].sort((a, b) => {
    if (sortField.value === 'name') {
      return a.originalName.localeCompare(b.originalName) * dir;
    }
    if (sortField.value === 'size') {
      return (a.sizeBytes - b.sizeBytes) * dir;
    }
    const ta = Date.parse(a.updatedAt ?? a.createdAt) || 0;
    const tb = Date.parse(b.updatedAt ?? b.createdAt) || 0;
    return (ta - tb) * dir;
  });
});

const allVisibleSelected = computed(
  () =>
    visibleAssets.value.length > 0 &&
    visibleAssets.value.every((a) => selectedIds.value.includes(a.id)),
);

const totalBytes = computed(() => assets.value.reduce((sum, a) => sum + (a.sizeBytes || 0), 0));

const contextMenuItems = computed<MenuItem[]>(() => [
  { id: 'preview', label: t('media.preview') },
  { id: 'download', label: t('drive.download') },
  { id: 'info', label: t('drive.fileInfo') },
  { id: 'sep-1', label: '', kind: 'separator' },
  { id: 'rename', label: t('drive.renameFile') },
  { id: 'move', label: t('drive.moveFile') },
  { id: 'copy', label: t('drive.copyFile') },
  { id: 'sep-2', label: '', kind: 'separator' },
  { id: 'delete', label: t('drive.deleteSelected') },
]);

const kindOptions = computed(() => [
  { value: 'all', label: t('drive.filterAll') },
  { value: 'document', label: t('drive.filterDocuments') },
  { value: 'image', label: t('drive.filterImages') },
  { value: 'video', label: t('drive.filterVideos') },
  { value: 'audio', label: t('drive.filterAudio') },
]);

const sortOptions = computed(() => [
  { value: 'date', label: t('drive.sortDate') },
  { value: 'name', label: t('drive.sortName') },
  { value: 'size', label: t('drive.sortSize') },
]);

async function loadFiles(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    if (!props.organizationId) {
      await operations.ensureOrganization();
    }
    const orgId = activeOrgId.value;
    if (!orgId) {
      assets.value = [];
      return;
    }
    const res = await mediaApi.listAssets(orgId);
    assets.value = res.items ?? [];
    selectedIds.value = selectedIds.value.filter((id) =>
      assets.value.some((asset) => asset.id === id),
    );
  } catch (caught) {
    error.value = resolveErrorMessage(caught);
  } finally {
    loading.value = false;
  }
}

function toggleSelect(id: string): void {
  if (selectedIds.value.includes(id)) {
    selectedIds.value = selectedIds.value.filter((x) => x !== id);
  } else {
    selectedIds.value = [...selectedIds.value, id];
  }
}

function toggleSelectAll(): void {
  if (allVisibleSelected.value) {
    const visibleSet = new Set(visibleAssets.value.map((a) => a.id));
    selectedIds.value = selectedIds.value.filter((id) => !visibleSet.has(id));
  } else {
    const next = new Set(selectedIds.value);
    for (const a of visibleAssets.value) next.add(a.id);
    selectedIds.value = Array.from(next);
  }
}

function openFolder(path: string): void {
  currentFolder.value = path;
  selectedIds.value = [];
}

function goUpFolder(): void {
  if (!currentFolder.value) return;
  const parts = currentFolder.value.split('/').filter(Boolean);
  parts.pop();
  currentFolder.value = parts.join('/');
  selectedIds.value = [];
}

function openNewFolderModal(): void {
  newFolderName.value = '';
  showNewFolderModal.value = true;
}

function handleCreateFolder(): void {
  const clean = newFolderName.value.trim().replace(/[/\\]+/g, '');
  if (!clean) return;
  const full = currentFolder.value ? `${currentFolder.value}/${clean}` : clean;
  if (!createdFolders.value.includes(full)) {
    createdFolders.value.push(full);
  }
  showNewFolderModal.value = false;
  toast.success(t('drive.folderCreated'));
}

function cancelActiveUpload(): void {
  if (currentUploadHandle) {
    void operations.cancelUpload(currentUploadHandle.id);
  } else {
    uploadController?.abort();
  }
}

async function handleFilesSelected(selected: File[]): Promise<void> {
  if (!selected.length) return;
  const orgId = activeOrgId.value;
  if (!orgId) return;

  saving.value = true;
  uploadProgress.value = 0;
  const controller = new AbortController();
  uploadController = controller;
  const totalBytes = selected.reduce((sum, f) => sum + f.size, 0);
  const tracker = operations.registerUpload({
    organizationId: orgId,
    label:
      selected.length === 1 ? selected[0]!.name : `${selected[0]!.name} (+${selected.length - 1})`,
    total: totalBytes,
    abortController: controller,
  });
  currentUploadHandle = tracker;

  try {
    let completedBytes = 0;
    for (let i = 0; i < selected.length; i += 1) {
      if (controller.signal.aborted) {
        throw new DOMException('The operation was aborted.', 'AbortError');
      }
      const file = selected[i]!;
      const sha256 = await computeBlobSha256(file);
      if (controller.signal.aborted) {
        throw new DOMException('The operation was aborted.', 'AbortError');
      }
      const reserved = await mediaApi.reserveUpload(orgId, {
        kind: inferKind(file),
        name: file.name,
        mime: file.type || 'application/octet-stream',
        sizeBytes: file.size,
        sha256,
      });
      tracker.setMediaId(reserved.id);

      await mediaApi.uploadBinary(orgId, reserved.id, file, sha256, {
        signal: controller.signal,
        onProgress: (info) => {
          const loadedTotal = completedBytes + info.loaded;
          const pct =
            totalBytes > 0 ? Math.min(100, Math.round((loadedTotal / totalBytes) * 100)) : info.percent;
          uploadProgress.value = pct;
          tracker.updateProgress({
            loaded: loadedTotal,
            total: totalBytes,
            percent: pct,
            lengthComputable: totalBytes > 0,
          });
        },
      });

      const meta: Record<string, unknown> = {
        mimeType: file.type || 'application/octet-stream',
        sizeBytes: file.size,
        sha256,
      };
      if (currentFolder.value) {
        meta.folderPath = currentFolder.value;
      }
      const confirmed = await mediaApi.confirmUpload(orgId, reserved.id, meta);
      assets.value.unshift(confirmed);
      completedBytes += file.size;
    }

    tracker.markCompleted();
    toast.success(t('drive.uploadSuccess'));
  } catch (caught) {
    if (
      controller.signal.aborted ||
      (caught instanceof DOMException && caught.name === 'AbortError')
    ) {
      tracker.markCancelled();
      toast.info(t('workspace.uploadCancelled'));
    } else {
      const msg = resolveErrorMessage(caught);
      tracker.markFailed(msg);
      toast.error(msg);
    }
  } finally {
    if (uploadController === controller) {
      uploadController = null;
      currentUploadHandle = null;
    }
    saving.value = false;
    uploadProgress.value = null;
  }
}

async function openPreviewModal(asset: MediaAsset): Promise<void> {
  try {
    const playback = await mediaApi.getPlaybackUrl(activeOrgId.value, asset.id);
    previewingAsset.value = asset;
    previewingUrl.value = playback.url;
    showPreviewModal.value = true;
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  }
}

async function downloadAsset(asset: MediaAsset): Promise<void> {
  try {
    const url = await mediaApi.getDownloadUrl(activeOrgId.value, asset.id);
    const link = document.createElement('a');
    link.href = url;
    link.download = asset.originalName;
    document.body.appendChild(link);
    link.click();
    link.remove();
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  }
}

function openInfoModal(asset: MediaAsset): void {
  infoAsset.value = asset;
  showInfoModal.value = true;
}

function openRenameModal(asset: MediaAsset): void {
  renamingAsset.value = asset;
  newFileName.value = asset.originalName;
  showRenameModal.value = true;
}

async function handleRenameFile(): Promise<void> {
  if (!renamingAsset.value || !newFileName.value.trim()) return;
  saving.value = true;
  try {
    const updated = await mediaApi.renameAsset(
      activeOrgId.value,
      renamingAsset.value.id,
      newFileName.value.trim(),
    );
    const idx = assets.value.findIndex((a) => a.id === updated.id);
    if (idx !== -1) assets.value[idx] = updated;
    showRenameModal.value = false;
    toast.success(t('drive.fileRenamed'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

function openMoveModal(targets: MediaAsset[]): void {
  movingAssets.value = targets;
  targetMoveFolder.value = currentFolder.value;
  showMoveModal.value = true;
}

async function handleMoveFiles(): Promise<void> {
  if (movingAssets.value.length === 0) return;
  saving.value = true;
  try {
    const target = targetMoveFolder.value.trim().replace(/^\/+|\/+$/g, '');
    for (const asset of movingAssets.value) {
      const updated = await mediaApi.moveAsset(activeOrgId.value, asset.id, target);
      const idx = assets.value.findIndex((a) => a.id === updated.id);
      if (idx !== -1) assets.value[idx] = updated;
    }
    if (target && !createdFolders.value.includes(target)) {
      createdFolders.value.push(target);
    }
    showMoveModal.value = false;
    selectedIds.value = [];
    toast.success(t('drive.fileMoved'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

async function handleCopyFile(asset: MediaAsset): Promise<void> {
  saving.value = true;
  try {
    const copied = await mediaApi.copyAsset(activeOrgId.value, asset.id, currentFolder.value);
    assets.value.unshift(copied);
    toast.success(t('drive.fileCopied'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

function openDeleteModal(targets: MediaAsset[]): void {
  deletingAssets.value = targets;
  showDeleteModal.value = true;
}

async function handleDeleteFiles(): Promise<void> {
  if (deletingAssets.value.length === 0) return;
  saving.value = true;
  try {
    const ids = new Set(deletingAssets.value.map((a) => a.id));
    for (const asset of deletingAssets.value) {
      await workspaceApi.moveToTrash({
        entityType: 'media.asset',
        entityId: asset.id,
        organizationId: activeOrgId.value,
      });
    }
    assets.value = assets.value.filter((a) => !ids.has(a.id));
    selectedIds.value = selectedIds.value.filter((id) => !ids.has(id));
    showDeleteModal.value = false;
    toast.success(t('drive.fileDeleted'));
  } catch (caught) {
    toast.error(resolveErrorMessage(caught));
  } finally {
    saving.value = false;
  }
}

function handleContextSelect(item: MenuItem): void {
  const asset = contextTarget.value;
  if (!asset) return;
  if (item.id === 'preview') void openPreviewModal(asset);
  else if (item.id === 'download') void downloadAsset(asset);
  else if (item.id === 'info') openInfoModal(asset);
  else if (item.id === 'rename') openRenameModal(asset);
  else if (item.id === 'move') openMoveModal([asset]);
  else if (item.id === 'copy') void handleCopyFile(asset);
  else if (item.id === 'delete') openDeleteModal([asset]);
}

function bulkMoveSelected(): void {
  const targets = assets.value.filter((a) => selectedIds.value.includes(a.id));
  if (targets.length > 0) openMoveModal(targets);
}

function bulkDeleteSelected(): void {
  const targets = assets.value.filter((a) => selectedIds.value.includes(a.id));
  if (targets.length > 0) openDeleteModal(targets);
}

watch(activeOrgId, () => {
  currentFolder.value = '';
  selectedIds.value = [];
  void loadFiles();
});

onMounted(() => {
  void loadFiles();
});
</script>

<template>
  <div
    class="cloud-drive"
    data-testid="universal-file-manager"
  >
    <header class="cloud-drive__header">
      <div>
        <h1 class="cloud-drive__title">{{ $t('drive.title') }}</h1>
        <p class="cloud-drive__subtitle">{{ $t('drive.subtitle') }}</p>
      </div>
      <div class="cloud-drive__header-actions">
        <AppButton
          variant="secondary"
          size="s"
          data-testid="drive-new-folder-btn"
          @click="openNewFolderModal"
        >
          {{ $t('drive.newFolder') }}
        </AppButton>
        <RouterLink
          :to="{ name: 'trash' }"
          class="cloud-drive__trash-link"
        >
          {{ $t('drive.openTrash') }}
        </RouterLink>
      </div>
    </header>

    <!-- Summary bar -->
    <AppCard class="cloud-drive__summary-card">
      <div class="cloud-drive__quota-header">
        <span class="cloud-drive__quota-title">{{ $t('drive.storageSpace') }}</span>
        <span class="cloud-drive__quota-stats">
          {{ assets.length }} · {{ formatBytes(totalBytes) }}
        </span>
      </div>
    </AppCard>

    <!-- Drag & Drop Upload Zone -->
    <AppFileDropzone
      multiple
      :label="$t('drive.uploadFile')"
      :hint="$t('drive.dropToUpload')"
      :busy="saving"
      :progress="uploadProgress"
      :cancellable="saving"
      @select="handleFilesSelected"
      @cancel="cancelActiveUpload"
    />

    <!-- Breadcrumbs + Search + Filter + Sort -->
    <div class="cloud-drive__toolbar">
      <nav
        class="cloud-drive__breadcrumbs"
        aria-label="Folder breadcrumbs"
      >
        <button
          type="button"
          class="cloud-drive__crumb"
          :class="{ 'cloud-drive__crumb--active': !currentFolder }"
          @click="openFolder('')"
        >
          {{ $t('drive.rootFolder') }}
        </button>
        <template
          v-for="crumb in breadcrumbs"
          :key="crumb.path"
        >
          <span class="cloud-drive__crumb-sep">/</span>
          <button
            type="button"
            class="cloud-drive__crumb"
            :class="{ 'cloud-drive__crumb--active': currentFolder === crumb.path }"
            @click="openFolder(crumb.path)"
          >
            {{ crumb.name }}
          </button>
        </template>
      </nav>

      <div class="cloud-drive__controls">
        <AppInput
          id="drive-search-input"
          v-model="searchQuery"
          type="search"
          :placeholder="$t('drive.searchPlaceholder')"
          data-testid="drive-search-input"
        />
        <AppDropdown
          v-model="kindFilter"
          :label="$t('drive.filterAll')"
          :options="kindOptions"
        />
        <AppDropdown
          v-model="sortField"
          :label="$t('drive.sortBy')"
          :options="sortOptions"
        />
        <AppButton
          variant="secondary"
          size="s"
          @click="sortDir = sortDir === 'asc' ? 'desc' : 'asc'"
        >
          {{ sortDir === 'asc' ? '↑' : '↓' }}
        </AppButton>
      </div>
    </div>

    <!-- Bulk Selection Bar -->
    <div
      v-if="selectedIds.length > 0"
      class="cloud-drive__bulk-bar"
      data-testid="drive-bulk-bar"
    >
      <span>{{ $t('drive.selectedCount', { count: selectedIds.length }) }}</span>
      <div class="cloud-drive__btn-group">
        <AppButton
          variant="secondary"
          size="s"
          @click="bulkMoveSelected"
        >
          {{ $t('drive.moveSelected') }}
        </AppButton>
        <AppButton
          variant="danger"
          size="s"
          @click="bulkDeleteSelected"
        >
          {{ $t('drive.deleteSelected') }}
        </AppButton>
      </div>
    </div>

    <AppErrorState
      v-if="error"
      :message="error"
      :action-label="$t('common.retry')"
      @action="loadFiles"
    />

    <AppCard
      v-else-if="loading"
      class="cloud-drive__card"
    >
      <AppSkeleton
        variant="table"
        :rows="5"
        :columns="5"
      />
    </AppCard>

    <div
      v-else-if="childFolders.length > 0 || visibleAssets.length > 0 || currentFolder"
      class="cloud-drive__table-wrapper"
    >
      <AppContextMenu
        :items="contextMenuItems"
        :label="$t('admin.actions')"
        @select="handleContextSelect"
      >
        <table class="cloud-drive__table">
          <thead>
            <tr>
              <th class="cloud-drive__check-col">
                <input
                  type="checkbox"
                  :checked="allVisibleSelected"
                  :aria-label="$t('drive.fileName')"
                  @change="toggleSelectAll"
                />
              </th>
              <th>{{ $t('drive.fileName') }}</th>
              <th>{{ $t('drive.fileSize') }}</th>
              <th>{{ $t('drive.status') }}</th>
              <th>{{ $t('drive.lastModified') }}</th>
              <th class="cloud-drive__align-right">{{ $t('admin.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="currentFolder">
              <td />
              <td colspan="5">
                <button
                  type="button"
                  class="cloud-drive__folder-btn"
                  @click="goUpFolder"
                >
                  📁 ..
                </button>
              </td>
            </tr>
            <tr
              v-for="folder in childFolders"
              :key="`folder-${folder.path}`"
              class="cloud-drive__folder-row"
            >
              <td />
              <td colspan="5">
                <button
                  type="button"
                  class="cloud-drive__folder-btn"
                  @click="openFolder(folder.path)"
                >
                  📁 {{ folder.name }}
                </button>
              </td>
            </tr>
            <tr
              v-for="asset in visibleAssets"
              :key="asset.id"
              data-testid="drive-file-row"
              @contextmenu="contextTarget = asset"
            >
              <td class="cloud-drive__check-col">
                <input
                  type="checkbox"
                  :checked="selectedIds.includes(asset.id)"
                  :aria-label="asset.originalName"
                  @change="toggleSelect(asset.id)"
                />
              </td>
              <td class="cloud-drive__file-cell">
                <span class="cloud-drive__file-icon">📄</span>
                <div class="cloud-drive__file-meta">
                  <strong class="cloud-drive__file-name">{{ asset.originalName }}</strong>
                  <span class="cloud-drive__file-path">
                    {{ getAssetFolder(asset) ? `/${getAssetFolder(asset)}` : '/' }} ·
                    {{ asset.verifiedMime || asset.originalMime }}
                  </span>
                </div>
              </td>
              <td class="cloud-drive__muted-cell">{{ formatBytes(asset.sizeBytes) }}</td>
              <td>
                <AppBadge
                  variant="neutral"
                  size="s"
                >
                  {{ asset.kind }}
                </AppBadge>
              </td>
              <td class="cloud-drive__muted-cell">
                {{ formatDateTime(asset.updatedAt ?? asset.createdAt) }}
              </td>
              <td class="cloud-drive__align-right">
                <div class="cloud-drive__btn-group">
                  <AppButton
                    variant="secondary"
                    size="s"
                    @click="openPreviewModal(asset)"
                  >
                    {{ $t('media.preview') }}
                  </AppButton>
                  <AppButton
                    variant="secondary"
                    size="s"
                    @click="downloadAsset(asset)"
                  >
                    {{ $t('drive.download') }}
                  </AppButton>
                  <AppButton
                    variant="secondary"
                    size="s"
                    @click="openInfoModal(asset)"
                  >
                    {{ $t('drive.fileInfo') }}
                  </AppButton>
                  <AppButton
                    variant="secondary"
                    size="s"
                    @click="openRenameModal(asset)"
                  >
                    {{ $t('drive.renameFile') }}
                  </AppButton>
                  <AppButton
                    variant="secondary"
                    size="s"
                    @click="openMoveModal([asset])"
                  >
                    {{ $t('drive.moveFile') }}
                  </AppButton>
                  <AppButton
                    variant="secondary"
                    size="s"
                    @click="handleCopyFile(asset)"
                  >
                    {{ $t('drive.copyFile') }}
                  </AppButton>
                  <AppButton
                    variant="danger"
                    size="s"
                    @click="openDeleteModal([asset])"
                  >
                    {{ $t('admin.delete') }}
                  </AppButton>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </AppContextMenu>
    </div>

    <AppEmptyState
      v-else
      :title="$t('drive.noFiles')"
      :description="$t('drive.subtitle')"
    />

    <!-- New Folder Modal -->
    <AppDialog
      v-model="showNewFolderModal"
      :title="$t('drive.newFolder')"
      size="s"
    >
      <form @submit.prevent="handleCreateFolder">
        <AppInput
          id="drive-new-folder-input"
          v-model="newFolderName"
          :label="$t('drive.folderName')"
          autofocus
        />
        <div class="cloud-drive__modal-actions">
          <AppButton
            variant="ghost"
            type="button"
            @click="showNewFolderModal = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton
            variant="primary"
            type="submit"
            :disabled="!newFolderName.trim()"
          >
            {{ $t('drive.createFolder') }}
          </AppButton>
        </div>
      </form>
    </AppDialog>

    <!-- Rename Modal -->
    <AppDialog
      v-model="showRenameModal"
      :title="$t('drive.renameFile')"
      size="s"
    >
      <form @submit.prevent="handleRenameFile">
        <AppInput
          id="drive-rename-input"
          v-model="newFileName"
          :label="$t('drive.fileName')"
          autofocus
        />
        <div class="cloud-drive__modal-actions">
          <AppButton
            variant="ghost"
            type="button"
            @click="showRenameModal = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton
            variant="primary"
            type="submit"
            :disabled="saving || !newFileName.trim()"
          >
            {{ $t('common.save') }}
          </AppButton>
        </div>
      </form>
    </AppDialog>

    <!-- Move Modal -->
    <AppDialog
      v-model="showMoveModal"
      :title="$t('drive.moveFile')"
      size="s"
    >
      <form @submit.prevent="handleMoveFiles">
        <AppInput
          id="drive-move-folder-input"
          v-model="targetMoveFolder"
          :label="$t('drive.folderPath')"
          placeholder="docs/archive"
          autofocus
        />
        <div class="cloud-drive__modal-actions">
          <AppButton
            variant="ghost"
            type="button"
            @click="showMoveModal = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton
            variant="primary"
            type="submit"
            :disabled="saving"
          >
            {{ $t('common.save') }}
          </AppButton>
        </div>
      </form>
    </AppDialog>

    <!-- File Info Modal -->
    <AppDialog
      v-model="showInfoModal"
      :title="$t('drive.fileInfo')"
      size="m"
    >
      <dl
        v-if="infoAsset"
        class="cloud-drive__info-list"
      >
        <div class="cloud-drive__info-row">
          <dt>{{ $t('drive.fileName') }}</dt>
          <dd>{{ infoAsset.originalName }}</dd>
        </div>
        <div class="cloud-drive__info-row">
          <dt>{{ $t('drive.mimeType') }}</dt>
          <dd>{{ infoAsset.verifiedMime || infoAsset.originalMime }}</dd>
        </div>
        <div class="cloud-drive__info-row">
          <dt>{{ $t('drive.fileSize') }}</dt>
          <dd>{{ formatBytes(infoAsset.sizeBytes) }}</dd>
        </div>
        <div class="cloud-drive__info-row">
          <dt>{{ $t('drive.folderPath') }}</dt>
          <dd>{{ getAssetFolder(infoAsset) ? `/${getAssetFolder(infoAsset)}` : '/' }}</dd>
        </div>
        <div class="cloud-drive__info-row">
          <dt>{{ $t('drive.status') }}</dt>
          <dd>{{ infoAsset.status }}</dd>
        </div>
        <div
          v-if="infoAsset.width && infoAsset.height"
          class="cloud-drive__info-row"
        >
          <dt>{{ $t('drive.dimensions') }}</dt>
          <dd>{{ infoAsset.width }} × {{ infoAsset.height }}</dd>
        </div>
        <div
          v-if="infoAsset.durationSeconds"
          class="cloud-drive__info-row"
        >
          <dt>{{ $t('drive.duration') }}</dt>
          <dd>{{ infoAsset.durationSeconds.toFixed(1) }}s</dd>
        </div>
        <div class="cloud-drive__info-row">
          <dt>{{ $t('drive.sha256') }}</dt>
          <dd class="cloud-drive__mono">{{ infoAsset.sha256 }}</dd>
        </div>
        <div class="cloud-drive__info-row">
          <dt>{{ $t('drive.createdAt') }}</dt>
          <dd>{{ formatDateTime(infoAsset.createdAt) }}</dd>
        </div>
      </dl>
    </AppDialog>

    <!-- Delete Confirmation Modal -->
    <AppConfirmDialog
      v-model="showDeleteModal"
      :title="$t('drive.deleteSelected')"
      :description="$t('drive.deleteConfirm')"
      :confirm-label="$t('admin.delete')"
      :cancel-label="$t('common.cancel')"
      :busy="saving"
      danger
      @confirm="handleDeleteFiles"
    />

    <!-- Media Preview Dialog -->
    <MediaPreviewDialog
      v-model:open="showPreviewModal"
      :src="previewingUrl"
      :name="previewingAsset?.originalName"
      :mime="previewingAsset?.verifiedMime || previewingAsset?.originalMime"
      :size-bytes="previewingAsset?.sizeBytes"
    />
  </div>
</template>

<style scoped>
.cloud-drive {
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
  max-width: 1200px;
  margin: 0 auto;
  width: 100%;
}

.cloud-drive__header {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-start;
  gap: var(--space-12);
}

.cloud-drive__header-actions {
  display: flex;
  align-items: center;
  gap: var(--space-8);
}

.cloud-drive__trash-link {
  color: var(--accent);
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-emphasis-weight);
  text-decoration: none;
}

.cloud-drive__title {
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
  color: var(--text-1);
}

.cloud-drive__subtitle {
  margin-top: var(--space-4);
  color: var(--text-2);
  font-size: var(--type-body-medium-size);
}

.cloud-drive__quota-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-8);
}

.cloud-drive__quota-title {
  font-weight: var(--type-body-medium-emphasis-weight);
  color: var(--text-1);
}

.cloud-drive__quota-stats {
  color: var(--text-2);
  font-size: var(--type-body-small-size);
}

.cloud-drive__toolbar {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-12);
}

.cloud-drive__breadcrumbs {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-4);
}

.cloud-drive__crumb {
  padding: var(--space-4) var(--space-8);
  border-radius: var(--radius-control-s);
  color: var(--text-2);
  font-size: var(--type-body-small-size);
}

.cloud-drive__crumb--active {
  color: var(--text-1);
  background: var(--surface-2);
  font-weight: var(--type-body-small-emphasis-weight);
}

.cloud-drive__crumb-sep {
  color: var(--text-2);
}

.cloud-drive__controls {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-8);
}

.cloud-drive__bulk-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: var(--space-8) var(--space-12);
  border-radius: var(--radius-control-m);
  border: 1px solid var(--accent);
  background: var(--accent-soft-1);
}

.cloud-drive__table-wrapper {
  overflow-x: auto;
  border: 1px solid var(--border);
  border-radius: var(--radius-card-m);
  background: var(--card);
}

.cloud-drive__table {
  width: 100%;
  border-collapse: collapse;
}

.cloud-drive__table th,
.cloud-drive__table td {
  padding: var(--space-10) var(--space-12);
  border-bottom: 1px solid var(--border);
  text-align: left;
  font-size: var(--type-body-small-size);
}

.cloud-drive__check-col {
  width: 36px;
}

.cloud-drive__folder-btn {
  display: inline-flex;
  align-items: center;
  gap: var(--space-6);
  color: var(--text-1);
  font-weight: var(--type-body-small-emphasis-weight);
}

.cloud-drive__file-cell {
  display: flex;
  align-items: center;
  gap: var(--space-8);
}

.cloud-drive__file-meta {
  display: flex;
  flex-direction: column;
}

.cloud-drive__file-name {
  color: var(--text-1);
}

.cloud-drive__file-path,
.cloud-drive__muted-cell {
  color: var(--text-2);
  font-size: var(--type-body-caption-size);
}

.cloud-drive__align-right {
  text-align: right;
}

.cloud-drive__btn-group {
  display: inline-flex;
  flex-wrap: wrap;
  gap: var(--space-6);
  justify-content: flex-end;
}

.cloud-drive__modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-8);
  margin-top: var(--space-16);
}

.cloud-drive__info-list {
  display: flex;
  flex-direction: column;
  gap: var(--space-8);
}

.cloud-drive__info-row {
  display: flex;
  justify-content: space-between;
  gap: var(--space-12);
  border-bottom: 1px solid var(--border);
  padding-bottom: var(--space-6);
}

.cloud-drive__info-row dt {
  color: var(--text-2);
  font-size: var(--type-body-small-size);
}

.cloud-drive__info-row dd {
  color: var(--text-1);
  font-size: var(--type-body-small-size);
  word-break: break-all;
}

.cloud-drive__mono {
  font-family: var(--font-mono);
}
</style>
