<script setup lang="ts">
import { computed, ref } from 'vue';
import type { MediaAsset, MediaKind } from './types';
import AudioPlayer from './AudioPlayer.vue';
import VoiceMessagePlayer from './VoiceMessagePlayer.vue';
import VideoPlayer from './VideoPlayer.vue';
import VideoNotePlayer from './VideoNotePlayer.vue';
import FileAttachment from './FileAttachment.vue';
import MediaPreviewDialog from './MediaPreviewDialog.vue';

const props = defineProps<{
  asset?: MediaAsset | null;
  src?: string;
  kind?: MediaKind;
  name?: string;
  sizeBytes?: number;
  mime?: string;
}>();

const previewOpen = ref(false);

const resolvedKind = computed<MediaKind>(() => {
  return props.kind || props.asset?.kind || 'document';
});

const resolvedSrc = computed(() => {
  return props.src || props.asset?.playbackUrl || '';
});

const resolvedName = computed(() => {
  return props.name || props.asset?.originalName || 'media';
});
</script>

<template>
  <div class="app-media-player">
    <VoiceMessagePlayer
      v-if="resolvedKind === 'voice'"
      :asset="asset"
      :src="resolvedSrc"
    />

    <AudioPlayer
      v-else-if="resolvedKind === 'audio'"
      :asset="asset"
      :src="resolvedSrc"
      :show-download="true"
    />

    <VideoNotePlayer
      v-else-if="resolvedKind === 'video_note'"
      :asset="asset"
      :src="resolvedSrc"
    />

    <template v-else-if="resolvedKind === 'image'">
      <div
        class="app-media-player__image-wrapper"
        role="button"
        tabindex="0"
        @click="previewOpen = true"
        @keydown.enter.prevent="previewOpen = true"
      >
        <img
          :src="resolvedSrc"
          :alt="resolvedName"
          loading="lazy"
          class="app-media-player__image"
        />
      </div>
      <MediaPreviewDialog
        v-if="previewOpen"
        v-model:open="previewOpen"
        kind="image"
        :src="resolvedSrc"
        :name="resolvedName"
        :asset="asset"
      />
    </template>

    <div
      v-else-if="resolvedKind === 'video'"
      class="app-media-player__video-wrapper"
    >
      <VideoPlayer
        :asset="asset"
        :src="resolvedSrc"
        :title="resolvedName"
      />
    </div>

    <FileAttachment
      v-else
      :asset="asset"
      :url="resolvedSrc"
      :name="resolvedName"
      :size-bytes="sizeBytes"
      :mime="mime"
    />
  </div>
</template>

<style scoped>
.app-media-player {
  display: inline-block;
  max-width: 100%;
}

.app-media-player__image-wrapper {
  max-width: 320px;
  max-height: 240px;
  border-radius: var(--radius-card-m, 12px);
  overflow: hidden;
  background: var(--surface-primary, #1e222b);
  border: 1px solid var(--border-default, rgba(255, 255, 255, 0.1));
  cursor: pointer;
}

.app-media-player__image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.app-media-player__video-wrapper {
  max-width: 420px;
}
</style>
