<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import { actT } from '../activityI18n';

const props = defineProps<{
  exercise: Record<string, unknown>;
  initialAnswers?: Record<string, unknown>;
  readonly?: boolean;
}>();

const emit = defineEmits<{
  'update:submission': [submission: Record<string, unknown>, telemetry: Record<string, unknown>];
  submit: [submission: Record<string, unknown>, telemetry: Record<string, unknown>];
}>();

const items = computed(() =>
  Array.isArray(props.exercise.items)
    ? (props.exercise.items as Array<{ id: string; prompt: string }>)
    : [],
);
const answers = ref<Record<string, string>>({
  ...((props.initialAnswers as Record<string, string>) ?? {}),
});
const startedAt = Date.now();

watch(
  answers,
  (val) =>
    emit(
      'update:submission',
      { answers: val },
      { elapsedSeconds: Math.max(1, (Date.now() - startedAt) / 1000) },
    ),
  { deep: true },
);

function finish(): void {
  const sec = Math.max(1, (Date.now() - startedAt) / 1000);
  emit('submit', { answers: answers.value, elapsedSeconds: sec }, { elapsedSeconds: sec });
}
</script>

<template>
  <div class="uact-math">
    <div
      v-for="(item, idx) in items"
      :key="item.id"
      class="uact-math__row"
    >
      <strong>{{ idx + 1 }}. {{ item.prompt }} =</strong>
      <input
        v-model="answers[item.id]"
        class="uact-math__input"
        type="number"
        :disabled="readonly"
      />
    </div>
    <AppButton
      v-if="!readonly"
      variant="primary"
      @click="finish"
    >
      {{ actT('submit') }}
    </AppButton>
  </div>
</template>

<style scoped>
.uact-math {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.uact-math__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 14px;
  border-radius: var(--radius-control-m);
  background: var(--card);
  border: 1px solid var(--border);
}
.uact-math__input {
  width: 140px;
  padding: 6px 10px;
  border-radius: var(--radius-control-s);
  border: 1px solid var(--border);
  background: var(--bg);
  color: var(--text-1);
}
</style>
