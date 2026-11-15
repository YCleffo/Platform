<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import { activityApi } from '../activityApi';
import {
  defaultActivityConfig,
  type ActivityConfig,
  type ActivityExercise,
  type LogicAnswer,
  type LogicResult,
} from '../activityTypes';
import { useEducationI18n } from '../i18n';
import { useEducationError } from '../resources';
import { invalidateEducationCache } from '../cache';
import ActivityConfigFields from '../ActivityConfigFields.vue';
const props = defineProps<{
  organizationId: string;
  attemptId?: string;
  homeworkId?: string;
  assignedConfig?: ActivityConfig;
}>();
const emit = defineEmits<{ started: [attemptId: string]; finished: [result: LogicResult] }>();
const { t } = useEducationI18n();
const issue = useEducationError();
const config = ref<ActivityConfig>(defaultActivityConfig('platform-logic'));
const exercise = ref<ActivityExercise | null>(null);
const answers = ref<LogicAnswer[]>([]);
const currentRound = ref(0);
const result = ref<LogicResult | null>(null);
const busy = ref(false);
const actionError = ref<unknown>(null);
const errorAction = ref<'start' | 'save' | 'finish' | 'resume'>('start');
const heading = ref<HTMLElement | null>(null);
let generation = 0;
const rounds = computed(() => exercise.value?.exercise.rounds ?? []);
const round = computed(() => rounds.value[currentRound.value]);
const selected = computed(
  () => answers.value.find((answer) => answer.roundId === round.value?.id)?.optionId,
);
const running = computed(() => exercise.value?.attempt.status === 'running' && !result.value);
watch(
  () => props.assignedConfig,
  (value) => {
    if (value) config.value = { ...defaultActivityConfig('platform-logic'), ...value };
  },
  { immediate: true },
);
function clear(): void {
  generation++;
  exercise.value = null;
  answers.value = [];
  currentRound.value = 0;
  result.value = null;
  busy.value = false;
  actionError.value = null;
}
watch(
  [() => props.organizationId, () => props.homeworkId, coreAdminSessionEpoch],
  () => {
    clear();
    if (props.attemptId && props.organizationId && coreAdminSession.accessToken())
      void resume(props.attemptId);
  },
  { flush: 'sync' },
);
watch(
  () => props.attemptId,
  (value) => {
    if (value && value !== exercise.value?.attempt.id) {
      clear();
      void resume(value);
    }
  },
  { immediate: true },
);
function accept(value: ActivityExercise): void {
  exercise.value = value;
  const valid = value.attempt.state?.answers ?? [];
  answers.value = valid.filter((a) =>
    value.exercise.rounds?.some(
      (r) => r.id === a.roundId && r.options.some((o) => o.id === a.optionId),
    ),
  );
  currentRound.value = Math.min(
    Math.max(0, value.attempt.state?.currentRound ?? 0),
    Math.max(0, (value.exercise.rounds?.length ?? 1) - 1),
  );
}
async function run(action: () => Promise<void>, kind: typeof errorAction.value): Promise<void> {
  if (busy.value) return;
  const version = generation,
    epoch = coreAdminSession.epoch();
  busy.value = true;
  actionError.value = null;
  errorAction.value = kind;
  try {
    await action();
  } catch (error) {
    if (version === generation && epoch === coreAdminSession.epoch()) actionError.value = error;
  } finally {
    if (version === generation && epoch === coreAdminSession.epoch()) busy.value = false;
  }
}
async function start(): Promise<void> {
  const version = generation,
    epoch = coreAdminSession.epoch();
  await run(async () => {
    const value = props.homeworkId
      ? await activityApi.startHomework(props.organizationId, props.homeworkId)
      : await activityApi.startLogic(props.organizationId, config.value);
    if (version !== generation || epoch !== coreAdminSession.epoch()) return;
    accept(value);
    result.value = null;
    emit('started', value.attempt.id);
    await nextTick();
    heading.value?.focus();
  }, 'start');
}
async function resume(attemptId: string): Promise<void> {
  const version = generation,
    epoch = coreAdminSession.epoch();
  await run(async () => {
    const value = await activityApi.attempt(props.organizationId, attemptId);
    if (version !== generation || epoch !== coreAdminSession.epoch()) return;
    if (
      (value.attempt.activityCode && value.attempt.activityCode !== 'platform-logic') ||
      !value.exercise.rounds?.length
    )
      throw new Error('Invalid activity');
    accept(value);
    if (value.attempt.status === 'finished') {
      if (!value.result) throw new Error('Finished result unavailable');
      result.value = value.result;
    }
  }, 'resume');
}
async function save(): Promise<void> {
  if (!exercise.value || !running.value) return;
  const version = generation,
    epoch = coreAdminSession.epoch();
  const attempt = exercise.value.attempt;
  await run(async () => {
    const value = await activityApi.saveState(
      props.organizationId,
      attempt.id,
      attempt.stateVersion ?? 0,
      { answers: [...answers.value], currentRound: currentRound.value },
    );
    if (version === generation && epoch === coreAdminSession.epoch()) exercise.value = value;
  }, 'save');
}
async function choose(optionId: string): Promise<void> {
  if (!round.value || busy.value || !running.value) return;
  answers.value = [
    ...answers.value.filter((a) => a.roundId !== round.value?.id),
    { roundId: round.value.id, optionId },
  ];
  await save();
}
async function move(index: number): Promise<void> {
  if (busy.value) return;
  currentRound.value = index;
  await save();
  await nextTick();
  heading.value?.focus();
}
async function finish(): Promise<void> {
  if (!exercise.value || answers.value.length !== rounds.value.length || busy.value) return;
  const version = generation,
    epoch = coreAdminSession.epoch(),
    attemptId = exercise.value.attempt.id;
  const snapshot = [...answers.value];
  await run(async () => {
    const value = await activityApi.finishLogic(props.organizationId, attemptId, snapshot);
    if (version !== generation || epoch !== coreAdminSession.epoch()) return;
    result.value = value;
    invalidateEducationCache(['homework', 'student', 'students', 'groups', 'messages', 'header']);
    emit('finished', value);
  }, 'finish');
}
function retry(): void {
  if (errorAction.value === 'save') void save();
  else if (errorAction.value === 'finish') void finish();
  else if (errorAction.value === 'resume' && props.attemptId) void resume(props.attemptId);
  else void start();
}
onBeforeUnmount(() => {
  generation++;
});
</script>
<template>
  <div class="edu-logic">
    <div
      v-if="!exercise || result"
      class="edu-card"
    >
      <ActivityConfigFields
        v-model="config"
        activity-code="platform-logic"
        :disabled="busy || !!homeworkId"
      />
      <p class="edu-subtle">{{ t('education.logicHint') }}</p>
      <button
        type="button"
        class="edu-button edu-button--primary"
        :disabled="busy"
        @click="start"
      >
        {{ result ? t('education.trainAgain') : t('education.startTraining') }}
      </button>
    </div>
    <p
      v-if="busy"
      role="status"
    >
      {{ t('education.loading') }}
    </p>
    <div
      v-if="actionError"
      class="edu-error"
      role="alert"
    >
      <p>{{ issue(actionError) }}</p>
      <button
        type="button"
        class="edu-button"
        :disabled="busy"
        @click="retry"
      >
        {{ t('education.retrySave') }}
      </button>
      <button
        v-if="exercise"
        type="button"
        class="edu-button"
        :disabled="busy"
        @click="resume(exercise.attempt.id)"
      >
        {{ t('education.restoreAttempt') }}
      </button>
    </div>
    <div
      v-if="running && round"
      class="edu-card"
    >
      <p class="edu-caption">
        {{ t('education.round') }} {{ currentRound + 1 }} / {{ rounds.length }} ·
        {{ t('education.answered') }}: {{ answers.length }}
      </p>
      <progress
        class="edu-activity-progress"
        :value="answers.length"
        :max="rounds.length"
        :aria-label="t('education.answered')"
      />
      <h2
        ref="heading"
        tabindex="-1"
      >
        {{ round.prompt }}
      </h2>
      <fieldset
        class="edu-logic__options"
        :disabled="busy"
      >
        <legend class="edu-sr-only">{{ t('education.chooseAnswer') }}</legend>
        <label
          v-for="option in round.options"
          :key="option.id"
          class="edu-logic__option"
          :class="{ 'edu-logic__option--selected': selected === option.id }"
        >
          <input
            type="radio"
            :name="round.id"
            :value="option.id"
            :checked="selected === option.id"
            @change="choose(option.id)"
          />
          <span>{{ option.label }}</span>
        </label>
      </fieldset>
      <div class="edu-form-actions">
        <button
          v-if="currentRound > 0"
          type="button"
          class="edu-button"
          :disabled="busy"
          @click="move(currentRound - 1)"
        >
          {{ t('education.back') }}
        </button>
        <button
          v-if="currentRound < rounds.length - 1"
          type="button"
          class="edu-button edu-button--primary"
          :disabled="!selected || busy || !!actionError"
          @click="move(currentRound + 1)"
        >
          {{ t('education.nextRound') }}
        </button>
        <button
          v-else
          type="button"
          class="edu-button edu-button--primary"
          :disabled="answers.length !== rounds.length || busy || !!actionError"
          @click="finish"
        >
          {{ t('education.finishTraining') }}
        </button>
      </div>
      <p class="edu-caption">{{ t('education.logicSavedHint') }}</p>
    </div>
    <p
      v-else-if="exercise && !result && !busy"
      class="edu-subtle"
    >
      {{ t('education.attemptUnavailable') }}
    </p>
    <div
      v-if="result"
      class="edu-card"
      role="status"
    >
      <h2>{{ t('education.trainingResult') }}</h2>
      <p>
        <strong>{{ result.correctAnswers }} / {{ result.totalRounds }}</strong>
        · {{ result.accuracy }}% · {{ t('education.gameScore') }}: {{ result.score }}
      </p>
      <ol class="edu-logic__review">
        <li
          v-for="entry in result.rounds"
          :key="entry.roundId"
        >
          <strong>{{ rounds.find((r) => r.id === entry.roundId)?.prompt }}</strong>
          <p>
            {{ entry.correct ? t('education.correctAnswer') : t('education.incorrectAnswer') }} ·
            {{
              rounds
                .find((r) => r.id === entry.roundId)
                ?.options.find((o) => o.id === entry.correctOptionId)?.label
            }}
          </p>
          <p class="edu-subtle">{{ entry.explanation }}</p>
        </li>
      </ol>
    </div>
  </div>
</template>
