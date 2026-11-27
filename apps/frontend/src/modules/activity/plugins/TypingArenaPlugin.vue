<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import type { ActivityAttempt } from '../activityApi';
import { actT } from '../activityI18n';
import {
  buildPromptWords,
  calculateTypingStats,
  defaultTypingArenaConfig,
  diffInputUpdate,
  normalizeTypingAttemptResult,
  splitGraphemes,
  type TypingArenaConfig,
  type TypingLanguage,
  type TypingSubmode,
  type WpmSample,
} from '../typing/typingEngine';

const props = defineProps<{
  exercise: Record<string, unknown>;
  initialAnswers?: Record<string, unknown>;
  readonly?: boolean;
  config?: Record<string, unknown>;
  attempt?: ActivityAttempt | null;
  assigned?: boolean;
  history?: Array<Record<string, unknown>>;
}>();

const emit = defineEmits<{
  'update:submission': [submission: Record<string, unknown>, telemetry: Record<string, unknown>];
  submit: [submission: Record<string, unknown>, telemetry: Record<string, unknown>];
  restart: [config: TypingArenaConfig];
}>();

const inputId = useId();
const inputRef = ref<HTMLTextAreaElement | null>(null);
const viewportRef = ref<HTMLElement | null>(null);

const localConfig = ref<TypingArenaConfig>(
  defaultTypingArenaConfig({ ...props.config, ...props.exercise }),
);
const showCustomEditor = ref(false);
const customTextDraft = ref(localConfig.value.customText);
const zenFocusMode = ref(false);

const promptText = computed(() => String(props.exercise.promptText ?? ''));
const effectiveSubmode = computed<TypingSubmode>(() => {
  const s = String(props.exercise.submode ?? localConfig.value.submode ?? 'time');
  if (s === 'zen' || props.exercise.mode === 'zen') return 'zen';
  if (s === 'words' || s === 'quote' || s === 'custom') return s;
  return 'time';
});
const isZen = computed(() => effectiveSubmode.value === 'zen' || (promptText.value === '' && !props.readonly));
const timeLimitSeconds = computed(() => Number(props.exercise.timeLimitSeconds ?? 0));
const language = computed<TypingLanguage>(() =>
  (props.exercise.language ?? localConfig.value.language) === 'ru' ? 'ru' : 'en',
);

const typedText = ref(String(props.initialAnswers?.typedText ?? ''));
const startedAtMs = ref<number | null>(null);
const elapsedMs = ref(0);
const corrections = ref(0);
const inputErrors = ref(0);
const isComposing = ref(false);
const composingText = ref('');
const focused = ref(false);
const hasStartedTyping = ref(typedText.value.length > 0);
const submittedLocally = ref(false);
const wpmSamples = ref<WpmSample[]>([]);
let lastSampleSecond = 0;
let timer: number | null = null;
let resizeObserver: ResizeObserver | null = null;

// Caret visual position
const caretPos = ref({ x: 0, y: 0, height: 26 });
const hoverSampleIdx = ref<number | null>(null);
const hoveredSample = computed(() => {
  if (hoverSampleIdx.value === null || !chartPoints.value) return null;
  return chartPoints.value.pts[hoverSampleIdx.value] ?? null;
});

const expectedGraphemes = computed(() => splitGraphemes(promptText.value, language.value));
const promptWords = computed(() => buildPromptWords(expectedGraphemes.value));
const actualGraphemes = ref<string[]>(splitGraphemes(typedText.value, language.value));

watch(
  () => [props.exercise, props.attempt?.id],
  () => {
    stopTimer();
    localConfig.value = defaultTypingArenaConfig({ ...props.config, ...props.exercise });
    customTextDraft.value = localConfig.value.customText;
    typedText.value = String(props.initialAnswers?.typedText ?? '');
    actualGraphemes.value = splitGraphemes(typedText.value, language.value);
    startedAtMs.value = null;
    elapsedMs.value = 0;
    corrections.value = 0;
    inputErrors.value = 0;
    isComposing.value = false;
    composingText.value = '';
    hasStartedTyping.value = typedText.value.length > 0;
    submittedLocally.value = false;
    wpmSamples.value = [];
    lastSampleSecond = 0;
    if (viewportRef.value) viewportRef.value.scrollTop = 0;
    void nextTick(() => {
      updateCaretPosition();
      if (!props.readonly) {
        inputRef.value?.focus();
      }
    });
  },
  { deep: false },
);

const stats = computed(() =>
  calculateTypingStats({
    actualGraphemes: actualGraphemes.value,
    expectedGraphemes: expectedGraphemes.value,
    elapsedMs: elapsedMs.value,
    wpmSamples: wpmSamples.value,
    isZen: isZen.value,
  }),
);

const remainingSeconds = computed(() => {
  if (effectiveSubmode.value !== 'time' || timeLimitSeconds.value <= 0) return null;
  return Math.max(0, Math.ceil((timeLimitSeconds.value * 1000 - elapsedMs.value) / 1000));
});

const isEvaluated = computed(
  () =>
    props.attempt !== null &&
    props.attempt !== undefined &&
    ['EVALUATED', 'COMPLETED'].includes(props.attempt.state),
);

const canonicalResult = computed(() => {
  if (props.attempt && isEvaluated.value) {
    return normalizeTypingAttemptResult(props.attempt);
  }
  return null;
});

const chartPoints = computed(() => {
  const samples = canonicalResult.value?.wpmSamples?.length
    ? canonicalResult.value.wpmSamples
    : wpmSamples.value;
  if (samples.length < 2) return null;
  const maxWpm = Math.max(20, ...samples.map((s) => Math.max(s.wpm, s.rawWpm)));
  const width = 560;
  const height = 130;
  const padX = 20;
  const padY = 15;
  const pts = samples.map((s, idx) => {
    const x = padX + (idx / Math.max(1, samples.length - 1)) * width;
    const yWpm = padY + height - (s.wpm / maxWpm) * height;
    const yRaw = padY + height - (s.rawWpm / maxWpm) * height;
    return { ...s, x, yWpm, yRaw };
  });
  return {
    pts,
    maxWpm,
    wpmPolyline: pts.map((p) => `${p.x.toFixed(1)},${p.yWpm.toFixed(1)}`).join(' '),
    rawPolyline: pts.map((p) => `${p.x.toFixed(1)},${p.yRaw.toFixed(1)}`).join(' '),
  };
});

function stopTimer(): void {
  if (timer !== null) {
    window.clearInterval(timer);
    timer = null;
  }
}

function tick(): void {
  if (startedAtMs.value === null || props.readonly || submittedLocally.value) return;
  const nowElapsed = Date.now() - startedAtMs.value;
  elapsedMs.value = nowElapsed;

  const curSec = Math.floor(nowElapsed / 1000);
  if (curSec > lastSampleSecond) {
    lastSampleSecond = curSec;
    wpmSamples.value = [
      ...wpmSamples.value,
      {
        second: curSec,
        wpm: stats.value.wpm,
        rawWpm: stats.value.rawWpm,
        errors: stats.value.errors,
      },
    ].slice(-300);
  }

  if (effectiveSubmode.value === 'time' && timeLimitSeconds.value > 0) {
    if (nowElapsed >= timeLimitSeconds.value * 1000) {
      finishTyping();
    }
  }
}

function startClock(): void {
  if (startedAtMs.value !== null || props.readonly) return;
  startedAtMs.value = Date.now();
  hasStartedTyping.value = true;
  stopTimer();
  timer = window.setInterval(tick, 100);
}

function buildTelemetry(sec: number): Record<string, unknown> {
  return {
    backspaceCount: corrections.value,
    corrections: corrections.value,
    inputErrors: inputErrors.value,
    consistency: stats.value.consistency,
    wpmSamples: wpmSamples.value,
    elapsedSeconds: sec,
    telemetryVerified: false,
  };
}

function applyTypedValue(nextValue: string): void {
  if (props.readonly || submittedLocally.value) return;
  const nextGraphemes = splitGraphemes(nextValue, language.value);
  const { correctionsDelta, inputErrorsDelta } = diffInputUpdate(
    actualGraphemes.value,
    nextGraphemes,
    expectedGraphemes.value,
    isZen.value,
  );
  corrections.value += correctionsDelta;
  inputErrors.value += inputErrorsDelta;
  typedText.value = nextValue;
  actualGraphemes.value = nextGraphemes;

  if (nextGraphemes.length > 0) {
    startClock();
  }

  const sec = startedAtMs.value
    ? Math.max(1, (Date.now() - startedAtMs.value) / 1000)
    : Math.max(1, elapsedMs.value / 1000);

  emit('update:submission', { typedText: typedText.value, elapsedSeconds: sec }, buildTelemetry(sec));

  void nextTick(updateCaretPosition);

  // Auto-finish for fixed-length modes (words, quote, custom, sentences, accuracy)
  if (
    !isZen.value &&
    effectiveSubmode.value !== 'time' &&
    expectedGraphemes.value.length > 0 &&
    nextGraphemes.length >= expectedGraphemes.value.length
  ) {
    finishTyping();
  }
}

function onInput(event: Event): void {
  const target = event.target as HTMLTextAreaElement;
  if (isComposing.value || (event as InputEvent).isComposing) {
    composingText.value = target.value.slice(typedText.value.length);
    return;
  }
  composingText.value = '';
  applyTypedValue(target.value);
}

function onCompositionStart(): void {
  isComposing.value = true;
}

function onCompositionUpdate(event: CompositionEvent): void {
  composingText.value = event.data ?? '';
}

function onCompositionEnd(event: CompositionEvent): void {
  isComposing.value = false;
  composingText.value = '';
  const target = event.target as HTMLTextAreaElement;
  applyTypedValue(target.value);
}

function onKeyDown(event: KeyboardEvent): void {
  if (event.key === 'Tab') {
    event.preventDefault();
    if (!props.assigned) {
      triggerRestart();
    } else if (!props.readonly) {
      focusInput();
    }
  }
}

function finishTyping(): void {
  if (props.readonly || submittedLocally.value) return;
  submittedLocally.value = true;
  stopTimer();
  const sec = startedAtMs.value
    ? Math.max(1, Math.round(((Date.now() - startedAtMs.value) / 1000) * 100) / 100)
    : Math.max(1, Math.round((elapsedMs.value / 1000) * 100) / 100);

  // Record final sample
  wpmSamples.value = [
    ...wpmSamples.value,
    {
      second: Math.max(1, Math.round(sec)),
      wpm: stats.value.wpm,
      rawWpm: stats.value.rawWpm,
      errors: stats.value.errors,
    },
  ];

  emit('submit', { typedText: typedText.value, elapsedSeconds: sec }, buildTelemetry(sec));
}

function updateCaretPosition(): void {
  const viewport = viewportRef.value;
  if (!viewport) return;
  const cursorIdx = actualGraphemes.value.length;
  const targetEl =
    viewport.querySelector<HTMLElement>(`[data-idx="${cursorIdx}"]`) ??
    viewport.querySelector<HTMLElement>('[data-idx="end"]');
  if (!targetEl) return;

  const x = targetEl.offsetLeft;
  const y = targetEl.offsetTop;
  const height = targetEl.offsetHeight || 26;
  caretPos.value = { x, y, height };

  // Smooth scroll viewport so active line stays comfortably visible
  const lineH = height + 8;
  if (y < viewport.scrollTop + 4) {
    viewport.scrollTo({ top: Math.max(0, y - 8), behavior: 'smooth' });
  } else if (y + lineH > viewport.scrollTop + viewport.clientHeight - 4) {
    viewport.scrollTo({ top: Math.max(0, y - lineH), behavior: 'smooth' });
  }
}

function focusInput(): void {
  if (!props.readonly) {
    inputRef.value?.focus();
  }
}

function updateConfigAndRestart(patch: Partial<TypingArenaConfig>): void {
  if (props.assigned) return;
  const next: TypingArenaConfig = { ...localConfig.value, ...patch };
  if (patch.submode === 'zen') {
    next.mode = 'zen';
  } else if (patch.submode === 'quote') {
    next.mode = 'quote';
  } else if (patch.submode === 'custom') {
    next.mode = 'custom';
    showCustomEditor.value = true;
    localConfig.value = next;
    return;
  } else if (patch.submode === 'time' || patch.submode === 'words') {
    if (next.mode === 'zen' || next.mode === 'quote' || next.mode === 'custom') {
      next.mode = 'words';
    }
  }
  showCustomEditor.value = false;
  localConfig.value = next;
  emit('restart', next);
}

function applyCustomText(): void {
  const cleaned = customTextDraft.value.trim();
  if (!cleaned) return;
  showCustomEditor.value = false;
  localConfig.value = {
    ...localConfig.value,
    submode: 'custom',
    mode: 'custom',
    customText: cleaned,
  };
  emit('restart', localConfig.value);
}

function triggerRestart(): void {
  emit('restart', { ...localConfig.value });
}

onMounted(() => {
  if (typeof ResizeObserver !== 'undefined' && viewportRef.value) {
    resizeObserver = new ResizeObserver(() => updateCaretPosition());
    resizeObserver.observe(viewportRef.value);
  }
  void nextTick(() => {
    updateCaretPosition();
    if (!props.readonly) {
      inputRef.value?.focus();
    }
  });
});

onBeforeUnmount(() => {
  stopTimer();
  resizeObserver?.disconnect();
});
</script>

<template>
  <div
    class="uact-arena"
    :class="{ 'uact-arena--zen-focus': zenFocusMode && hasStartedTyping && !readonly }"
  >
    <!-- Mode & Configuration Bar -->
    <div
      v-if="!assigned && !isEvaluated"
      class="uact-arena__toolbar"
    >
      <div class="uact-arena__group">
        <button
          v-for="sub in (['time', 'words', 'quote', 'zen', 'custom'] as const)"
          :key="sub"
          type="button"
          class="uact-arena__pill"
          :class="{ 'uact-arena__pill--active': localConfig.submode === sub }"
          @click="updateConfigAndRestart({ submode: sub })"
        >
          {{
            actT(
              sub === 'time'
                ? 'typingSubmodeTime'
                : sub === 'words'
                  ? 'typingSubmodeWords'
                  : sub === 'quote'
                    ? 'typingSubmodeQuote'
                    : sub === 'zen'
                      ? 'typingSubmodeZen'
                      : 'typingSubmodeCustom',
            )
          }}
        </button>
      </div>

      <div
        v-if="localConfig.submode === 'time'"
        class="uact-arena__group"
      >
        <button
          v-for="sec in [15, 30, 60, 120]"
          :key="sec"
          type="button"
          class="uact-arena__pill"
          :class="{ 'uact-arena__pill--active': localConfig.timeLimitSeconds === sec }"
          @click="updateConfigAndRestart({ timeLimitSeconds: sec })"
        >
          {{ sec }}s
        </button>
      </div>

      <div
        v-else-if="localConfig.submode === 'words'"
        class="uact-arena__group"
      >
        <button
          v-for="wc in [10, 25, 50, 100]"
          :key="wc"
          type="button"
          class="uact-arena__pill"
          :class="{ 'uact-arena__pill--active': localConfig.wordCount === wc }"
          @click="updateConfigAndRestart({ wordCount: wc })"
        >
          {{ wc }}
        </button>
      </div>

      <div
        v-if="localConfig.submode === 'time' || localConfig.submode === 'words'"
        class="uact-arena__group"
      >
        <button
          type="button"
          class="uact-arena__pill"
          :class="{ 'uact-arena__pill--active': localConfig.punctuation }"
          @click="updateConfigAndRestart({ punctuation: !localConfig.punctuation })"
        >
          {{ actT('typingPunctuation') }}
        </button>
        <button
          type="button"
          class="uact-arena__pill"
          :class="{ 'uact-arena__pill--active': localConfig.numbers }"
          @click="updateConfigAndRestart({ numbers: !localConfig.numbers })"
        >
          {{ actT('typingNumbers') }}
        </button>
        <button
          v-for="diff in ([1, 2, 3] as const)"
          :key="diff"
          type="button"
          class="uact-arena__pill"
          :class="{ 'uact-arena__pill--active': localConfig.difficulty === diff }"
          @click="updateConfigAndRestart({ difficulty: diff })"
        >
          L{{ diff }}
        </button>
      </div>

      <div class="uact-arena__group">
        <button
          v-for="lang in (['en', 'ru'] as const)"
          :key="lang"
          type="button"
          class="uact-arena__pill"
          :class="{ 'uact-arena__pill--active': localConfig.language === lang }"
          @click="updateConfigAndRestart({ language: lang })"
        >
          {{ lang.toUpperCase() }}
        </button>
        <button
          type="button"
          class="uact-arena__pill"
          :class="{ 'uact-arena__pill--active': zenFocusMode }"
          @click="zenFocusMode = !zenFocusMode"
        >
          {{ actT('typingFocusMode') }}
        </button>
      </div>
    </div>

    <div
      v-else-if="assigned && !isEvaluated"
      class="uact-arena__assigned-banner"
    >
      <AppBadge variant="primary">{{ actT('typingAssignedLocked') }}</AppBadge>
      <span>
        {{ language.toUpperCase() }} ·
        {{ timeLimitSeconds > 0 ? `${timeLimitSeconds}s` : `${localConfig.wordCount}w` }}
      </span>
    </div>

    <!-- Custom Text Editor -->
    <div
      v-if="showCustomEditor && !assigned && !isEvaluated"
      class="uact-arena__custom-box"
    >
      <textarea
        v-model="customTextDraft"
        class="uact-arena__custom-input"
        rows="3"
        :placeholder="actT('typingCustomTextPlaceholder')"
      />
      <AppButton
        size="s"
        variant="primary"
        @click="applyCustomText"
      >
        {{ actT('typingApplyCustomText') }}
      </AppButton>
    </div>

    <!-- Active Typing Stage -->
    <template v-if="!isEvaluated">
      <div
        class="uact-arena__metrics"
        aria-live="off"
      >
        <span class="uact-arena__timer">
          <template v-if="remainingSeconds !== null">{{ remainingSeconds }}s</template>
          <template v-else-if="!isZen">
            {{ actualGraphemes.length }} / {{ expectedGraphemes.length }}
          </template>
          <template v-else>{{ Math.floor(elapsedMs / 1000) }}s</template>
        </span>
        <span class="uact-arena__stat">
          <strong>{{ stats.wpm }}</strong> {{ actT('typingWpm') }}
        </span>
        <span class="uact-arena__stat">
          <strong>{{ stats.rawWpm }}</strong> {{ actT('typingRaw') }}
        </span>
        <span class="uact-arena__stat">
          <strong>{{ stats.cpm }}</strong> {{ actT('typingCpm') }}
        </span>
        <span class="uact-arena__stat">
          <strong>{{ stats.accuracy }}%</strong> {{ actT('typingAccuracy') }}
        </span>
        <span class="uact-arena__stat">
          <strong>{{ stats.errors }}</strong> {{ actT('typingErrors') }}
        </span>
        <AppBadge
          v-if="isComposing"
          variant="warning"
        >
          {{ actT('typingComposing') }}: {{ composingText }}
        </AppBadge>
      </div>

      <!-- Monkeytype-style Interactive Text Viewport -->
      <div
        class="uact-arena__stage"
        :class="{ 'uact-arena__stage--focused': focused }"
        @click="focusInput"
      >
        <div
          v-if="!isZen"
          ref="viewportRef"
          class="uact-arena__viewport"
          aria-hidden="true"
        >
          <span
            v-if="!readonly"
            class="uact-arena__caret"
            :class="{ 'uact-arena__caret--idle': !hasStartedTyping }"
            :style="{
              transform: `translate3d(${caretPos.x}px, ${caretPos.y}px, 0)`,
              height: `${caretPos.height}px`,
            }"
          />
          <span
            v-for="word in promptWords"
            :key="word.startIndex"
            class="uact-arena__word"
          >
            <span
              v-for="item in word.chars"
              :key="item.index"
              :data-idx="item.index"
              class="uact-char"
              :class="{
                'uact-char--ok':
                  item.index < actualGraphemes.length &&
                  actualGraphemes[item.index] === item.char,
                'uact-char--err':
                  item.index < actualGraphemes.length &&
                  actualGraphemes[item.index] !== item.char,
              }"
            >{{ item.char === ' ' ? '\u00A0' : item.char }}</span>
          </span>
          <span
            data-idx="end"
            class="uact-char uact-char--end"
          >&nbsp;</span>
        </div>

        <div
          v-else
          class="uact-arena__zen-view"
        >
          <p class="uact-arena__zen-hint">{{ actT('typingZenHint') }}</p>
          <div class="uact-arena__zen-text">
            {{ typedText || actT('typingPlaceholder') }}
          </div>
        </div>

        <!-- Focus Loss Overlay -->
        <div
          v-if="!focused && !readonly"
          class="uact-arena__blur-overlay"
        >
          <span>{{ actT('typingClickToFocus') }}</span>
        </div>

        <!-- Accessible Native Input (supports IME, mobile touch, paste, selection) -->
        <textarea
          :id="inputId"
          ref="inputRef"
          class="uact-arena__input"
          :value="typedText"
          :disabled="readonly"
          :aria-label="actT('typingPlaceholder')"
          rows="3"
          spellcheck="false"
          autocapitalize="off"
          autocomplete="off"
          autocorrect="off"
          :lang="language"
          @input="onInput"
          @keydown="onKeyDown"
          @compositionstart="onCompositionStart"
          @compositionupdate="onCompositionUpdate"
          @compositionend="onCompositionEnd"
          @focus="focused = true"
          @blur="focused = false"
        />
      </div>

      <div class="uact-arena__footer">
        <div class="uact-arena__actions">
          <AppButton
            v-if="!readonly"
            variant="primary"
            size="s"
            @click="finishTyping"
          >
            {{ actT('submit') }}
          </AppButton>
          <AppButton
            v-if="!assigned && !readonly"
            variant="secondary"
            size="s"
            @click="triggerRestart"
          >
            {{ actT('typingRestart') }}
          </AppButton>
        </div>
        <span
          v-if="!assigned"
          class="uact-arena__hint"
        >
          {{ actT('typingRestartHint') }}
        </span>
      </div>
    </template>

    <!-- Canonical Results Screen -->
    <div
      v-else-if="canonicalResult"
      class="uact-arena__results"
      role="status"
    >
      <div class="uact-arena__results-head">
        <h2>{{ actT('typingResultTitle') }}</h2>
        <AppBadge :variant="canonicalResult.passed ? 'success' : 'warning'">
          {{ canonicalResult.passed ? actT('typingPassed') : actT('typingNotPassed') }}
        </AppBadge>
      </div>

      <div class="uact-arena__result-grid">
        <div class="uact-arena__metric-card uact-arena__metric-card--hero">
          <span class="uact-arena__metric-val">{{ canonicalResult.wpm }}</span>
          <span class="uact-arena__metric-lbl">{{ actT('typingWpm') }}</span>
        </div>
        <div class="uact-arena__metric-card">
          <span class="uact-arena__metric-val">{{ canonicalResult.accuracy }}%</span>
          <span class="uact-arena__metric-lbl">{{ actT('typingAccuracy') }}</span>
        </div>
        <div class="uact-arena__metric-card">
          <span class="uact-arena__metric-val">{{ canonicalResult.rawWpm }}</span>
          <span class="uact-arena__metric-lbl">{{ actT('typingRaw') }}</span>
        </div>
        <div class="uact-arena__metric-card">
          <span class="uact-arena__metric-val">{{ canonicalResult.cpm }}</span>
          <span class="uact-arena__metric-lbl">{{ actT('typingCpm') }}</span>
        </div>
        <div class="uact-arena__metric-card">
          <span class="uact-arena__metric-val">
            {{ canonicalResult.consistency !== null ? `${canonicalResult.consistency}%` : '—' }}
          </span>
          <span class="uact-arena__metric-lbl">{{ actT('typingConsistency') }}</span>
        </div>
        <div class="uact-arena__metric-card">
          <span class="uact-arena__metric-val">{{ canonicalResult.errorCount }}</span>
          <span class="uact-arena__metric-lbl">{{ actT('typingErrors') }}</span>
        </div>
        <div class="uact-arena__metric-card">
          <span class="uact-arena__metric-val">{{ canonicalResult.elapsedSeconds }}s</span>
          <span class="uact-arena__metric-lbl">{{ actT('typingTime') }}</span>
        </div>
      </div>

      <!-- Interactive SVG Speed & Errors Chart -->
      <div
        v-if="chartPoints"
        class="uact-arena__chart-wrap"
      >
        <div class="uact-arena__chart-head">
          <span>{{ actT('typingSpeedGraph') }}</span>
          <span
            v-if="hoveredSample"
            class="uact-arena__chart-tooltip"
          >
            {{ hoveredSample.second }}s ·
            {{ hoveredSample.wpm }} WPM ·
            {{ hoveredSample.rawWpm }} Raw ·
            {{ hoveredSample.errors }} err
          </span>
        </div>
        <svg
          class="uact-arena__chart"
          viewBox="0 0 600 160"
          preserveAspectRatio="none"
        >
          <line
            v-for="y in [35, 75, 115]"
            :key="y"
            x1="20"
            :y1="y"
            x2="580"
            :y2="y"
            stroke="var(--border)"
            stroke-dasharray="3 3"
          />
          <polyline
            fill="none"
            stroke="var(--text-2)"
            stroke-width="1.5"
            stroke-dasharray="4 3"
            :points="chartPoints.rawPolyline"
          />
          <polyline
            fill="none"
            stroke="var(--accent)"
            stroke-width="2.5"
            stroke-linecap="round"
            stroke-linejoin="round"
            :points="chartPoints.wpmPolyline"
          />
          <g
            v-for="(pt, idx) in chartPoints.pts"
            :key="idx"
          >
            <circle
              v-if="pt.errors > 0"
              :cx="pt.x"
              :cy="pt.yWpm"
              r="4"
              fill="var(--danger)"
            />
            <circle
              :cx="pt.x"
              :cy="pt.yWpm"
              r="7"
              fill="transparent"
              style="cursor: pointer"
              @mouseenter="hoverSampleIdx = idx"
              @mouseleave="hoverSampleIdx = null"
            />
          </g>
        </svg>
      </div>

      <p class="uact-arena__note">{{ actT('telemetryClientNote') }}</p>

      <div
        v-if="!assigned"
        class="uact-arena__actions"
      >
        <AppButton
          variant="primary"
          @click="triggerRestart"
        >
          {{ actT('typingNewTest') }}
        </AppButton>
      </div>
    </div>

    <!-- Personal Results History -->
    <div
      v-if="history && history.length > 0"
      class="uact-arena__history"
    >
      <h3>{{ actT('typingHistoryTitle') }}</h3>
      <div class="uact-arena__history-list">
        <div
          v-for="row in history"
          :key="String(row.id)"
          class="uact-arena__history-row"
        >
          <strong>{{ (row.metrics as Record<string, unknown>)?.wpm ?? row.rawScore ?? 0 }} WPM</strong>
          <span>{{ (row.metrics as Record<string, unknown>)?.accuracy ?? row.percentage ?? 0 }}%</span>
          <span>{{ (row.metrics as Record<string, unknown>)?.cpm ?? '—' }} CPM</span>
          <span class="uact-arena__history-meta">
            {{ String((row.metrics as Record<string, unknown>)?.language ?? '').toUpperCase() }}
            · {{ String((row.metrics as Record<string, unknown>)?.submode ?? 'time') }}
          </span>
          <span class="uact-arena__history-meta">
            {{ row.completedAt ? new Date(String(row.completedAt)).toLocaleString() : '' }}
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.uact-arena {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.uact-arena--zen-focus .uact-arena__toolbar,
.uact-arena--zen-focus .uact-arena__history {
  opacity: 0.25;
  transition: opacity 0.25s ease;
}

.uact-arena__toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  padding: 10px 14px;
  border-radius: var(--radius-control-m);
  background: var(--surface);
  border: 1px solid var(--border);
  align-items: center;
}

.uact-arena__group {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  padding-right: 10px;
  border-right: 1px solid var(--border);
}

.uact-arena__group:last-child {
  border-right: none;
  padding-right: 0;
}

.uact-arena__pill {
  border: 1px solid transparent;
  background: transparent;
  color: var(--text-2);
  padding: 5px 10px;
  border-radius: var(--radius-control-s);
  font-size: 0.85rem;
  font-weight: 500;
  cursor: pointer;
  transition: var(--theme-transition);
}

.uact-arena__pill:hover {
  color: var(--text-1);
  background: var(--bg);
}

.uact-arena__pill--active {
  color: var(--accent);
  background: var(--bg);
  border-color: var(--accent);
}

.uact-arena__assigned-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 14px;
  border-radius: var(--radius-control-m);
  background: var(--surface);
  border: 1px solid var(--border);
  color: var(--text-2);
  font-size: 0.9rem;
}

.uact-arena__custom-box {
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: flex-start;
}

.uact-arena__custom-input {
  width: 100%;
  padding: 10px 12px;
  border-radius: var(--radius-control-m);
  border: 1px solid var(--border);
  background: var(--bg);
  color: var(--text-1);
  font-family: var(--font-mono);
}

.uact-arena__metrics {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 18px;
  min-height: 44px;
  padding: 8px 14px;
  border-radius: var(--radius-control-m);
  background: var(--surface);
  border: 1px solid var(--border);
  font-variant-numeric: tabular-nums;
}

.uact-arena__timer {
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--accent);
  min-width: 56px;
}

.uact-arena__stat {
  color: var(--text-2);
  font-size: 0.95rem;
}

.uact-arena__stat strong {
  color: var(--text-1);
}

.uact-arena__stage {
  position: relative;
  border-radius: var(--radius-control-m);
  background: var(--card);
  border: 1px solid var(--border);
  padding: 18px 20px;
  cursor: text;
  transition: border-color 0.15s ease;
}

.uact-arena__stage--focused {
  border-color: var(--focus-border, var(--accent));
}

.uact-arena__viewport {
  position: relative;
  height: calc(3 * 2.1em + 12px);
  overflow: hidden;
  font-family: var(--font-mono);
  font-size: 1.3rem;
  line-height: 2.1em;
  letter-spacing: 0.01em;
  user-select: none;
  display: flex;
  flex-wrap: wrap;
  align-content: flex-start;
}

.uact-arena__word {
  display: inline-flex;
  white-space: pre;
}

.uact-char {
  color: var(--text-2);
  transition: color 0.06s ease;
}

.uact-char--ok {
  color: var(--text-1);
}

.uact-char--err {
  color: var(--danger);
  text-decoration: underline;
  text-decoration-thickness: 2px;
}

.uact-char--end {
  width: 0.5ch;
}

.uact-arena__caret {
  position: absolute;
  top: 0;
  left: 0;
  width: 2.5px;
  border-radius: 2px;
  background: var(--accent);
  pointer-events: none;
  transition: transform 75ms ease-out;
  will-change: transform;
}

.uact-arena__caret--idle {
  animation: uact-blink 1s steps(2, start) infinite;
}

@keyframes uact-blink {
  to {
    opacity: 0.15;
  }
}

.uact-arena__zen-view {
  min-height: 120px;
  font-family: var(--font-mono);
  font-size: 1.15rem;
  line-height: 1.7;
}

.uact-arena__zen-hint {
  margin: 0 0 8px;
  font-family: inherit;
  font-size: 0.85rem;
  color: var(--text-2);
}

.uact-arena__zen-text {
  white-space: pre-wrap;
  word-break: break-word;
  color: var(--text-1);
}

.uact-arena__blur-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: color-mix(in srgb, var(--card) 72%, transparent);
  backdrop-filter: blur(3px);
  border-radius: var(--radius-control-m);
  color: var(--text-1);
  font-weight: 500;
  pointer-events: none;
}

.uact-arena__input {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  opacity: 0;
  resize: none;
  border: none;
  padding: 0;
  margin: 0;
  cursor: text;
}

.uact-arena__footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.uact-arena__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.uact-arena__hint,
.uact-arena__note {
  color: var(--text-2);
  font-size: 0.85rem;
  margin: 0;
}

.uact-arena__results {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 18px;
  border-radius: var(--radius-control-m);
  background: var(--card);
  border: 1px solid var(--border);
}

.uact-arena__results-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.uact-arena__results-head h2 {
  margin: 0;
}

.uact-arena__result-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(115px, 1fr));
  gap: 10px;
}

.uact-arena__metric-card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px;
  border-radius: var(--radius-control-s);
  background: var(--surface);
  border: 1px solid var(--border);
}

.uact-arena__metric-card--hero .uact-arena__metric-val {
  color: var(--accent);
}

.uact-arena__metric-val {
  font-size: 1.5rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.uact-arena__metric-lbl {
  font-size: 0.8rem;
  color: var(--text-2);
}

.uact-arena__chart-wrap {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 12px;
  border-radius: var(--radius-control-s);
  background: var(--surface);
  border: 1px solid var(--border);
}

.uact-arena__chart-head {
  display: flex;
  justify-content: space-between;
  font-size: 0.85rem;
  color: var(--text-2);
}

.uact-arena__chart-tooltip {
  color: var(--text-1);
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.uact-arena__chart {
  width: 100%;
  height: 150px;
}

.uact-arena__history {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.uact-arena__history h3 {
  margin: 0;
  font-size: 1rem;
}

.uact-arena__history-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.uact-arena__history-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 14px;
  padding: 8px 12px;
  border-radius: var(--radius-control-s);
  background: var(--surface);
  border: 1px solid var(--border);
  font-variant-numeric: tabular-nums;
}

.uact-arena__history-meta {
  color: var(--text-2);
  font-size: 0.85rem;
}
</style>
