<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AudioWaveform from './AudioWaveform.vue';

const props = withDefaults(
  defineProps<{
    maxDurationSeconds?: number;
  }>(),
  {
    maxDurationSeconds: 300, // 5 minutes max
  },
);

const emit = defineEmits<{
  (e: 'record-complete', payload: { blob: Blob; durationSeconds: number; mimeType: string }): void;
  (e: 'cancel'): void;
}>();

const { t } = useI18n();

const isRecording = ref(false);
const isPaused = ref(false);
const recordingSeconds = ref(0);
const timerId = ref<number | null>(null);
const recordedBlob = ref<Blob | null>(null);
const previewUrl = ref<string | null>(null);
const errorMessage = ref<string | null>(null);

let mediaRecorder: MediaRecorder | null = null;
let streamInstance: MediaStream | null = null;
let chunks: Blob[] = [];

function getSupportedMimeType(): string {
  const types = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
    'audio/ogg',
    'audio/mp4',
    'audio/aac',
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

async function startRecording(): Promise<void> {
  errorMessage.value = null;
  recordedBlob.value = null;
  previewUrl.value = null;
  chunks = [];
  recordingSeconds.value = 0;

  if (!navigator.mediaDevices?.getUserMedia) {
    errorMessage.value = t('media.micUnavailable');
    return;
  }

  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    streamInstance = stream;
    const mimeType = getSupportedMimeType();
    const options = mimeType ? { mimeType } : undefined;
    mediaRecorder = new MediaRecorder(stream, options);

    mediaRecorder.ondataavailable = (e) => {
      if (e.data && e.data.size > 0) {
        chunks.push(e.data);
      }
    };

    mediaRecorder.onstop = () => {
      const type = mediaRecorder?.mimeType || 'audio/webm';
      const blob = new Blob(chunks, { type });
      recordedBlob.value = blob;
      previewUrl.value = URL.createObjectURL(blob);
      cleanupStream();
    };

    mediaRecorder.start(250);
    isRecording.value = true;
    isPaused.value = false;

    timerId.value = window.setInterval(() => {
      if (!isPaused.value) {
        recordingSeconds.value++;
        if (recordingSeconds.value >= props.maxDurationSeconds) {
          stopRecording();
        }
      }
    }, 1000);
  } catch (err) {
    errorMessage.value = t('media.micUnavailable');
    cleanupStream();
  }
}

function pauseRecording(): void {
  if (mediaRecorder && mediaRecorder.state === 'recording') {
    mediaRecorder.pause();
    isPaused.value = true;
  }
}

function resumeRecording(): void {
  if (mediaRecorder && mediaRecorder.state === 'paused') {
    mediaRecorder.resume();
    isPaused.value = false;
  }
}

function stopRecording(): void {
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    mediaRecorder.stop();
  }
  isRecording.value = false;
  isPaused.value = false;
}

function cancelRecording(): void {
  if (mediaRecorder && mediaRecorder.state !== 'inactive') {
    mediaRecorder.stop();
  }
  cleanupStream();
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
  }
  recordedBlob.value = null;
  isRecording.value = false;
  isPaused.value = false;
  recordingSeconds.value = 0;
  emit('cancel');
}

function handleSend(): void {
  if (!recordedBlob.value) return;
  const mime = recordedBlob.value.type || 'audio/webm';
  emit('record-complete', {
    blob: recordedBlob.value,
    durationSeconds: Math.max(1, recordingSeconds.value),
    mimeType: mime,
  });
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
  }
  recordedBlob.value = null;
  recordingSeconds.value = 0;
}

const formattedTime = computed(() => {
  const m = Math.floor(recordingSeconds.value / 60);
  const s = recordingSeconds.value % 60;
  return `${m}:${s < 10 ? '0' : ''}${s}`;
});

onBeforeUnmount(() => {
  cleanupStream();
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value);
  }
});
</script>

<template>
  <div class="app-audio-recorder">
    <!-- Initial Idle State -->
    <button
      v-if="!isRecording && !recordedBlob"
      type="button"
      class="app-audio-recorder__btn app-audio-recorder__btn--start"
      :aria-label="t('media.record')"
      @click="startRecording"
    >
      <svg
        class="app-audio-recorder__icon"
        viewBox="0 0 24 24"
        fill="currentColor"
        aria-hidden="true"
      >
        <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z" />
        <path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z" />
      </svg>
    </button>

    <!-- Active Recording State -->
    <div v-else-if="isRecording" class="app-audio-recorder__active">
      <div class="app-audio-recorder__pulse" :class="{ 'app-audio-recorder__pulse--paused': isPaused }" />
      <span class="app-audio-recorder__timer">{{ formattedTime }}</span>

      <div class="app-audio-recorder__waveform-wrapper">
        <AudioWaveform :progress="1" :bar-count="24" :interactive="false" />
      </div>

      <button
        v-if="!isPaused"
        type="button"
        class="app-audio-recorder__control-btn"
        :aria-label="t('media.pause')"
        @click="pauseRecording"
      >
        <svg class="app-audio-recorder__icon-sm" viewBox="0 0 24 24" fill="currentColor">
          <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z" />
        </svg>
      </button>

      <button
        v-else
        type="button"
        class="app-audio-recorder__control-btn"
        :aria-label="t('media.play')"
        @click="resumeRecording"
      >
        <svg class="app-audio-recorder__icon-sm" viewBox="0 0 24 24" fill="currentColor">
          <path d="M8 5v14l11-7z" />
        </svg>
      </button>

      <button
        type="button"
        class="app-audio-recorder__control-btn app-audio-recorder__control-btn--stop"
        :aria-label="t('media.stop')"
        @click="stopRecording"
      >
        <svg class="app-audio-recorder__icon-sm" viewBox="0 0 24 24" fill="currentColor">
          <path d="M6 6h12v12H6z" />
        </svg>
      </button>

      <button
        type="button"
        class="app-audio-recorder__control-btn app-audio-recorder__control-btn--cancel"
        :aria-label="t('media.cancel')"
        @click="cancelRecording"
      >
        <svg class="app-audio-recorder__icon-sm" viewBox="0 0 24 24" fill="currentColor">
          <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" />
        </svg>
      </button>
    </div>

    <!-- Review / Preview State Before Send -->
    <div v-else-if="recordedBlob" class="app-audio-recorder__preview">
      <audio v-if="previewUrl" :src="previewUrl" controls class="app-audio-recorder__audio-preview" />
      <button
        type="button"
        class="app-audio-recorder__action-btn app-audio-recorder__action-btn--discard"
        @click="cancelRecording"
      >
        {{ t('media.discard') }}
      </button>
      <button
        type="button"
        class="app-audio-recorder__action-btn app-audio-recorder__action-btn--send"
        @click="handleSend"
      >
        {{ t('media.send') }}
      </button>
    </div>

    <!-- Error State -->
    <div v-if="errorMessage" class="app-audio-recorder__error" role="alert">
      {{ errorMessage }}
    </div>
  </div>
</template>

<style scoped>
.app-audio-recorder {
  display: flex;
  align-items: center;
  position: relative;
}

.app-audio-recorder__btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  border: none;
  background-color: var(--surface-secondary, rgba(255, 255, 255, 0.08));
  color: var(--text-primary, #fff);
  cursor: pointer;
  transition: transform var(--theme-transition), background-color var(--theme-transition);
}

.app-audio-recorder__btn:hover {
  background-color: var(--accent, #00d2ff);
  color: #000;
  transform: scale(1.05);
}

.app-audio-recorder__icon {
  width: 20px;
  height: 20px;
}

.app-audio-recorder__active {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--surface-primary, #1e222b);
  border: 1px solid var(--accent, #00d2ff);
  border-radius: var(--radius-card-m, 12px);
  padding: 6px 12px;
  box-shadow: var(--shadow-soft, 0 2px 8px rgba(0, 0, 0, 0.2));
}

.app-audio-recorder__pulse {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background-color: #ff4757;
  animation: pulse var(--motion-spinner-duration) var(--motion-linear) infinite alternate;
}

.app-audio-recorder__pulse--paused {
  background-color: #ffa502;
  animation: none;
}

@keyframes pulse {
  from { opacity: 0.4; transform: scale(0.85); }
  to { opacity: 1; transform: scale(1.15); }
}

.app-audio-recorder__timer {
  font-size: var(--type-detail-size, 11px);
  line-height: var(--type-detail-line, 14px);
  font-weight: var(--type-detail-weight, 500);
  font-family: var(--font-mono, monospace);
  color: var(--text-primary, #fff);
  min-width: 36px;
}

.app-audio-recorder__waveform-wrapper {
  width: 80px;
}

.app-audio-recorder__control-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: none;
  background: var(--surface-secondary, rgba(255, 255, 255, 0.1));
  color: var(--text-primary, #fff);
  cursor: pointer;
  transition: background-color var(--theme-transition);
}

.app-audio-recorder__control-btn:hover {
  background: var(--surface-tertiary, rgba(255, 255, 255, 0.2));
}

.app-audio-recorder__control-btn--stop:hover {
  background: #ff4757;
}

.app-audio-recorder__control-btn--cancel:hover {
  background: rgba(255, 71, 87, 0.2);
  color: #ff4757;
}

.app-audio-recorder__icon-sm {
  width: 14px;
  height: 14px;
}

.app-audio-recorder__preview {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--surface-primary, #1e222b);
  border: 1px solid var(--border-default, rgba(255, 255, 255, 0.15));
  border-radius: var(--radius-card-m, 12px);
  padding: 6px 12px;
}

.app-audio-recorder__audio-preview {
  height: 32px;
  max-width: 200px;
}

.app-audio-recorder__action-btn {
  border: none;
  border-radius: var(--radius-card-s, 4px);
  padding: 6px 10px;
  font-size: var(--type-detail-size, 11px);
  line-height: var(--type-detail-line, 14px);
  font-weight: var(--type-detail-weight, 500);
  cursor: pointer;
  transition: opacity var(--theme-transition);
}

.app-audio-recorder__action-btn--discard {
  background: var(--surface-secondary, rgba(255, 255, 255, 0.08));
  color: var(--text-secondary, #9ba3af);
}

.app-audio-recorder__action-btn--send {
  background: var(--accent, #00d2ff);
  color: #000;
}

.app-audio-recorder__error {
  position: absolute;
  top: 100%;
  left: 0;
  margin-top: 4px;
  font-size: var(--type-detail-size, 11px);
  line-height: var(--type-detail-line, 14px);
  font-weight: var(--type-detail-weight, 400);
  color: #ff4757;
  white-space: nowrap;
}
</style>
