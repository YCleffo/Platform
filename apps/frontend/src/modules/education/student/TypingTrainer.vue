<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { studentPortalApi, type TypingExercise, type TypingInput, type TypingResult } from './api';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import { ApiError } from '@/shared/api/errors';
import { homeworkApi } from '../homework/api';
import { invalidateEducationCache } from '../cache';
import { useEducationI18n } from '../i18n';
import { useEducationError } from '../resources';
import ActivityConfigFields from '../ActivityConfigFields.vue';
import { defaultActivityConfig, type ActivityConfig } from '../activityTypes';

const props = defineProps<{
  organizationId: string;
  homeworkId?: string;
  assignmentId?: string;
  assignedConfig?: ActivityConfig;
}>();

const emit = defineEmits<{ (event: 'finished', result: TypingResult): void }>();
const { t } = useEducationI18n();
const issue = useEducationError();
const assigned = computed(() => !!(props.homeworkId || props.assignmentId));
const inputId = useId();

const config = ref<ActivityConfig>(defaultActivityConfig());
const status = ref<'idle' | 'running' | 'finished' | 'saving' | 'failed'>('idle');
const exercise = ref<TypingExercise | null>(null);
const typed = ref('');
const startedAt = ref(0);
const elapsed = ref(0);
const actionError = ref<unknown>(null);
const result = ref<TypingResult | null>(null);
const input = ref<HTMLTextAreaElement | null>(null);
const textBoard = ref<HTMLElement | null>(null);
const focused = ref(false);
const composing = ref(false);
const selection = ref(0);
const corrections = ref(0);
const inputErrors = ref(0);
const focusMode = ref(false);

// Typing 3.0 metrics & telemetry
interface WpmSample {
  second: number;
  wpm: number;
  rawWpm: number;
  errors: number;
}
const wpmSamples = ref<WpmSample[]>([]);
let lastSampleSecond = 0;

let timer: number | null = null;
let observer: ResizeObserver | null = null;
let generation = 0;
let finishValues: (TypingInput & { durationSeconds: number }) | null = null;
let stateSaved = false;
let stateController: AbortController | null = null;

watch(
  [
    () => props.organizationId,
    () => props.homeworkId,
    () => props.assignmentId,
    coreAdminSessionEpoch,
  ],
  () => {
    generation++;
    stopTimer();
    stateController?.abort();
    stateController = null;
    exercise.value = null;
    typed.value = '';
    result.value = null;
    status.value = 'idle';
    actionError.value = null;
    finishValues = null;
    stateSaved = false;
    composing.value = false;
    wpmSamples.value = [];
    lastSampleSecond = 0;
  },
  { flush: 'sync' },
);

watch(
  () => props.assignedConfig,
  (value) => {
    if (value) config.value = { ...defaultActivityConfig(), ...value };
  },
  { immediate: true },
);

// Unicode Grapheme Cluster Tokenization
function segmentText(str: string, locale = 'en'): string[] {
  if (typeof Intl !== 'undefined' && 'Segmenter' in Intl) {
    const segmenter = new Intl.Segmenter(locale === 'ru' ? 'ru' : 'en', {
      granularity: 'grapheme',
    });
    return Array.from(segmenter.segment(str), (s) => s.segment);
  }
  return [...str];
}

const text = computed(() => exercise.value?.exercise.text ?? '');
const targetCharacters = computed(() => segmentText(text.value, config.value.language));
const typedCharacters = computed(() => segmentText(typed.value, config.value.language));

const correct = computed(() => {
  const target = targetCharacters.value;
  return typedCharacters.value.reduce(
    (count, char, index) => count + (char === target[index] ? 1 : 0),
    0,
  );
});

const errors = computed(() => Math.max(0, typedCharacters.value.length - correct.value));

const wpm = computed(() => {
  const minutes = Math.max(elapsed.value, 1) / 60;
  return Math.round((correct.value / 5 / minutes) * 10) / 10;
});

const rawWpm = computed(() => {
  const minutes = Math.max(elapsed.value, 1) / 60;
  return Math.round((typedCharacters.value.length / 5 / minutes) * 10) / 10;
});

const accuracy = computed(() =>
  typedCharacters.value.length
    ? Math.round((correct.value / typedCharacters.value.length) * 1000) / 10
    : 100,
);

const consistency = computed(() => {
  if (wpmSamples.value.length < 2) return 100;
  const values = wpmSamples.value.map((s) => s.wpm);
  const mean = values.reduce((a, b) => a + b, 0) / values.length;
  if (mean === 0) return 100;
  const variance = values.reduce((a, b) => a + Math.pow(b - mean, 2), 0) / values.length;
  const stdDev = Math.sqrt(variance);
  return Math.max(0, Math.min(100, Math.round((1 - stdDev / mean) * 100)));
});

const remaining = computed(() =>
  Math.max(0, (exercise.value?.exercise.durationSeconds ?? 0) - Math.floor(elapsed.value)),
);

function stopTimer(): void {
  if (timer !== null) {
    window.clearInterval(timer);
    timer = null;
  }
}

function tick(): void {
  elapsed.value = Math.min(
    (Date.now() - startedAt.value) / 1000,
    exercise.value?.exercise.durationSeconds ?? 30,
  );

  const curSec = Math.floor(elapsed.value);
  if (curSec > lastSampleSecond) {
    lastSampleSecond = curSec;
    wpmSamples.value.push({
      second: curSec,
      wpm: wpm.value,
      rawWpm: rawWpm.value,
      errors: errors.value,
    });
  }

  if (remaining.value <= 0) void finish();
}

async function start(next = false): Promise<void> {
  if (status.value === 'running' || status.value === 'saving') return;
  const version = generation,
    epoch = coreAdminSession.epoch();
  const current = () => generation === version && coreAdminSession.epoch() === epoch;
  if (next && !assigned.value)
    config.value = {
      ...config.value,
      difficulty: Math.min(3, (config.value.difficulty ?? 1) + 1) as 1 | 2 | 3,
    };
  status.value = 'saving';
  actionError.value = null;
  result.value = null;
  exercise.value = null;
  finishValues = null;
  stateSaved = false;
  wpmSamples.value = [];
  lastSampleSecond = 0;

  try {
    const started = props.homeworkId
      ? await homeworkApi.startActivity(props.organizationId, props.homeworkId)
      : await studentPortalApi.startAttempt(props.organizationId, 'platform-typing', {
          ...config.value,
          durationSeconds: [15, 30, 60].includes(Number(config.value.durationSeconds))
            ? (Number(config.value.durationSeconds) as 15 | 30 | 60)
            : 30,
          ...(props.assignmentId ? { assignmentId: props.assignmentId } : {}),
        });
    if (!current()) return;
    exercise.value = started as TypingExercise;
    typed.value = '';
    corrections.value = 0;
    inputErrors.value = 0;
    selection.value = 0;
    composing.value = false;
    startedAt.value = Date.now();
    elapsed.value = 0;
    status.value = 'running';
    stopTimer();
    timer = window.setInterval(tick, 100);
    await nextTick();
    if (current()) {
      input.value?.focus();
      observeBoard();
    }
  } catch (error) {
    if (current()) {
      actionError.value = error;
      status.value = 'failed';
    }
  }
}

async function finish(): Promise<void> {
  if (status.value !== 'running' || !exercise.value) return;
  if (composing.value && input.value) acceptInput(input.value.value);
  stopTimer();
  elapsed.value = Math.min(
    (Date.now() - startedAt.value) / 1000,
    exercise.value.exercise.durationSeconds,
  );
  finishValues = {
    typed: typed.value,
    durationSeconds: exercise.value.exercise.durationSeconds,
    corrections: corrections.value,
    inputErrors: inputErrors.value,
  };
  await saveResult();
}

async function saveResult(): Promise<void> {
  if (!exercise.value || !finishValues || status.value === 'saving') return;
  const attempt = exercise.value.attempt;
  const payload = finishValues;
  const version = generation,
    epoch = coreAdminSession.epoch();
  const current = () => generation === version && coreAdminSession.epoch() === epoch;
  status.value = 'saving';
  actionError.value = null;
  try {
    if (!stateSaved) {
      const request = new AbortController();
      stateController = request;
      const state = {
        typed: payload.typed,
        corrections: payload.corrections,
        inputErrors: payload.inputErrors,
      };
      try {
        await studentPortalApi.saveTypingState(
          props.organizationId,
          attempt.id,
          attempt.stateVersion ?? 0,
          state,
          request.signal,
        );
      } catch (error) {
        if (!current() || !(error instanceof ApiError) || error.status !== 409) throw error;
        const latest = await studentPortalApi.typingAttempt(
          props.organizationId,
          attempt.id,
          request.signal,
        );
        const saved = latest.attempt.state;
        if (
          latest.attempt.status !== 'running' ||
          saved?.typed !== state.typed ||
          saved.corrections !== state.corrections ||
          saved.inputErrors !== state.inputErrors
        )
          throw error;
      } finally {
        if (stateController === request) stateController = null;
      }
      if (!current()) return;
      stateSaved = true;
    }
    const saved = await studentPortalApi.finishAttempt(props.organizationId, attempt.id, payload);
    if (!current()) return;
    result.value = saved;
    status.value = 'finished';
    invalidateEducationCache([
      'homework',
      'student',
      'students',
      'groups',
      'lessons',
      'messages',
      'header',
    ]);
    emit('finished', saved);
  } catch (error) {
    if (current()) {
      actionError.value = error;
      status.value = 'failed';
    }
  }
}

function acceptInput(value: string): void {
  const before = typed.value;
  let prefix = 0,
    suffix = 0;
  while (prefix < before.length && prefix < value.length && before[prefix] === value[prefix])
    prefix++;
  while (
    suffix < before.length - prefix &&
    suffix < value.length - prefix &&
    before[before.length - 1 - suffix] === value[value.length - 1 - suffix]
  )
    suffix++;
  corrections.value += before.length - prefix - suffix;
  for (let index = prefix; index < value.length - suffix; index++)
    if (value[index] !== text.value[index]) inputErrors.value++;
  typed.value = value;
  updateSelection();
}

function onInput(event: Event): void {
  if (status.value !== 'running' || composing.value || (event as InputEvent).isComposing) return;
  acceptInput((event.target as HTMLTextAreaElement).value);
}

function compositionEnd(event: CompositionEvent): void {
  composing.value = false;
  if (status.value === 'running') acceptInput((event.target as HTMLTextAreaElement).value);
}

function updateSelection(): void {
  selection.value = input.value?.selectionStart ?? typed.value.length;
  void nextTick(scrollCaret);
}

function scrollCaret(): void {
  const board = textBoard.value,
    caret = board?.querySelector<HTMLElement>('.edu-typing__char--caret');
  if (!board || !caret) return;
  const top = caret.offsetTop - board.offsetTop;
  if (top < board.scrollTop || top + caret.offsetHeight > board.scrollTop + board.clientHeight)
    board.scrollTop = Math.max(0, top - board.clientHeight / 2);
}

function observeBoard(): void {
  observer?.disconnect();
  if (typeof ResizeObserver !== 'undefined' && textBoard.value) {
    observer = new ResizeObserver(scrollCaret);
    observer.observe(textBoard.value);
  }
}

function charClass(index: number): string[] {
  return [
    index === selection.value ? 'edu-typing__char--caret' : '',
    index < typed.value.length
      ? typed.value[index] === text.value[index]
        ? 'edu-typing__char--ok'
        : 'edu-typing__char--wrong'
      : '',
  ];
}

// Quick restart with Tab + Enter
function handleKeydown(event: KeyboardEvent): void {
  if (event.key === 'Tab') {
    event.preventDefault();
    if (status.value === 'running' || status.value === 'finished') {
      void start();
    }
  }
}

onMounted(() => {
  window.addEventListener('resize', scrollCaret);
  window.addEventListener('keydown', handleKeydown);
});

onBeforeUnmount(() => {
  generation++;
  stopTimer();
  stateController?.abort();
  observer?.disconnect();
  window.removeEventListener('resize', scrollCaret);
  window.removeEventListener('keydown', handleKeydown);
});
</script>

<template>
  <div
    class="edu-typing"
    :class="{ 'edu-typing--focus-mode': focusMode && status === 'running' }"
  >
    <!-- Monkeytype Mode Control Bar -->
    <div
      v-if="!assigned && (!exercise || status === 'finished')"
      class="edu-typing__mode-bar"
    >
      <div class="edu-typing__mode-group">
        <button
          v-for="mode in ['words', 'sentences', 'numbers', 'code', 'accuracy'] as const"
          :key="mode"
          type="button"
          class="edu-typing__mode-btn"
          :class="{ 'edu-typing__mode-btn--active': config.mode === mode }"
          @click="config.mode = mode"
        >
          {{ t(`education.typingMode_${mode}`) }}
        </button>
      </div>

      <div class="edu-typing__mode-group">
        <button
          v-for="duration in [15, 30, 60] as const"
          :key="duration"
          type="button"
          class="edu-typing__mode-btn"
          :class="{ 'edu-typing__mode-btn--active': config.durationSeconds === duration }"
          @click="config.durationSeconds = duration"
        >
          {{ duration }}s
        </button>
      </div>

      <div class="edu-typing__mode-group">
        <button
          v-for="lang in ['en', 'ru'] as const"
          :key="lang"
          type="button"
          class="edu-typing__mode-btn"
          :class="{ 'edu-typing__mode-btn--active': config.language === lang }"
          @click="config.language = lang"
        >
          {{ lang.toUpperCase() }}
        </button>
      </div>
    </div>

    <!-- Pre-test Card & Settings -->
    <div
      v-if="!exercise || status === 'finished'"
      class="edu-card"
    >
      <ActivityConfigFields
        v-model="config"
        :disabled="assigned || status === 'saving'"
      />
      <p class="edu-subtle">{{ t('education.typingHint') }}</p>
      <button
        v-if="status !== 'finished'"
        type="button"
        class="edu-button edu-button--primary"
        :disabled="status === 'saving'"
        @click="start()"
      >
        {{ t('education.startTraining') }}
      </button>
    </div>

    <!-- Error Alert -->
    <p
      v-if="actionError"
      class="edu-error"
      role="alert"
    >
      {{ issue(actionError) }}
    </p>

    <!-- Retry Save Button -->
    <button
      v-if="status === 'failed' && finishValues"
      type="button"
      class="edu-button edu-button--primary"
      @click="saveResult"
    >
      {{ t('education.retrySave') }}
    </button>

    <!-- Active Typing Board -->
    <div
      v-if="exercise && status !== 'finished'"
      class="edu-card edu-typing__board"
      :class="{ 'edu-typing__board--focused': focused }"
    >
      <!-- Telemetry Stats Strip -->
      <div
        class="edu-typing__stats"
        aria-live="off"
      >
        <strong>{{ remaining }} {{ t('education.seconds') }}</strong>
        <span class="edu-typing__stat-pill">{{ wpm }} WPM</span>
        <span class="edu-typing__stat-pill">{{ rawWpm }} {{ t('education.rawWpm') }}</span>
        <span class="edu-typing__stat-pill">{{ accuracy }}%</span>
        <span class="edu-typing__stat-pill">
          {{ consistency }}% {{ t('education.consistency') }}
        </span>
        <span>{{ t('education.errors') }}: {{ errors }}</span>
      </div>

      <!-- Text Display Surface -->
      <p
        :id="`${inputId}-target`"
        ref="textBoard"
        class="edu-typing__text"
        :aria-label="text"
      >
        <span
          v-for="(char, index) in text"
          :key="index"
          :class="charClass(index)"
          aria-hidden="true"
        >
          {{ char }}
        </span>
        <span
          v-if="selection >= text.length"
          class="edu-typing__char--caret"
          aria-hidden="true"
        >
          &nbsp;
        </span>
      </p>

      <!-- Hidden / Native Input Area -->
      <label
        :for="inputId"
        class="edu-caption"
      >
        {{ t('education.typingInput') }}
      </label>
      <textarea
        :id="inputId"
        ref="input"
        class="edu-input edu-typing__input"
        :value="typed"
        :aria-label="t('education.typingInput')"
        :aria-describedby="`${inputId}-target ${inputId}-hint`"
        :disabled="status !== 'running'"
        :maxlength="text.length + 200"
        rows="3"
        autocomplete="off"
        autocapitalize="off"
        :spellcheck="false"
        :lang="exercise.exercise.language"
        @input="onInput"
        @compositionstart="composing = true"
        @compositionend="compositionEnd"
        @select="updateSelection"
        @click="updateSelection"
        @keyup="updateSelection"
        @focus="focused = true"
        @blur="focused = false"
      />

      <!-- Quick Hint -->
      <p
        :id="`${inputId}-hint`"
        class="edu-caption"
      >
        {{ focused ? t('education.typingEditHint') : t('education.typingFocusHint') }}
        {{ t('education.timerContinues') }} ·
        <kbd class="edu-kbd">{{ t('education.restartHint') }}</kbd>
      </p>

      <!-- Action Buttons -->
      <button
        v-if="status === 'running'"
        type="button"
        class="edu-button"
        @click="finish"
      >
        {{ t('education.finishTraining') }}
      </button>

      <p
        v-if="status === 'saving'"
        role="status"
      >
        {{ t('education.loading') }}
      </p>
    </div>

    <!-- Enhanced Result Screen with Speed Graph & Detailed Telemetry -->
    <div
      v-if="status === 'finished' && result"
      class="edu-card edu-typing__result"
      role="status"
    >
      <h2>{{ t('education.trainingResult') }}</h2>

      <!-- Big Key Metrics Grid -->
      <div class="edu-typing__result-grid">
        <div class="edu-typing__metric-card">
          <span class="edu-typing__metric-val edu-typing__metric-val--primary">
            {{ result.wpm }}
          </span>
          <span class="edu-typing__metric-label">WPM</span>
        </div>
        <div class="edu-typing__metric-card">
          <span class="edu-typing__metric-val">{{ result.accuracy }}%</span>
          <span class="edu-typing__metric-label">{{ t('education.finalAccuracy') }}</span>
        </div>
        <div class="edu-typing__metric-card">
          <span class="edu-typing__metric-val">{{ rawWpm }}</span>
          <span class="edu-typing__metric-label">{{ t('education.rawWpm') }}</span>
        </div>
        <div class="edu-typing__metric-card">
          <span class="edu-typing__metric-val">{{ consistency }}%</span>
          <span class="edu-typing__metric-label">{{ t('education.consistency') }}</span>
        </div>
      </div>

      <!-- Interactive SVG Speed Graph -->
      <div
        v-if="wpmSamples.length > 1"
        class="edu-typing__graph-wrap"
      >
        <span class="edu-caption">{{ t('education.speedGraph') }}</span>
        <svg
          class="edu-typing__graph"
          viewBox="0 0 500 120"
          preserveAspectRatio="none"
        >
          <!-- Grid lines -->
          <line
            x1="0"
            y1="30"
            x2="500"
            y2="30"
            stroke="var(--border)"
            stroke-dasharray="3 3"
          />
          <line
            x1="0"
            y1="60"
            x2="500"
            y2="60"
            stroke="var(--border)"
            stroke-dasharray="3 3"
          />
          <line
            x1="0"
            y1="90"
            x2="500"
            y2="90"
            stroke="var(--border)"
            stroke-dasharray="3 3"
          />

          <!-- Raw WPM line (faded) -->
          <polyline
            fill="none"
            stroke="var(--text-2)"
            stroke-width="1.5"
            stroke-dasharray="2 2"
            :points="
              wpmSamples
                .map((s, idx) => {
                  const x = (idx / (wpmSamples.length - 1)) * 480 + 10;
                  const maxVal = Math.max(...wpmSamples.map((p) => Math.max(p.rawWpm, p.wpm, 10)));
                  const y = 110 - (s.rawWpm / maxVal) * 90;
                  return `${x},${y}`;
                })
                .join(' ')
            "
          />

          <!-- Net WPM line (solid accent) -->
          <polyline
            fill="none"
            stroke="var(--accent)"
            stroke-width="3"
            stroke-linecap="round"
            stroke-linejoin="round"
            :points="
              wpmSamples
                .map((s, idx) => {
                  const x = (idx / (wpmSamples.length - 1)) * 480 + 10;
                  const maxVal = Math.max(...wpmSamples.map((p) => Math.max(p.rawWpm, p.wpm, 10)));
                  const y = 110 - (s.wpm / maxVal) * 90;
                  return `${x},${y}`;
                })
                .join(' ')
            "
          />
        </svg>
      </div>

      <p>
        {{ t('education.typedCharacters') }}: {{ result.typedCharacters ?? result.keystrokes }} ·
        {{ result.durationSeconds }} {{ t('education.seconds') }} · {{ t('education.errors') }}:
        {{ result.errors }}
      </p>
      <p class="edu-caption">
        {{ t('education.corrections') }}: {{ corrections }} · {{ t('education.observedErrors') }}:
        {{ inputErrors }}. {{ t('education.clientTelemetry') }}
      </p>

      <div class="edu-form-actions">
        <button
          type="button"
          class="edu-button"
          @click="start()"
        >
          {{ t('education.trainAgain') }}
        </button>
        <button
          v-if="!assigned"
          type="button"
          class="edu-button edu-button--primary"
          @click="start(true)"
        >
          {{ t('education.nextExercise') }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.edu-typing__mode-bar {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1.5rem;
  padding: 0.5rem 1rem;
  background: var(--surface-2);
  border-radius: 9999px;
  border: 1px solid var(--border);
  margin-bottom: 0.5rem;
  flex-wrap: wrap;
}

.edu-typing__mode-group {
  display: flex;
  gap: 0.25rem;
}

.edu-typing__mode-btn {
  background: transparent;
  border: none;
  color: var(--text-2);
  font-size: var(--type-body-small-emphasis-size);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
  padding: 0.25rem 0.625rem;
  border-radius: 9999px;
  cursor: pointer;
  transition: var(--theme-transition);
}

.edu-typing__mode-btn:hover {
  color: var(--text-1);
}

.edu-typing__mode-btn--active {
  background: var(--accent-soft);
  color: var(--accent);
  font-size: var(--type-body-small-emphasis-size);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
}

.edu-typing__stat-pill {
  background: var(--surface);
  border: 1px solid var(--border);
  padding: 0.125rem 0.5rem;
  border-radius: 4px;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}

.edu-kbd {
  background: var(--surface-2);
  border: 1px solid var(--border);
  padding: 0.125rem 0.375rem;
  border-radius: 4px;
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}

.edu-typing__result-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
  gap: 0.75rem;
  margin: 1rem 0;
}

.edu-typing__metric-card {
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 1rem;
  text-align: center;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.edu-typing__metric-val {
  font-size: var(--type-metric-number-size);
  line-height: var(--type-metric-number-line);
  font-weight: var(--type-metric-number-weight);
}

.edu-typing__metric-val--primary {
  color: var(--accent);
}

.edu-typing__metric-label {
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
  color: var(--text-2);
}

.edu-typing__graph-wrap {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  margin: 1rem 0;
}

.edu-typing__graph {
  width: 100%;
  height: 90px;
  background: var(--surface-2);
  border-radius: 6px;
  border: 1px solid var(--border);
}
</style>
