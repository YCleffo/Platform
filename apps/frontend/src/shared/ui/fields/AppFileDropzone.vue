<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import { Book, Upload, X } from '@/shared/ui/icons';
import { i18n } from '@/shared/i18n';

const props = withDefaults(
  defineProps<{
    modelValue?: File | File[] | null;
    accept?: string;
    multiple?: boolean;
    disabled?: boolean;
    busy?: boolean;
    progress?: number | null;
    statusText?: string;
    cancellable?: boolean;
    compact?: boolean;
    label?: string;
    hint?: string;
    error?: string;
  }>(),
  {
    modelValue: null,
    accept: '',
    multiple: false,
    disabled: false,
    busy: false,
    progress: null,
    statusText: '',
    cancellable: false,
    compact: false,
    label: '',
    hint: '',
    error: '',
  },
);

const emit = defineEmits<{
  'update:modelValue': [value: File | File[] | null];
  select: [files: File[]];
  clear: [];
  cancel: [];
}>();

function t(key: string, params?: Record<string, unknown>): string {
  return params ? String(i18n.global.t(key, params)) : String(i18n.global.t(key));
}
const inputId = useId();
const inputEl = ref<HTMLInputElement | null>(null);
const dragging = ref(false);
let dragDepth = 0;

const selectedFiles = computed<File[]>(() => {
  if (!props.modelValue) return [];
  return Array.isArray(props.modelValue) ? props.modelValue : [props.modelValue];
});

const normalizedProgress = computed<number | null>(() => {
  if (props.progress == null || !Number.isFinite(props.progress)) return null;
  return Math.max(0, Math.min(100, Math.round(props.progress)));
});

const showProgress = computed(() => props.busy || normalizedProgress.value !== null);

const helperText = computed(() => {
  if (props.hint) return props.hint;
  if (props.accept) {
    const shortAccept = props.accept
      .split(',')
      .map((item) =>
        item
          .trim()
          .replace(/^[a-z]+\//i, '')
          .replace(/^\./, '')
          .toUpperCase(),
      )
      .filter(Boolean)
      .slice(0, 5)
      .join(', ');
    if (shortAccept) return t('fileDropzone.hint', { accept: shortAccept });
  }
  return t('fileDropzone.hintAny');
});

function formatBytes(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  let value = bytes;
  let idx = 0;
  while (value >= 1024 && idx < units.length - 1) {
    value /= 1024;
    idx += 1;
  }
  return `${value >= 10 || idx === 0 ? Math.round(value) : value.toFixed(1)} ${units[idx]}`;
}

function openPicker(): void {
  if (props.disabled || props.busy) return;
  inputEl.value?.click();
}

function handleFiles(fileList: FileList | null): void {
  if (!fileList || fileList.length === 0 || props.disabled || props.busy) return;
  const first = fileList[0];
  const files: File[] = props.multiple ? Array.from(fileList) : first ? [first] : [];
  if (files.length === 0) return;
  const nextValue: File | File[] | null = props.multiple ? files : (files[0] ?? null);
  emit('update:modelValue', nextValue);
  emit('select', files);
  if (inputEl.value) {
    inputEl.value.value = '';
  }
}

function onInputChange(event: Event): void {
  handleFiles((event.target as HTMLInputElement).files);
}

function onDragEnter(event: DragEvent): void {
  if (props.disabled || props.busy) return;
  event.preventDefault();
  dragDepth += 1;
  dragging.value = true;
}

function onDragOver(event: DragEvent): void {
  if (props.disabled || props.busy) return;
  event.preventDefault();
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = 'copy';
  }
}

function onDragLeave(): void {
  dragDepth = Math.max(0, dragDepth - 1);
  if (dragDepth === 0) dragging.value = false;
}

function onDrop(event: DragEvent): void {
  dragDepth = 0;
  dragging.value = false;
  if (props.disabled || props.busy) return;
  event.preventDefault();
  handleFiles(event.dataTransfer?.files ?? null);
}

function clearSelection(): void {
  if (inputEl.value) inputEl.value.value = '';
  emit('update:modelValue', props.multiple ? [] : null);
  emit('clear');
}
</script>

<template>
  <div
    class="app-dropzone"
    :class="{
      'app-dropzone--dragging': dragging,
      'app-dropzone--compact': compact,
      'app-dropzone--busy': busy,
      'app-dropzone--disabled': disabled,
      'app-dropzone--error': Boolean(error),
    }"
  >
    <label
      v-if="label"
      :for="inputId"
      class="app-dropzone__label"
    >
      {{ label }}
    </label>

    <div
      class="app-dropzone__zone"
      role="button"
      tabindex="0"
      :aria-disabled="disabled || busy || undefined"
      :aria-label="label || (multiple ? t('fileDropzone.titleMultiple') : t('fileDropzone.title'))"
      @click="openPicker"
      @keydown.enter.prevent="openPicker"
      @keydown.space.prevent="openPicker"
      @dragenter="onDragEnter"
      @dragover="onDragOver"
      @dragleave="onDragLeave"
      @drop="onDrop"
    >
      <input
        :id="inputId"
        ref="inputEl"
        type="file"
        class="app-dropzone__input"
        :accept="accept || undefined"
        :multiple="multiple"
        :disabled="disabled || busy"
        @change="onInputChange"
      />

      <div
        class="app-dropzone__icon"
        aria-hidden="true"
      >
        <Upload :size="compact ? 18 : 22" />
      </div>

      <div class="app-dropzone__copy">
        <span class="app-dropzone__title">
          {{ multiple ? t('fileDropzone.titleMultiple') : t('fileDropzone.title') }}
        </span>
        <span class="app-dropzone__hint">{{ helperText }}</span>
      </div>

      <span
        v-if="selectedFiles.length > 0 && !busy"
        class="app-dropzone__replace"
      >
        {{ t('fileDropzone.replace') }}
      </span>
    </div>

    <ul
      v-if="selectedFiles.length > 0"
      class="app-dropzone__files"
    >
      <li
        v-for="(file, idx) in selectedFiles"
        :key="`${file.name}-${file.size}-${idx}`"
        class="app-dropzone__file"
      >
        <Book
          :size="16"
          class="app-dropzone__file-icon"
          aria-hidden="true"
        />
        <span class="app-dropzone__file-name">{{ file.name }}</span>
        <span class="app-dropzone__file-size">{{ formatBytes(file.size) }}</span>
        <button
          v-if="!busy && !disabled"
          type="button"
          class="app-dropzone__clear"
          :aria-label="t('fileDropzone.clear')"
          @click.stop="clearSelection"
        >
          <X :size="14" />
        </button>
      </li>
    </ul>

    <div
      v-if="showProgress"
      class="app-dropzone__progress-wrap"
      aria-live="polite"
    >
      <div class="app-dropzone__progress-head">
        <span class="app-dropzone__progress-text">
          {{ statusText || t('fileDropzone.uploading') }}
        </span>
        <span
          v-if="normalizedProgress !== null"
          class="app-dropzone__progress-pct"
        >
          {{ normalizedProgress }}%
        </span>
        <button
          v-if="cancellable && busy"
          type="button"
          class="app-dropzone__cancel"
          @click.stop="emit('cancel')"
        >
          {{ t('fileDropzone.cancelUpload') }}
        </button>
      </div>
      <div
        class="app-dropzone__track"
        role="progressbar"
        :aria-valuemin="0"
        :aria-valuemax="100"
        :aria-valuenow="normalizedProgress ?? undefined"
      >
        <div
          class="app-dropzone__bar"
          :class="{ 'app-dropzone__bar--indeterminate': normalizedProgress === null }"
          :style="normalizedProgress !== null ? { width: `${normalizedProgress}%` } : undefined"
        />
      </div>
    </div>

    <p
      v-if="error"
      class="app-dropzone__error"
      role="alert"
    >
      {{ error }}
    </p>
  </div>
</template>

<style scoped>
.app-dropzone {
  display: grid;
  gap: 8px;
  min-width: 0;
  width: 100%;
}

.app-dropzone__label {
  font-size: var(--type-body-small-emphasis-size);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-1);
}

.app-dropzone__input {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
  border: 0;
}

.app-dropzone__zone {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 76px;
  padding: 12px 16px;
  border: 1.5px dashed var(--border-strong);
  border-radius: var(--radius-control-m);
  background: var(--surface-2);
  color: var(--text-1);
  cursor: pointer;
  outline: none;
  transition: var(--theme-transition);
}

.app-dropzone--compact .app-dropzone__zone {
  min-height: 54px;
  padding: 10px 14px;
  gap: 10px;
}

.app-dropzone__zone:hover:not([aria-disabled='true']),
.app-dropzone--dragging .app-dropzone__zone {
  border-color: var(--accent);
  background: color-mix(in srgb, var(--accent) 8%, var(--surface-2));
}

.app-dropzone__zone:focus-visible {
  border-style: solid;
  border-color: var(--focus-border);
}

.app-dropzone--error .app-dropzone__zone {
  border-color: var(--danger);
}

.app-dropzone--disabled .app-dropzone__zone,
.app-dropzone--busy .app-dropzone__zone {
  cursor: default;
  opacity: 0.72;
}

.app-dropzone__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 38px;
  height: 38px;
  border-radius: var(--radius-control-s);
  background: color-mix(in srgb, var(--accent) 12%, transparent);
  color: var(--accent);
  transition: var(--theme-transition);
}

.app-dropzone--compact .app-dropzone__icon {
  width: 32px;
  height: 32px;
}

.app-dropzone--dragging .app-dropzone__icon {
  background: var(--accent);
  color: var(--on-accent);
}

.app-dropzone__copy {
  display: grid;
  gap: 2px;
  min-width: 0;
  flex: 1;
}

.app-dropzone__title {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-1);
  overflow-wrap: anywhere;
}

.app-dropzone__hint {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
  overflow-wrap: anywhere;
}

.app-dropzone__replace {
  flex-shrink: 0;
  padding: 4px 10px;
  border-radius: var(--radius-badge-pill);
  background: var(--surface);
  border: 1px solid var(--border);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--accent-text);
  white-space: nowrap;
}

.app-dropzone__files {
  display: grid;
  gap: 6px;
  list-style: none;
  margin: 0;
  padding: 0;
}

.app-dropzone__file {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-control-s);
  background: var(--surface);
  min-width: 0;
}

.app-dropzone__file-icon {
  color: var(--accent);
  flex-shrink: 0;
}

.app-dropzone__file-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-1);
}

.app-dropzone__file-size {
  flex-shrink: 0;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
  font-variant-numeric: tabular-nums;
}

.app-dropzone__clear {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 24px;
  height: 24px;
  padding: 0;
  border: 0;
  border-radius: var(--radius-control-s);
  background: transparent;
  color: var(--text-2);
  cursor: pointer;
  transition: var(--theme-transition);
}

.app-dropzone__clear:hover {
  background: var(--surface-hover);
  color: var(--danger);
}

.app-dropzone__progress-wrap {
  display: grid;
  gap: 6px;
  padding: 8px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-control-s);
  background: var(--surface);
}

.app-dropzone__progress-head {
  display: flex;
  align-items: center;
  gap: 8px;
}

.app-dropzone__progress-text {
  flex: 1;
  min-width: 0;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
}

.app-dropzone__progress-pct {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--accent-text);
  font-variant-numeric: tabular-nums;
}

.app-dropzone__cancel {
  border: 1px solid var(--border);
  background: transparent;
  color: var(--danger-text);
  border-radius: var(--radius-control-s);
  padding: 2px 8px;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-small-emphasis-weight);
  cursor: pointer;
  transition: var(--theme-transition);
}

.app-dropzone__track {
  position: relative;
  height: 6px;
  border-radius: var(--radius-badge-pill);
  background: var(--surface-2);
  overflow: hidden;
}

.app-dropzone__bar {
  height: 100%;
  border-radius: var(--radius-badge-pill);
  background: var(--accent);
  transition: var(--theme-transition);
}

.app-dropzone__bar--indeterminate {
  width: 38%;
  animation: app-dropzone-slide var(--motion-skeleton-duration) infinite var(--motion-pulse-ease);
}

@keyframes app-dropzone-slide {
  0% {
    transform: translateX(-110%);
  }
  100% {
    transform: translateX(280%);
  }
}

.app-dropzone__error {
  margin: 0;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--danger-text);
}
</style>
