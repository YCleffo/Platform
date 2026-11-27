<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MediaPreviewDialog from './MediaPreviewDialog.vue';
import type { MediaAsset } from './types';

const props = withDefaults(
  defineProps<{
    name?: string;
    sizeBytes?: number;
    mime?: string;
    url?: string;
    asset?: MediaAsset | null;
    previewable?: boolean;
  }>(),
  {
    name: undefined,
    sizeBytes: undefined,
    mime: undefined,
    url: undefined,
    asset: null,
    previewable: true,
  },
);

const emit = defineEmits<{
  (e: 'preview'): void;
}>();

const { t } = useI18n();
const previewOpen = ref(false);

const displayName = computed(() => props.name || props.asset?.originalName || 'file');

const resolvedMime = computed(
  () => props.mime || props.asset?.verifiedMime || props.asset?.originalMime || 'application/octet-stream',
);

const displaySize = computed(() => {
  const bytes = props.sizeBytes ?? props.asset?.sizeBytes ?? 0;
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
});

const downloadUrl = computed(() => props.url || props.asset?.playbackUrl || '');

const isUnavailable = computed(
  () =>
    !downloadUrl.value ||
    props.asset?.status === 'EXPIRED' ||
    props.asset?.status === 'FAILED' ||
    props.asset?.status === 'CANCELLED',
);

function openPreview(): void {
  if (isUnavailable.value || !props.previewable) return;
  emit('preview');
  previewOpen.value = true;
}
</script>

<template>
  <div
    class="app-file-attachment"
    :class="{ 'app-file-attachment--unavailable': isUnavailable }"
  >
    <div class="app-file-attachment__icon-wrapper">
      <svg
        class="app-file-attachment__icon"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
      >
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
        <polyline points="14 2 14 8 20 8" />
        <line
          x1="16"
          y1="13"
          x2="8"
          y2="13"
        />
        <line
          x1="16"
          y1="17"
          x2="8"
          y2="17"
        />
        <polyline points="10 9 9 9 8 9" />
      </svg>
    </div>

    <div class="app-file-attachment__info">
      <span
        class="app-file-attachment__name"
        :title="displayName"
      >
        {{ displayName }}
      </span>
      <span class="app-file-attachment__size">
        {{ isUnavailable ? t('media.unavailable') : displaySize }}
      </span>
    </div>

    <div class="app-file-attachment__actions">
      <button
        v-if="previewable && !isUnavailable"
        type="button"
        class="app-file-attachment__action"
        :aria-label="`${t('media.preview')} ${displayName}`"
        @click="openPreview"
      >
        <svg
          class="app-file-attachment__dl-icon"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
        >
          <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
          <circle
            cx="12"
            cy="12"
            r="3"
          />
        </svg>
      </button>

      <a
        v-if="!isUnavailable"
        :href="downloadUrl"
        :download="displayName"
        class="app-file-attachment__download"
        :aria-label="`${t('media.download')} ${displayName}`"
      >
        <svg
          class="app-file-attachment__dl-icon"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
        >
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
          <polyline points="7 10 12 15 17 10" />
          <line
            x1="12"
            y1="15"
            x2="12"
            y2="3"
          />
        </svg>
      </a>
    </div>

    <MediaPreviewDialog
      v-if="previewOpen"
      v-model:open="previewOpen"
      :src="downloadUrl"
      :name="displayName"
      :mime="resolvedMime"
      :size-bytes="sizeBytes ?? asset?.sizeBytes"
      :asset="asset"
    />
  </div>
</template>

<style scoped>
.app-file-attachment {
  display: flex;
  align-items: center;
  gap: 12px;
  background-color: var(--surface-primary, #1e222b);
  border: 1px solid var(--border-default, rgba(255, 255, 255, 0.1));
  border-radius: var(--radius-card-m, 12px);
  padding: 10px 14px;
  max-width: 380px;
  box-sizing: border-box;
  box-shadow: var(--shadow-soft, 0 2px 6px rgba(0, 0, 0, 0.12));
}

.app-file-attachment--unavailable {
  opacity: 0.75;
}

.app-file-attachment__icon-wrapper {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  border-radius: var(--radius-card-s, 8px);
  background: var(--surface-secondary, rgba(255, 255, 255, 0.08));
  color: var(--accent, #00d2ff);
  flex-shrink: 0;
}

.app-file-attachment__icon {
  width: 20px;
  height: 20px;
}

.app-file-attachment__info {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.app-file-attachment__name {
  font-size: var(--type-body-sm-size, 13px);
  line-height: var(--type-body-sm-line, 16px);
  font-weight: var(--type-body-sm-weight, 500);
  color: var(--text-primary, #fff);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.app-file-attachment__size {
  font-size: var(--type-detail-size, 11px);
  line-height: var(--type-detail-line, 14px);
  font-weight: var(--type-detail-weight, 400);
  color: var(--text-secondary, #9ba3af);
  margin-top: 2px;
}

.app-file-attachment__actions {
  display: flex;
  align-items: center;
  gap: 4px;
}

.app-file-attachment__action,
.app-file-attachment__download {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: none;
  background: transparent;
  color: var(--text-secondary, #9ba3af);
  cursor: pointer;
  transition:
    color var(--theme-transition),
    background-color var(--theme-transition);
}

.app-file-attachment__action:hover,
.app-file-attachment__download:hover {
  color: var(--text-primary, #fff);
  background: var(--surface-tertiary, rgba(255, 255, 255, 0.12));
}

.app-file-attachment__dl-icon {
  width: 16px;
  height: 16px;
}
</style>
