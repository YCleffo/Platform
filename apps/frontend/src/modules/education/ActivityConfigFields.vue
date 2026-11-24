<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useEducationI18n } from './i18n';
import type { ActivityConfig, AssessmentItem } from './activityTypes';
import { assessmentApi } from './assessment/assessmentApi';

const props = defineProps<{
  modelValue: ActivityConfig;
  activityCode?: string;
  disabled?: boolean;
  assignment?: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: ActivityConfig] }>();
const { t } = useEducationI18n();

const logic = computed(() => props.activityCode === 'platform-logic');
const assessment = computed(() => props.activityCode === 'platform-assessment');
const publishedAssessments = ref<AssessmentItem[]>([]);

watch(
  [() => props.activityCode, () => props.assignment],
  async ([code, isAssign]) => {
    if (code === 'platform-assessment' && isAssign && publishedAssessments.value.length === 0) {
      try {
        const res = await assessmentApi.listAssessments({ status: 'PUBLISHED' });
        publishedAssessments.value = res.items;
        const first = res.items[0];
        if (!props.modelValue.assessmentId && first) {
          emit('update:modelValue', { ...props.modelValue, assessmentId: first.id });
        }
      } catch {
        // ignore
      }
    }
  },
  { immediate: true },
);

function update(key: keyof ActivityConfig, event: Event, numeric = false): void {
  const target = event.target as HTMLInputElement;
  const value =
    target.type === 'checkbox' ? target.checked : numeric ? Number(target.value) : target.value;
  emit('update:modelValue', { ...props.modelValue, [key]: value });
}
</script>

<template>
  <fieldset
    class="edu-config-fields"
    :disabled="disabled"
  >
    <legend class="edu-sr-only">{{ t('education.exerciseSettings') }}</legend>

    <!-- Assessment Specific Settings -->
    <template v-if="assessment">
      <label v-if="assignment">
        {{ t('education.selectAssessment') }}
        <select
          class="edu-input"
          :value="modelValue.assessmentId || ''"
          @change="update('assessmentId', $event)"
        >
          <option
            value=""
            disabled
          >
            {{ t('education.selectAssessmentPlaceholder') }}
          </option>
          <option
            v-for="test in publishedAssessments"
            :key="test.id"
            :value="test.id"
          >
            {{ test.title }} ({{ test.questionsCount }} {{ t('education.questionsCount') }})
          </option>
        </select>
      </label>

      <label>
        {{ t('education.assessmentPassingScore') }}
        <input
          class="edu-input"
          type="number"
          min="0"
          max="100"
          step="5"
          :value="modelValue.passingScorePercent ?? 60"
          @input="update('passingScorePercent', $event, true)"
        />
      </label>

      <label>
        {{ t('education.assessmentTimeLimit') }}
        <select
          class="edu-input"
          :value="modelValue.timeLimitSeconds ?? 1800"
          @change="update('timeLimitSeconds', $event, true)"
        >
          <option :value="300">5 {{ t('education.minutes') }}</option>
          <option :value="600">10 {{ t('education.minutes') }}</option>
          <option :value="900">15 {{ t('education.minutes') }}</option>
          <option :value="1800">30 {{ t('education.minutes') }}</option>
          <option :value="2700">45 {{ t('education.minutes') }}</option>
          <option :value="3600">60 {{ t('education.minutes') }}</option>
        </select>
      </label>

      <label class="edu-config-checkbox">
        <input
          type="checkbox"
          :checked="Boolean(modelValue.shuffleQuestions)"
          @change="update('shuffleQuestions', $event)"
        />
        <span>{{ t('education.assessmentShuffleQuestions') }}</span>
      </label>

      <label class="edu-config-checkbox">
        <input
          type="checkbox"
          :checked="Boolean(modelValue.shuffleOptions)"
          @change="update('shuffleOptions', $event)"
        />
        <span>{{ t('education.assessmentShuffleOptions') }}</span>
      </label>

      <label>
        {{ t('education.assessmentRevealPolicy') }}
        <select
          class="edu-input"
          :value="modelValue.revealAnswersPolicy ?? 'after_grading'"
          @change="update('revealAnswersPolicy', $event)"
        >
          <option value="after_grading">{{ t('education.revealAfterGrading') }}</option>
          <option value="immediate">{{ t('education.revealImmediate') }}</option>
          <option value="never">{{ t('education.revealNever') }}</option>
        </select>
      </label>

      <label v-if="assignment">
        {{ t('education.homeworkMaxAttempts') }}
        <input
          class="edu-input"
          type="number"
          min="1"
          max="20"
          :value="modelValue.maxAttempts ?? 1"
          @input="update('maxAttempts', $event, true)"
        />
      </label>
    </template>

    <!-- Standard Trainers Settings (Typing / Logic / Math) -->
    <template v-else>
      <label>
        {{ t('education.language') }}
        <select
          class="edu-input"
          :value="modelValue.language"
          @change="update('language', $event)"
        >
          <option value="ru">RU</option>
          <option value="en">EN</option>
        </select>
      </label>

      <label>
        {{ t('education.difficulty') }}
        <select
          class="edu-input"
          :value="modelValue.difficulty ?? 1"
          @change="update('difficulty', $event, true)"
        >
          <option :value="1">1 · {{ t('education.difficulty1') }}</option>
          <option :value="2">2 · {{ t('education.difficulty2') }}</option>
          <option :value="3">3 · {{ t('education.difficulty3') }}</option>
        </select>
      </label>

      <template v-if="!logic">
        <label>
          {{ t('education.typingMode') }}
          <select
            class="edu-input"
            :value="modelValue.mode ?? 'words'"
            @change="update('mode', $event)"
          >
            <option
              v-for="mode in ['words', 'sentences', 'numbers', 'code', 'accuracy']"
              :key="mode"
              :value="mode"
            >
              {{ t(`education.typingMode_${mode}`) }}
            </option>
          </select>
        </label>

        <label>
          {{ t('education.duration') }}
          <select
            class="edu-input"
            :value="modelValue.durationSeconds ?? 30"
            @change="update('durationSeconds', $event, true)"
          >
            <option
              v-for="seconds in [15, 30, 60]"
              :key="seconds"
              :value="seconds"
            >
              {{ seconds }} {{ t('education.seconds') }}
            </option>
          </select>
        </label>

        <template v-if="assignment">
          <label>
            {{ t('education.minAccuracy') }}
            <input
              class="edu-input"
              type="number"
              min="0"
              max="100"
              step="1"
              :value="modelValue.minAccuracy ?? 0"
              @input="update('minAccuracy', $event, true)"
            />
          </label>
          <label>
            {{ t('education.targetWpm') }}
            <input
              class="edu-input"
              type="number"
              min="0"
              max="500"
              :value="modelValue.targetWpm ?? 0"
              @input="update('targetWpm', $event, true)"
            />
          </label>
        </template>
      </template>

      <label v-else>
        {{ t('education.rounds') }}
        <select
          class="edu-input"
          :value="modelValue.roundCount ?? 5"
          @change="update('roundCount', $event, true)"
        >
          <option :value="5">5</option>
          <option :value="10">10</option>
        </select>
      </label>

      <label v-if="assignment">
        {{ t('education.homeworkMaxAttempts') }}
        <input
          class="edu-input"
          type="number"
          min="1"
          max="20"
          :value="modelValue.maxAttempts ?? 5"
          @input="update('maxAttempts', $event, true)"
        />
      </label>
    </template>
  </fieldset>
</template>
