<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import { actT } from '../activityI18n';

interface MemoryRound {
  id: string;
  gridSize: number;
  previewMs: number;
  pattern: number[];
}

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
  Array.isArray(props.exercise.rounds) ? (props.exercise.rounds as MemoryRound[]) : [],
);
const roundIdx = ref(0);
const phase = ref<'preview' | 'recall'>('preview');
const answers = ref<Record<string, number[]>>({
  ...((props.initialAnswers as Record<string, number[]>) ?? {}),
});
let previewTimer: ReturnType<typeof setTimeout> | null = null;

const currentRound = computed(() => rounds.value[roundIdx.value] ?? null);

function startPreview(): void {
  if (!currentRound.value || props.readonly) return;
  phase.value = 'preview';
  if (previewTimer) clearTimeout(previewTimer);
  previewTimer = setTimeout(() => {
    phase.value = 'recall';
  }, currentRound.value.previewMs || 1800);
}

function toggleCell(idx: number): void {
  if (!currentRound.value || phase.value !== 'recall' || props.readonly) return;
  const rid = currentRound.value.id;
  const list = [...(answers.value[rid] ?? [])];
  const pos = list.indexOf(idx);
  if (pos >= 0) list.splice(pos, 1);
  else list.push(idx);
  answers.value = { ...answers.value, [rid]: list };
}

function nextRoundOrFinish(): void {
  if (roundIdx.value + 1 < rounds.value.length) {
    roundIdx.value += 1;
    startPreview();
  } else {
    emit('submit', { answers: answers.value }, {});
  }
}

watch(answers, (val) => emit('update:submission', { answers: val }, {}), { deep: true });

onMounted(() => startPreview());
onBeforeUnmount(() => {
  if (previewTimer) clearTimeout(previewTimer);
});
</script>

<template>
  <div
    v-if="currentRound"
    class="uact-mem"
  >
    <header class="uact-mem__top">
      <strong>{{ roundIdx + 1 }} / {{ rounds.length }}</strong>
      <span>{{ phase === 'preview' ? actT('memorizeHint') : actT('recallHint') }}</span>
    </header>

    <div
      class="uact-mem__grid"
      :style="{ gridTemplateColumns: `repeat(${currentRound.gridSize}, 54px)` }"
    >
      <button
        v-for="idx in currentRound.gridSize * currentRound.gridSize"
        :key="idx - 1"
        type="button"
        class="uact-mem__cell"
        :class="{
          'uact-mem__cell--lit':
            (phase === 'preview' && currentRound.pattern.includes(idx - 1)) ||
            (phase === 'recall' && (answers[currentRound.id] || []).includes(idx - 1)),
        }"
        :disabled="phase === 'preview' || readonly"
        @click="toggleCell(idx - 1)"
      />
    </div>

    <AppButton
      v-if="!readonly && phase === 'recall'"
      variant="primary"
      @click="nextRoundOrFinish"
    >
      {{ roundIdx + 1 < rounds.length ? actT('checkRound') : actT('submit') }}
    </AppButton>
  </div>
</template>

<style scoped>
.uact-mem {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 12px;
}
.uact-mem__top {
  display: flex;
  gap: 14px;
  align-items: center;
}
.uact-mem__grid {
  display: grid;
  gap: 6px;
}
.uact-mem__cell {
  width: 54px;
  height: 54px;
  border-radius: var(--radius-control-s);
  border: 1px solid var(--border);
  background: var(--surface);
  cursor: pointer;
}
.uact-mem__cell--lit {
  background: var(--accent);
  border-color: var(--accent);
}
</style>
