<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = withDefaults(
  defineProps<{
    maxDurationSeconds?: number;
    size?: number;
  }>(),
  {
    maxDurationSeconds: 60,
    size: 240,
  },
);

const emit = defineEmits<{
  (
    e: 'record-complete',
    payload: { blob: Blob; durationSeconds: number; mimeType: string; previewUrl: string },
  ): void;
  (e: 'cancel'): void;
}>();

const { t } = useI18n();

const videoPreviewRef = ref<HTMLVideoElement | null>(null);
const videoReviewRef = ref<HTMLVideoElement | null>(null);

const isRecording = ref(false);
const isPreviewing = ref(false);
const isPlayingReview = ref(false);
const recordingSeconds = ref(0);
const timerId = ref<number | null>(null);
const recordedBlob = ref<Blob | null>(null);
const previewUrl = ref<string | null>(null);
const errorMessage = ref<string | null>(null);

let mediaRecorder: MediaRecorder | null = null;
let streamInstance: MediaStream | null = null;
let chunks: Blob[] = [];

// Circular Progress Ring
const radius = computed(() => props.size / 2 - 4);
const circumference = computed(() => 2 * Math.PI * radius.value);
const strokeDashoffset = computed(() => {
  const ratio = Math.min(1, recordingSeconds.value / props.maxDurationSeconds);
  return circumference.value * (1 - ratio);
});

function getSupportedVideoMimeType(): string {
  const types = [
    'video/webm;codecs=vp9,opus',
    'video/webm;codecs=vp8,opus',
    'video/webm',
    'video/mp4;codecs=avc1,mp4a.40.2',
    'video/mp4',
  ];
  for (const type of types) {
    if (typeof MediaRecorder !== 'undefined' && MediaRecorder.isTypeSupported(type)) {
      return type;
    }
  }
  return '';
}

function cleanupStream(): void {
  if (streamInstance) {
    for (const track of streamInstance.getTracks()) {
      track.stop();
    }
    streamInstance = null;
  }
  if (timerId.value) {
    window.clearInterval(timerId.value);
    timerId.value = null;
  }
}

async function initCamera(): Promise<void> {
  errorMessage.value = null;
  if (!navigator.mediaDevices?.getUserMedia) {
    errorMessage.value = t('media.cameraUnavailable');
    return;
  }

  try {
    const stream = await navigator.mediaDevices.getUserMedia({
      video: {
        width: { ideal: 480 },
        height: { ideal: 480 },
        aspectRatio: { ideal: 1 },
        facingMode: 'user',
      },
      audio: true,
    });
    streamInstance = stream;
    if (videoPreviewRef.value) {
      videoPreviewRef.value.srcObject = stream;
      void videoPreviewRef.value.play();
    }
  } catch {
    errorMessage.value = t('media.cameraUnavailable');
    cleanupStream();
  }
}

function startRecording(): void {
  if (!streamInstance) return;
  errorMessage.value = null;
  recordedBlob.value = null;
  previewUrl.value = null;
  chunks = [];
  recordingSeconds.value = 0;

  try {
    const mimeType = getSupportedVideoMimeType();
    const options = mimeType ? { mimeType } : undefined;
    mediaRecorder = new MediaRecorder(streamInstance, options);

    mediaRecorder.ondataavailable = (e) => {
      if (e.data && e.data.size > 0) {
        chunks.push(e.data);
      }
    };

    mediaRecorder.onstop = () => {
      const type = mediaRecorder?.mimeType || 'video/webm';
      const blob = new Blob(chunks, { type });
      recordedBlob.value = blob;
      previewUrl.value = URL.createObjectURL(blob);
      isPreviewing.value = true;
      cleanupStream();
    };

    mediaRecorder.start(250);
    isRecording.value = true;

    timerId.value = window.setInterval(() => {
      recordingSeconds.value++;
      if (recordingSeconds.value >= props.maxDurationSeconds) {
        stopRecording();
      }
    }, 1000);
  } catch {
    errorMessage.value = t('media.cameraUnavailable');
    cleanupStream();
  }
}

function stopRecording(): void {
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    mediaRecorder.stop();
  }
  isRecording.value = false;
  if (timerId.value) {
    window.clearInterval(timerId.value);
    timerId.value = null;
  }
}

function retake(): void {
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
  }
  recordedBlob.value = null;
  isPreviewing.value = false;
  isPlayingReview.value = false;
  recordingSeconds.value = 0;
  void initCamera();
}

function send(): void {
  if (!recordedBlob.value || !previewUrl.value) return;
  emit('record-complete', {
    blob: recordedBlob.value,
    durationSeconds: Math.max(1, recordingSeconds.value),
    mimeType: recordedBlob.value.type,
    previewUrl: previewUrl.value,
  });
}

function cancel(): void {
  cleanupStream();
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
  }
  emit('cancel');
}

function toggleReviewPlayback(): void {
  const el = videoReviewRef.value;
  if (!el) return;
  if (isPlayingReview.value) {
    el.pause();
  } else {
    void el.play();
  }
}

function formatDuration(s: number): string {
  const m = Math.floor(s / 60);
  const sec = s % 60;
  return `${m}:${sec < 10 ? '0' : ''}${sec}`;
}

onMounted(() => {
  void initCamera();
});

onBeforeUnmount(() => {
  cleanupStream();
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value);
  }
});
</script>

<template>
  <div class="app-video-note-recorder">
    <!-- Camera/Preview circular bubble -->
    <div
      class="app-video-note-recorder__bubble"
      :style="{ width: `${size}px`, height: `${size}px` }"
    >
      <!-- Circular Progress Ring while recording -->
      <svg
        v-if="isRecording"
        class="app-video-note-recorder__ring"
        :width="size"
        :height="size"
      >
        <circle
          class="app-video-note-recorder__ring-bg"
          :cx="size / 2"
          :cy="size / 2"
          :r="radius"
          stroke-width="4"
        />
        <circle
          class="app-video-note-recorder__ring-progress"
          :cx="size / 2"
          :cy="size / 2"
          :r="radius"
          stroke-width="4"
          :stroke-dasharray="circumference"
          :stroke-dashoffset="strokeDashoffset"
        />
      </svg>

      <!-- Live Camera Stream -->
      <video
        v-show="!isPreviewing"
        ref="videoPreviewRef"
        playsinline
        muted
        class="app-video-note-recorder__video app-video-note-recorder__video--live"
      />

      <!-- Recorded Review Playback -->
      <video
        v-if="isPreviewing && previewUrl"
        ref="videoReviewRef"
        :src="previewUrl"
        playsinline
        loop
        class="app-video-note-recorder__video"
        @play="isPlayingReview = true"
        @pause="isPlayingReview = false"
        @click="toggleReviewPlayback"
      />

      <!-- Overlay recording timer badge -->
      <div
        v-if="isRecording"
        class="app-video-note-recorder__timer-badge"
      >
        <span class="app-video-note-recorder__dot" />
        {{ formatDuration(recordingSeconds) }}
      </div>

      <!-- Play toggle overlay for review -->
      <button
        v-if="isPreviewing && !isPlayingReview"
        type="button"
        class="app-video-note-recorder__review-play"
        :aria-label="t('media.play')"
        @click="toggleReviewPlayback"
      >
        <svg
          viewBox="0 0 24 24"
          fill="currentColor"
          width="32"
          height="32"
        >
          <path d="M8 5v14l11-7z" />
        </svg>
      </button>

      <!-- Error message inside bubble -->
      <div
        v-if="errorMessage"
        class="app-video-note-recorder__error"
      >
        <p>{{ errorMessage }}</p>
      </div>
    </div>

    <!-- Recorder Actions Bar -->
    <div class="app-video-note-recorder__actions">
      <!-- State 1: Idle (Camera ready, waiting to record) -->
      <template v-if="!isRecording && !isPreviewing">
        <button
          type="button"
          class="app-video-note-recorder__btn app-video-note-recorder__btn--cancel"
          :aria-label="t('media.cancel')"
          @click="cancel"
        >
          ✕
        </button>
        <button
          type="button"
          class="app-video-note-recorder__btn app-video-note-recorder__btn--record"
          :aria-label="t('media.record')"
          :disabled="!!errorMessage"
          @click="startRecording"
        >
          <span class="app-video-note-recorder__record-core" />
        </button>
      </template>

      <!-- State 2: Actively Recording -->
      <template v-else-if="isRecording">
        <button
          type="button"
          class="app-video-note-recorder__btn app-video-note-recorder__btn--cancel"
          :aria-label="t('media.cancel')"
          @click="cancel"
        >
          ✕
        </button>
        <button
          type="button"
          class="app-video-note-recorder__btn app-video-note-recorder__btn--stop"
          :aria-label="t('media.stop')"
          @click="stopRecording"
        >
          <span class="app-video-note-recorder__stop-core" />
        </button>
      </template>

      <!-- State 3: Review Preview (Send or Retake) -->
      <template v-else-if="isPreviewing">
        <button
          type="button"
          class="app-video-note-recorder__btn app-video-note-recorder__btn--retake"
          :title="t('media.retake')"
          :aria-label="t('media.retake')"
          @click="retake"
        >
          ↺
        </button>
        <button
          type="button"
          class="app-video-note-recorder__btn app-video-note-recorder__btn--send"
          :title="t('media.send')"
          :aria-label="t('media.send')"
          @click="send"
        >
          ✓
        </button>
        <button
          type="button"
          class="app-video-note-recorder__btn app-video-note-recorder__btn--cancel"
          :aria-label="t('media.cancel')"
          @click="cancel"
        >
          ✕
        </button>
      </template>
    </div>
  </div>
</template>

<style scoped>
.app-video-note-recorder {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  padding: 16px;
  background-color: var(--surface-primary, #1e222b);
  border: 1px solid var(--border-default, rgba(255, 255, 255, 0.1));
  border-radius: var(--radius-card-m, 16px);
  box-shadow: var(--shadow-soft, 0 4px 16px rgba(0, 0, 0, 0.25));
  max-width: 320px;
  margin: 0 auto;
}

.app-video-note-recorder__bubble {
  position: relative;
  border-radius: 50%;
  overflow: hidden;
  background-color: #000;
  box-shadow: 0 0 0 2px var(--border-default, rgba(255, 255, 255, 0.2));
  flex-shrink: 0;
}

.app-video-note-recorder__ring {
  position: absolute;
  top: 0;
  left: 0;
  z-index: 5;
  pointer-events: none;
  transform: rotate(-90deg);
}

.app-video-note-recorder__ring-bg {
  fill: none;
  stroke: rgba(255, 255, 255, 0.2);
}

.app-video-note-recorder__ring-progress {
  fill: none;
  stroke: var(--accent, #00d2ff);
  stroke-linecap: round;
  transition:
    stroke-dashoffset var(--motion-control-duration) var(--motion-linear),
    var(--theme-transition);
}

.app-video-note-recorder__video {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  cursor: pointer;
}

.app-video-note-recorder__video--live {
  transform: scaleX(-1);
}

.app-video-note-recorder__timer-badge {
  position: absolute;
  bottom: 12px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: 6px;
  background: rgba(0, 0, 0, 0.65);
  color: #fff;
  padding: 4px 10px;
  border-radius: 12px;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-small-emphasis-weight);
  z-index: 4;
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
}

.app-video-note-recorder__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background-color: var(--danger, #ff4d4f);
  animation: app-vn-pulse var(--motion-caret-duration) infinite alternate;
}

@keyframes app-vn-pulse {
  from {
    opacity: 0.4;
  }
  to {
    opacity: 1;
  }
}

.app-video-note-recorder__review-play {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 52px;
  height: 52px;
  border-radius: 50%;
  border: none;
  background: rgba(0, 0, 0, 0.6);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  z-index: 4;
}

.app-video-note-recorder__error {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  text-align: center;
  color: var(--danger, #ff4d4f);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  background: rgba(0, 0, 0, 0.85);
  z-index: 6;
}

.app-video-note-recorder__actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  width: 100%;
}

.app-video-note-recorder__btn {
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  border: none;
  cursor: pointer;
  font-size: var(--type-h3-size);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  transition:
    transform var(--motion-fast-duration) var(--motion-ease),
    var(--theme-transition);
}

.app-video-note-recorder__btn:hover {
  transform: scale(1.08);
}

.app-video-note-recorder__btn:active {
  transform: scale(0.95);
}

.app-video-note-recorder__btn--cancel {
  width: 40px;
  height: 40px;
  background: var(--surface-secondary, rgba(255, 255, 255, 0.1));
  color: var(--text-secondary, #aaa);
}

.app-video-note-recorder__btn--record {
  width: 52px;
  height: 52px;
  background: transparent;
  border: 3px solid var(--danger, #ff4d4f);
  padding: 3px;
}

.app-video-note-recorder__record-core {
  display: block;
  width: 100%;
  height: 100%;
  border-radius: 50%;
  background: var(--danger, #ff4d4f);
}

.app-video-note-recorder__btn--stop {
  width: 52px;
  height: 52px;
  background: var(--danger, #ff4d4f);
  color: #fff;
}

.app-video-note-recorder__stop-core {
  display: block;
  width: 18px;
  height: 18px;
  border-radius: 3px;
  background: #fff;
}

.app-video-note-recorder__btn--retake {
  width: 44px;
  height: 44px;
  background: var(--surface-secondary, rgba(255, 255, 255, 0.15));
  color: #fff;
  font-size: var(--type-h2-size);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
}

.app-video-note-recorder__btn--send {
  width: 52px;
  height: 52px;
  background: var(--accent, #00d2ff);
  color: #000;
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
}
</style>
