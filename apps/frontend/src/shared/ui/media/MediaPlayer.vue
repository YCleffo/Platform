<script setup lang="ts">
import { computed } from 'vue';
import type { MediaAsset, MediaKind } from './types';
import AudioPlayer from './AudioPlayer.vue';
import VideoNotePlayer from './VideoNotePlayer.vue';
import FileAttachment from './FileAttachment.vue';

const props = defineProps<{
  asset?: MediaAsset | null;
  src?: string;
  kind?: MediaKind;
  name?: string;
  sizeBytes?: number;
  mime?: string;
}>();

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
    <AudioPlayer
      v-if="resolvedKind === 'audio' || resolvedKind === 'voice'"
      :asset="asset"
      :src="resolvedSrc"
      :show-download="resolvedKind === 'audio'"
    />

    <VideoNotePlayer
      v-else-if="resolvedKind === 'video_note'"
      :asset="asset"
      :src="resolvedSrc"
    />

    <div
      v-else-if="resolvedKind === 'image'"
      class="app-media-player__image-wrapper"
    >
      <img
        :src="resolvedSrc"
        :alt="resolvedName"
        loading="lazy"
        class="app-media-player__image"
      />
    </div>

    <div
      v-else-if="resolvedKind === 'video'"
      class="app-media-player__video-wrapper"
    >
      <video
        :src="resolvedSrc"
        controls
        playsinline
        class="app-media-player__video"
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
}

.app-media-player__image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.app-media-player__video-wrapper {
  max-width: 400px;
  border-radius: var(--radius-card-m, 12px);
  overflow: hidden;
  background: #000;
  border: 1px solid var(--border-default, rgba(255, 255, 255, 0.1));
}

.app-media-player__video {
  width: 100%;
  max-height: 300px;
  display: block;
}
</style>
