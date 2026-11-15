<script setup lang="ts">
import { computed } from 'vue';
import { useEducationI18n } from './i18n';
import type { ActivityConfig } from './activityTypes';
const props = defineProps<{
  modelValue: ActivityConfig;
  activityCode?: string;
  disabled?: boolean;
  assignment?: boolean;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: ActivityConfig] }>();
const { t } = useEducationI18n();
const logic = computed(() => props.activityCode === 'platform-logic');
function update(key: keyof ActivityConfig, event: Event, numeric = false): void {
  const value = (event.target as HTMLInputElement).value;
  emit('update:modelValue', { ...props.modelValue, [key]: numeric ? Number(value) : value });
}
</script>
<template>
  <fieldset
    class="edu-config-fields"
    :disabled="disabled"
  >
    <legend class="edu-sr-only">{{ t('education.exerciseSettings') }}</legend>
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
  </fieldset>
</template>
