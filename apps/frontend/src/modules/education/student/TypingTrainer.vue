<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { studentPortalApi, type TypingExercise, type TypingResult } from './api';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import { homeworkApi } from '../homework/api';
import { invalidateEducationCache } from '../cache';
import { useEducationI18n } from '../i18n';
import { useEducationError } from '../resources';
import ActivityConfigFields from '../ActivityConfigFields.vue';
import { defaultActivityConfig, type ActivityConfig } from '../activityTypes';

const props = defineProps<{
  organizationId: string;
  homeworkId?: string;
  assignedConfig?: ActivityConfig;
}>();
const emit = defineEmits<{ (event: 'finished', result: TypingResult): void }>();
const { t } = useEducationI18n();
const issue = useEducationError();
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
let timer: number | null = null;
let observer: ResizeObserver | null = null;
let generation = 0;
let finishValues: {
  typed: string;
  durationSeconds: number;
  corrections: number;
  inputErrors: number;
} | null = null;
watch(
  [() => props.organizationId, () => props.homeworkId, coreAdminSessionEpoch],
  () => {
    generation++;
    stopTimer();
    exercise.value = null;
    typed.value = '';
    result.value = null;
    status.value = 'idle';
    actionError.value = null;
    finishValues = null;
    composing.value = false;
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
const text = computed(() => exercise.value?.exercise.text ?? '');
const targetCharacters = computed(() => [...text.value]);
const correct = computed(() =>
  [...typed.value].reduce(
    (count, char, index) => count + (char === targetCharacters.value[index] ? 1 : 0),
    0,
  ),
);
const errors = computed(() => [...typed.value].length - correct.value);
const wpm = computed(() => Math.round(correct.value / 5 / (Math.max(elapsed.value, 1) / 60)));
const accuracy = computed(() =>
  typed.value.length ? Math.round((correct.value / [...typed.value].length) * 1000) / 10 : 100,
);
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
  if (remaining.value <= 0) void finish();
}
async function start(next = false): Promise<void> {
  if (status.value === 'running' || status.value === 'saving') return;
  const version = generation,
    epoch = coreAdminSession.epoch();
  const current = () => generation === version && coreAdminSession.epoch() === epoch;
  if (next && !props.homeworkId)
    config.value = {
      ...config.value,
      difficulty: Math.min(3, (config.value.difficulty ?? 1) + 1) as 1 | 2 | 3,
    };
  status.value = 'saving';
  actionError.value = null;
  result.value = null;
  exercise.value = null;
  finishValues = null;
  try {
    const started = props.homeworkId
      ? await homeworkApi.startActivity(props.organizationId, props.homeworkId)
      : await studentPortalApi.startAttempt(props.organizationId, 'platform-typing', {
          ...config.value,
          durationSeconds: config.value.durationSeconds ?? 30,
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
  const version = generation,
    epoch = coreAdminSession.epoch();
  const current = () => generation === version && coreAdminSession.epoch() === epoch;
  status.value = 'saving';
  actionError.value = null;
  try {
    const saved = await studentPortalApi.finishAttempt(
      props.organizationId,
      exercise.value.attempt.id,
      finishValues,
    );
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
onMounted(() => window.addEventListener('resize', scrollCaret));
onBeforeUnmount(() => {
  generation++;
  stopTimer();
  observer?.disconnect();
  window.removeEventListener('resize', scrollCaret);
});
</script>
<template>
  <div class="edu-typing">
    <div
      v-if="!exercise || status === 'finished'"
      class="edu-card"
    >
      <ActivityConfigFields
        v-model="config"
        :disabled="!!homeworkId || status === 'saving'"
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
    <p
      v-if="actionError"
      class="edu-error"
      role="alert"
    >
      {{ issue(actionError) }}
    </p>
    <button
      v-if="status === 'failed' && finishValues"
      type="button"
      class="edu-button edu-button--primary"
      @click="saveResult"
    >
      {{ t('education.retrySave') }}
    </button>
    <div
      v-if="exercise && status !== 'finished'"
      class="edu-card edu-typing__board"
      :class="{ 'edu-typing__board--focused': focused }"
    >
      <div
        class="edu-typing__stats"
        aria-live="off"
      >
        <strong>{{ remaining }} {{ t('education.seconds') }}</strong>
        <span>{{ wpm }} WPM</span>
        <span>{{ accuracy }}%</span>
        <span>{{ t('education.errors') }}: {{ errors }}</span>
      </div>
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
      <p
        :id="`${inputId}-hint`"
        class="edu-caption"
      >
        {{ focused ? t('education.typingEditHint') : t('education.typingFocusHint') }}
        {{ t('education.timerContinues') }}
      </p>
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
    <div
      v-if="status === 'finished' && result"
      class="edu-card"
      role="status"
    >
      <h2>{{ t('education.trainingResult') }}</h2>
      <p>
        <strong>{{ result.wpm }} WPM</strong>
        · {{ t('education.finalAccuracy') }}: {{ result.accuracy }}% · {{ t('education.errors') }}:
        {{ result.errors }}
      </p>
      <p>
        {{ t('education.typedCharacters') }}: {{ result.typedCharacters ?? result.keystrokes }} ·
        {{ result.durationSeconds }} {{ t('education.seconds') }}
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
