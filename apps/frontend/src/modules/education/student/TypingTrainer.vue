<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { studentPortalApi, type TypingExercise, type TypingResult } from './api';
import { useEducationI18n } from '../i18n';
import { useEducationError } from '../resources';

const props = defineProps<{ organizationId: string }>();
const emit = defineEmits<{ (event: 'finished', result: TypingResult): void }>();
const { t } = useEducationI18n();
const issue = useEducationError();

const duration = ref<15 | 30 | 60>(30);
const language = ref<'ru' | 'en'>('ru');
const status = ref<'idle' | 'running' | 'finished' | 'saving' | 'failed'>('idle');
const exercise = ref<TypingExercise | null>(null);
const typed = ref('');
const startedAt = ref(0);
const elapsed = ref(0);
const timer = ref<number | null>(null);
const actionError = ref<Error | null>(null);
const result = ref<TypingResult | null>(null);
const busyStart = ref(false);

const text = computed(() => exercise.value?.exercise.text ?? '');
const correct = computed(() => {
  let count = 0;
  const max = Math.min(text.value.length, typed.value.length);
  for (let i = 0; i < max; i++) if (typed.value[i] === text.value[i]) count++;
  return count;
});
const wrong = computed(() => Math.min(text.value.length, typed.value.length) - correct.value);
const extra = computed(() => Math.max(0, typed.value.length - text.value.length));
const errors = computed(() => wrong.value + extra.value);
const minutes = computed(() => Math.max(elapsed.value, 1) / 60);
const wpm = computed(() => Math.round(correct.value / 5 / minutes.value));
const accuracy = computed(() => typed.value.length > 0 ? Math.round(correct.value / typed.value.length * 1000) / 10 : 100);
const remaining = computed(() => Math.max(0, (exercise.value?.exercise.durationSeconds ?? 0) - Math.floor(elapsed.value)));

function tick(): void {
  if (!startedAt.value) return;
  elapsed.value = (Date.now() - startedAt.value) / 1000;
  if (elapsed.value >= (exercise.value?.exercise.durationSeconds ?? 30)) {
    void finish();
  }
}
async function start(): Promise<void> {
  busyStart.value = true;
  status.value = 'saving';
  actionError.value = null;
  try {
    exercise.value = await studentPortalApi.startAttempt(props.organizationId, 'kiberone-typing', {
      durationSeconds: duration.value,
      language: language.value,
    });
    typed.value = '';
    result.value = null;
    startedAt.value = Date.now();
    elapsed.value = 0;
    timer.value = window.setInterval(tick, 100);
    status.value = 'running';
  } catch (error) {
    actionError.value = error instanceof Error ? error : new Error(String(error));
    status.value = 'failed';
  } finally {
    busyStart.value = false;
  }
}
async function finish(): Promise<void> {
  if (status.value !== 'running' || !exercise.value) return;
  stopTimer();
  status.value = 'saving';
  const values = { typed: typed.value, durationSeconds: exercise.value.exercise.durationSeconds };
  try {
    result.value = await studentPortalApi.finishAttempt(props.organizationId, exercise.value.attempt.id, values);
    status.value = 'finished';
    emit('finished', result.value);
  } catch (error) {
    actionError.value = error instanceof Error ? error : new Error(String(error));
    status.value = 'failed';
  }
}
function stopTimer(): void {
  if (timer.value !== null) {
    window.clearInterval(timer.value);
    timer.value = null;
  }
}
function charClass(index: number): string {
  if (index >= typed.value.length) return index === typed.value.length ? 'edu-typing__char--caret' : '';
  return typed.value[index] === text.value[index] ? 'edu-typing__char--ok' : 'edu-typing__char--wrong';
}
function onKeydown(event: KeyboardEvent): void {
  if (status.value !== 'running') return;
  if (event.metaKey || event.ctrlKey || event.altKey) return;
  if (event.key === 'Backspace') {
    event.preventDefault();
    typed.value = typed.value.slice(0, -1);
    return;
  }
  if (event.key.length === 1 && event.key !== ' ') {
    if (typed.value.length < text.value.length + 200) typed.value += event.key;
  } else if (event.key === ' ') {
    event.preventDefault();
    if (typed.value.length < text.value.length + 200) typed.value += ' ';
  }
}
onBeforeUnmount(stopTimer);
window.addEventListener('keydown', onKeydown);
</script>
<template>
  <div class="edu-typing">
    <div
      v-if="status === 'idle' || status === 'failed'"
      class="edu-card"
    >
      <div class="edu-form-actions">
        <label>
          {{ t('education.duration') }}
          <select
            v-model="duration"
            class="edu-input"
          >
            <option :value="15">15 {{ t('education.seconds') }}</option>
            <option :value="30">30 {{ t('education.seconds') }}</option>
            <option :value="60">60 {{ t('education.seconds') }}</option>
          </select>
        </label>
        <label>
          {{ t('education.language') }}
          <select
            v-model="language"
            class="edu-input"
          >
            <option value="ru">RU</option>
            <option value="en">EN</option>
          </select>
        </label>
        <button
          type="button"
          class="edu-button edu-button--primary"
          :disabled="busyStart"
          @click="start"
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
    </div>
    <div
      v-else-if="exercise"
      class="edu-card edu-typing__board"
    >
      <p class="edu-typing__stats">
        <strong>{{ wpm }} WPM</strong> · {{ accuracy }}% · {{ t('education.errors') }}: {{ errors }} ·
        {{ remaining }}{{ t('education.seconds') }}
      </p>
      <p class="edu-typing__text">
        <template v-if="status !== 'finished'">
          <span
            v-for="(char, index) in text"
            :key="index"
            :class="charClass(index)"
          >{{ char === ' ' ? '\u00A0' : char }}</span>
        </template>
        <template v-else>{{ text }}</template>
      </p>
      <button
        v-if="status === 'running'"
        type="button"
        class="edu-button"
        @click="finish"
      >
        {{ t('education.finishTraining') }}
      </button>
    </div>
    <div
      v-if="status === 'finished' && result"
      class="edu-card"
    >
      <h2>{{ t('education.trainingResult') }}</h2>
      <p><strong>{{ result.wpm }} {{ t('education.wpm') }}</strong> · {{ result.accuracy }}% ·
        {{ t('education.errors') }}: {{ result.errors }}</p>
      <button
        type="button"
        class="edu-button"
        @click="status = 'idle'; exercise = null; result = null"
      >
        {{ t('education.trainAgain') }}
      </button>
    </div>
  </div>
</template>
