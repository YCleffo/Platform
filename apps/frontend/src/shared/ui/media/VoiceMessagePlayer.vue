<script setup lang="ts">
import type { MediaAsset } from './types';
import type { MediaSessionPolicy } from '@/shared/media/types';
import AudioPlayer from './AudioPlayer.vue';

withDefaults(
  defineProps<{
    src?: string;
    asset?: MediaAsset | null;
    waveformBars?: number[];
    autoplay?: boolean;
    durationHint?: number;
    policy?: MediaSessionPolicy;
  }>(),
  {
    src: undefined,
    asset: null,
    waveformBars: undefined,
    autoplay: false,
    durationHint: undefined,
    policy: 'exclusive',
  },
);

const emit = defineEmits<{
  (e: 'play'): void;
  (e: 'pause'): void;
  (e: 'ended'): void;
  (e: 'timeupdate', currentTime: number): void;
  (e: 'error', message: string): void;
}>();
</script>

<template>
  <AudioPlayer
    class="app-voice-message-player"
    :src="src"
    :asset="asset"
    :waveform-bars="waveformBars"
    :autoplay="autoplay"
    :duration-hint="durationHint"
    :show-download="false"
    :show-volume="false"
    :compact="true"
    :policy="policy"
    @play="emit('play')"
    @pause="emit('pause')"
    @ended="emit('ended')"
    @timeupdate="(time) => emit('timeupdate', time)"
    @error="(msg) => emit('error', msg)"
  />
</template>
