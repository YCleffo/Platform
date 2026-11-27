<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AudioPlayer from './AudioPlayer.vue';
import DocumentPreview from './DocumentPreview.vue';
import ImageViewer, { type GalleryImageItem } from './ImageViewer.vue';
import VideoPlayer from './VideoPlayer.vue';
import type { MediaAsset, MediaKind } from './types';

const props = withDefaults(
  defineProps<{
    open: boolean;
    src?: string;
    name?: string;
    mime?: string;
    kind?: MediaKind;
    sizeBytes?: number;
    asset?: MediaAsset | null;
    galleryItems?: GalleryImageItem[];
    initialIndex?: number;
  }>(),
  {
    src: undefined,
    name: undefined,
    mime: undefined,
    kind: undefined,
    sizeBytes: undefined,
    asset: null,
    galleryItems: undefined,
    initialIndex: 0,
  },
);

const emit = defineEmits<{
  (e: 'update:open', value: boolean): void;
  (e: 'close'): void;
}>();

const { t } = useI18n();

const resolvedSrc = computed(() => props.src || props.asset?.playbackUrl || '');
const resolvedName = computed(() => props.name || props.asset?.originalName || t('media.preview'));
const resolvedMime = computed(
  () => props.mime || props.asset?.verifiedMime || props.asset?.originalMime || '',
);

const resolvedKind = computed<MediaKind>(() => {
  if (props.kind) return props.kind;
  if (props.asset?.kind) return props.asset.kind;
  const m = resolvedMime.value.toLowerCase();
  const n = resolvedName.value.toLowerCase();
  if (m.startsWith('image/') || /\.(png|jpe?g|webp|gif|svg)$/i.test(n)) return 'image';
  if (m.startsWith('video/') || /\.(mp4|webm|mov|mkv)$/i.test(n)) return 'video';
  if (m.startsWith('audio/') || /\.(mp3|wav|ogg|m4a|flac|aac)$/i.test(n)) return 'audio';
  return 'document';
});

function handleUpdateOpen(value: boolean): void {
  emit('update:open', value);
  if (!value) emit('close');
}
</script>

<template>
  <AppDialog
    :model-value="open"
    :title="resolvedName"
    size="l"
    @update:model-value="handleUpdateOpen"
  >
    <div class="app-media-preview-dialog">
      <ImageViewer
        v-if="resolvedKind === 'image'"
        :src="resolvedSrc"
        :alt="resolvedName"
        :items="galleryItems"
        :initial-index="initialIndex"
      />
      <VideoPlayer
        v-else-if="resolvedKind === 'video' || resolvedKind === 'video_note'"
        :src="resolvedSrc"
        :asset="asset"
        :title="resolvedName"
      />
      <AudioPlayer
        v-else-if="resolvedKind === 'audio' || resolvedKind === 'voice'"
        :src="resolvedSrc"
        :asset="asset"
        :title="resolvedName"
        :show-download="true"
      />
      <DocumentPreview
        v-else
        :src="resolvedSrc"
        :name="resolvedName"
        :mime="resolvedMime"
        :size-bytes="sizeBytes ?? asset?.sizeBytes"
      />
    </div>
  </AppDialog>
</template>

<style scoped>
.app-media-preview-dialog {
  min-height: 160px;
}
</style>
