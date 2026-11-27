<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import { actT } from '../activityI18n';

type BreakdownRow = {
  itemId?: string;
  correct?: boolean;
  expected?: string;
  actual?: string;
  explanation?: string;
};

const props = defineProps<{
  exercise: Record<string, unknown>;
  initialAnswers?: Record<string, unknown>;
  readonly?: boolean;
  evaluation?: Record<string, unknown> | null;
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

watch(
  () => props.initialAnswers,
  (val) => {
    if (val && typeof val === 'object') {
      answers.value = { ...(val as Record<string, string>) };
    }
  },
);

const breakdownByRound = computed<Record<string, BreakdownRow>>(() => {
  const raw = props.evaluation?.breakdown;
  if (!Array.isArray(raw)) return {};
  const map: Record<string, BreakdownRow> = {};
  for (const row of raw) {
    if (row && typeof row === 'object' && typeof (row as BreakdownRow).itemId === 'string') {
      map[(row as BreakdownRow).itemId as string] = row as BreakdownRow;
    }
  }
  return map;
});

watch(answers, (val) => emit('update:submission', { answers: val }, {}), { deep: true });
</script>

<template>
  <div class="uact-logic">
    <article
      v-for="(r, idx) in rounds"
      :key="r.id"
      class="uact-logic__card"
      :class="{
        'is-correct': readonly && breakdownByRound[r.id]?.correct === true,
        'is-wrong': readonly && breakdownByRound[r.id]?.correct === false,
      }"
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
      <div
        v-if="readonly && breakdownByRound[r.id]"
        class="uact-logic__feedback"
      >
        <p>
          <strong>{{ breakdownByRound[r.id]?.correct ? '✓' : '✕' }}</strong>
          <span v-if="!breakdownByRound[r.id]?.correct && breakdownByRound[r.id]?.expected">
            ({{ breakdownByRound[r.id]?.expected }})
          </span>
        </p>
        <p
          v-if="breakdownByRound[r.id]?.explanation"
          class="uact-logic__explanation"
        >
          {{ breakdownByRound[r.id]?.explanation }}
        </p>
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
.uact-logic__card.is-correct {
  border-color: var(--success, #16a34a);
}
.uact-logic__card.is-wrong {
  border-color: var(--danger, #dc2626);
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
.uact-logic__feedback {
  font-size: 0.9rem;
  color: var(--muted);
}
.uact-logic__feedback p {
  margin: 2px 0;
}
</style>
