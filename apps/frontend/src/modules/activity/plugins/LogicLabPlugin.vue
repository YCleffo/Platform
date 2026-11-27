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

const rounds = computed(() =>
  Array.isArray(props.exercise.rounds)
    ? (props.exercise.rounds as Array<{ id: string; prompt: string; options: string[] }>)
    : [],
);
const answers = ref<Record<string, string>>({
  ...((props.initialAnswers as Record<string, string>) ?? {}),
});

watch(answers, (val) => emit('update:submission', { answers: val }, {}), { deep: true });
</script>

<template>
  <div class="uact-logic">
    <article
      v-for="(r, idx) in rounds"
      :key="r.id"
      class="uact-logic__card"
    >
      <strong>{{ idx + 1 }}. {{ r.prompt }}</strong>
      <div class="uact-logic__opts">
        <label
          v-for="opt in r.options"
          :key="opt"
          class="uact-logic__opt"
        >
          <input
            v-model="answers[r.id]"
            type="radio"
            :name="`logic-${r.id}`"
            :value="opt"
            :disabled="readonly"
          />
          <span>{{ opt }}</span>
        </label>
      </div>
    </article>
    <AppButton
      v-if="!readonly"
      variant="primary"
      @click="emit('submit', { answers }, {})"
    >
      {{ actT('submit') }}
    </AppButton>
  </div>
</template>

<style scoped>
.uact-logic {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.uact-logic__card {
  padding: 12px;
  border-radius: var(--radius-control-m);
  border: 1px solid var(--border);
  background: var(--card);
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.uact-logic__opts {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.uact-logic__opt {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-control-s);
  background: var(--surface);
  cursor: pointer;
}
</style>
