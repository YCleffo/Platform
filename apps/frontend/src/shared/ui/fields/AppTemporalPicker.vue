<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Calendar, Clock, ChevronLeft, ChevronRight, X } from '@/shared/ui/icons';
import {
  normalizeTemporal,
  displayTemporal,
  withinTemporal,
  temporalStep,
  validDate,
  isoDay,
  shiftDay,
  type TemporalMode,
} from '@/shared/formatting/temporal';
import AppDropdown from './AppDropdown.vue';
import AppButton from '../actions/AppButton.vue';
const props = defineProps<{
  mode: TemporalMode;
  input: HTMLInputElement | null;
  disabled?: boolean;
  readonly?: boolean;
  min?: string;
  max?: string;
  step?: number;
}>();
const model = defineModel<string | number>({ default: '' });
const { t, locale } = useI18n();
const uid = `temporal-${useId()}`;
const open = ref(false);
const trigger = ref<HTMLButtonElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const point = ref({ left: 0, top: 0, width: 296, maxHeight: 600 });
const today = () => isoDay(new Date());
const selected = ref(today());
const focusedDay = ref(today());
const year = ref(new Date().getFullYear());
const month = ref(new Date().getMonth() + 1);
const hour = ref('12');
const minute = ref('00');
const second = ref('00');
const seconds = computed(
  () => (props.step ?? 60) < 60 || /:\d{2}:\d{2}$/.test(String(model.value)),
);
const normalized = computed(() => normalizeTemporal(String(model.value), props.mode, locale.value));
const formatted = computed(() => displayTemporal(String(model.value), props.mode, locale.value));
const placeholder = computed(() =>
  props.mode === 'time'
    ? seconds.value
      ? 'HH:mm:ss'
      : 'HH:mm'
    : t(locale.value.startsWith('ru') ? 'picker.dateFormatRu' : 'picker.dateFormatEn') +
      (props.mode === 'datetime-local' ? ' HH:mm' : ''),
);
const invalid = computed(() =>
  !String(model.value)
    ? ''
    : normalized.value === null
      ? t('picker.invalid')
      : !withinTemporal(normalized.value, props.mode, props.min, props.max)
        ? t('picker.range')
        : !temporalStep(normalized.value, props.mode, props.step, props.min)
          ? t('common.timeStep')
          : '',
);
const target = computed(() => trigger.value?.closest('[role="dialog"]') ?? 'body');
const clock = computed(
  () => `${hour.value}:${minute.value}${seconds.value ? ':' + second.value : ''}`,
);
const draft = computed(() =>
  props.mode === 'date'
    ? selected.value
    : props.mode === 'time'
      ? clock.value
      : `${selected.value}T${clock.value}`,
);
const canApply = computed(
  () =>
    normalizeTemporal(draft.value, props.mode, locale.value) !== null &&
    withinTemporal(draft.value, props.mode, props.min, props.max) &&
    temporalStep(draft.value, props.mode, props.step, props.min),
);
const firstWeekday = computed(() => (locale.value.startsWith('ru') ? 1 : 0));
const months = computed(() =>
  Array.from({ length: 12 }, (_, index) => ({
    value: String(index + 1),
    label: new Intl.DateTimeFormat(locale.value, { month: 'long', timeZone: 'UTC' }).format(
      new Date(Date.UTC(2026, index, 1)),
    ),
  })),
);
const years = computed(() => {
  const first = Math.max(1, Math.min(1900, year.value - 10));
  const last = Math.min(9999, Math.max(2126, year.value + 10));
  return Array.from({ length: last - first + 1 }, (_, index) => ({
    value: String(first + index),
    label: String(first + index),
  }));
});
const monthValue = computed({
  get: () => String(month.value),
  set: (value: string) => {
    month.value = Number(value);
  },
});
const yearValue = computed({
  get: () => String(year.value),
  set: (value: string) => {
    year.value = Number(value);
  },
});
const weeks = computed(() => {
  const start = `${String(year.value).padStart(4, '0')}-${String(month.value).padStart(2, '0')}-01`;
  const weekday = new Date(start + 'T12:00:00Z').getUTCDay();
  const first = shiftDay(start, -((weekday - firstWeekday.value + 7) % 7));
  return Array.from({ length: 6 }, (_, week) =>
    Array.from({ length: 7 }, (_, day) => shiftDay(first, week * 7 + day)),
  );
});
const weekdays = computed(() =>
  Array.from({ length: 7 }, (_, index) =>
    new Intl.DateTimeFormat(locale.value, { weekday: 'short', timeZone: 'UTC' }).format(
      new Date(Date.UTC(2026, 10, 1 + ((index + firstWeekday.value) % 7))),
    ),
  ),
);
const hours = Array.from({ length: 24 }, (_, index) => ({
  value: String(index).padStart(2, '0'),
  label: String(index).padStart(2, '0'),
}));
const minutes = Array.from({ length: 60 }, (_, index) => ({
  value: String(index).padStart(2, '0'),
  label: String(index).padStart(2, '0'),
}));
let observer: ResizeObserver | undefined;
function parse(value: string): string {
  return normalizeTemporal(value, props.mode, locale.value) ?? value;
}
function dayLabel(day: string): string {
  return new Intl.DateTimeFormat(locale.value, { dateStyle: 'full', timeZone: 'UTC' }).format(
    new Date(day + 'T12:00:00Z'),
  );
}
function allowedDay(day: string): boolean {
  return (
    validDate(day) &&
    withinTemporal(day, 'date', props.min?.slice(0, 10), props.max?.slice(0, 10)) &&
    (props.mode !== 'date' || temporalStep(day, 'date', props.step, props.min))
  );
}
function view(day: string): void {
  year.value = Number(day.slice(0, 4));
  month.value = Number(day.slice(5, 7));
}
function layout(): void {
  if (!open.value || !trigger.value || !panel.value) return;
  const box =
    trigger.value.closest('.app-input__control')?.getBoundingClientRect() ??
    trigger.value.getBoundingClientRect();
  const vv = window.visualViewport;
  const left = vv?.offsetLeft ?? 0;
  const top = vv?.offsetTop ?? 0;
  const width = vv?.width ?? window.innerWidth;
  const height = vv?.height ?? window.innerHeight;
  if (box.bottom < top || box.top > top + height) {
    close(false);
    return;
  }
  const panelWidth = Math.min(320, width - 24);
  const below = top + height - box.bottom - 12;
  const above = box.top - top - 12;
  const preferBelow = below >= Math.min(panel.value.scrollHeight, 430) || below >= above;
  const available = preferBelow ? below : above;
  const maxHeight = available < 160 ? Math.max(0, height - 24) : available;
  const panelHeight = Math.min(panel.value.scrollHeight, maxHeight);
  point.value = {
    left: Math.max(left + 12, Math.min(box.left, left + width - panelWidth - 12)),
    top:
      available < 160
        ? top + 12
        : Math.max(top + 12, preferBelow ? box.bottom + 6 : box.top - panelHeight - 6),
    width: panelWidth,
    maxHeight,
  };
}
function close(restore = true): void {
  open.value = false;
  if (restore) trigger.value?.focus({ preventScroll: true });
}
function outside(event: PointerEvent): void {
  const node = event.target as Node;
  if (!panel.value?.contains(node) && !trigger.value?.contains(node)) close(false);
}
function outsideFocus(event: FocusEvent): void {
  const node = event.target as Node;
  if (!panel.value?.contains(node) && !trigger.value?.contains(node) && node !== props.input)
    close(false);
}
async function focusDay(): Promise<void> {
  await nextTick();
  panel.value
    ?.querySelector<HTMLButtonElement>(`[data-day="${focusedDay.value}"]`)
    ?.focus({ preventScroll: true });
}
async function show(): Promise<void> {
  if (props.disabled || props.readonly) return;
  const initial = normalized.value || (props.mode === 'time' ? '' : today());
  const date = initial.split('T')[0];
  selected.value = props.mode === 'time' ? today() : date || today();
  if (props.mode !== 'time') {
    const lower = props.min?.slice(0, 10);
    const upper = props.max?.slice(0, 10);
    if (lower && validDate(lower) && selected.value < lower) selected.value = lower;
    if (upper && validDate(upper) && selected.value > upper) selected.value = upper;
  }
  focusedDay.value = selected.value;
  view(selected.value);
  if (!allowedDay(focusedDay.value))
    focusedDay.value =
      weeks.value
        .flat()
        .find((day) => Number(day.slice(5, 7)) === month.value && allowedDay(day)) ??
      focusedDay.value;
  const current = props.mode === 'time' ? initial : initial.split('T')[1];
  [hour.value, minute.value, second.value] = (
    current ||
    `${String(new Date().getHours()).padStart(2, '0')}:${String(new Date().getMinutes()).padStart(2, '0')}:00`
  ).split(':') as [string, string, string];
  second.value ||= '00';
  open.value = true;
  await nextTick();
  layout();
  if (props.mode !== 'time') await focusDay();
  else panel.value?.querySelector<HTMLButtonElement>('.app-dropdown__trigger')?.focus();
}
function apply(value = draft.value): void {
  if (
    value &&
    (!withinTemporal(value, props.mode, props.min, props.max) ||
      !temporalStep(value, props.mode, props.step, props.min))
  )
    return;
  model.value = value;
  close();
}
function selectDay(day: string): void {
  if (!allowedDay(day)) return;
  selected.value = day;
  focusedDay.value = day;
  view(day);
  if (props.mode === 'date') apply(day);
}
function changeMonth(delta: number): void {
  const date = new Date(
    `${String(year.value).padStart(4, '0')}-${String(month.value).padStart(2, '0')}-15T12:00:00Z`,
  );
  date.setUTCMonth(date.getUTCMonth() + delta);
  if (date.getUTCFullYear() < 1 || date.getUTCFullYear() > 9999) return;
  year.value = date.getUTCFullYear();
  month.value = date.getUTCMonth() + 1;
}
watch([year, month, open], () => {
  if (open.value && !weeks.value.flat().includes(focusedDay.value))
    focusedDay.value =
      weeks.value
        .flat()
        .find((day) => Number(day.slice(5, 7)) === month.value && allowedDay(day)) ??
      weeks.value[0]![0]!;
});
function keyboard(event: KeyboardEvent): void {
  const delta: Record<string, number> = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
  let next: string | undefined;
  if (event.key in delta) next = shiftDay(focusedDay.value, delta[event.key]!);
  else if (event.key === 'Home' || event.key === 'End') {
    const offset =
      (new Date(focusedDay.value + 'T12:00:00Z').getUTCDay() - firstWeekday.value + 7) % 7;
    next = shiftDay(focusedDay.value, event.key === 'Home' ? -offset : 6 - offset);
  } else if (event.key === 'PageUp' || event.key === 'PageDown') {
    changeMonth(event.key === 'PageUp' ? -1 : 1);
    next = `${year.value}-${String(month.value).padStart(2, '0')}-01`;
  }
  if (next) {
    event.preventDefault();
    if (allowedDay(next)) {
      focusedDay.value = next;
      view(next);
      void focusDay();
    }
  }
}
watch(
  open,
  (value) => {
    if (value) {
      document.addEventListener('pointerdown', outside, true);
      document.addEventListener('focusin', outsideFocus, true);
      window.addEventListener('resize', layout);
      window.addEventListener('scroll', layout, true);
      window.visualViewport?.addEventListener('resize', layout);
      if (typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(layout);
        if (panel.value) observer.observe(panel.value);
      }
    } else cleanup();
  },
  { flush: 'post' },
);
function cleanup(): void {
  document.removeEventListener('pointerdown', outside, true);
  document.removeEventListener('focusin', outsideFocus, true);
  window.removeEventListener('resize', layout);
  window.removeEventListener('scroll', layout, true);
  window.visualViewport?.removeEventListener('resize', layout);
  observer?.disconnect();
}
function inactive(element: Element): void {
  element.setAttribute('inert', '');
  element.setAttribute('aria-hidden', 'true');
}
watch(
  () => [props.disabled, props.readonly],
  () => {
    if (props.disabled || props.readonly) close(false);
  },
);
onBeforeUnmount(cleanup);
defineExpose({ show, formatted, placeholder, invalid, parse, open });
</script>

<template>
  <button
    ref="trigger"
    type="button"
    class="temporal-trigger"
    :aria-label="t(mode === 'time' ? 'picker.chooseTime' : 'picker.chooseDate')"
    aria-haspopup="dialog"
    :aria-expanded="open"
    :aria-controls="open ? uid : undefined"
    :disabled="disabled || readonly"
    @click.stop="open ? close() : show()"
  >
    <Clock
      v-if="mode === 'time'"
      :size="20"
      aria-hidden="true"
    />
    <Calendar
      v-else
      :size="20"
      aria-hidden="true"
    />
  </button>
  <Teleport :to="target">
    <Transition
      name="temporal"
      @before-leave="inactive"
    >
      <section
        v-if="open"
        :id="uid"
        ref="panel"
        class="app-temporal"
        role="dialog"
        :aria-label="t(mode === 'time' ? 'picker.chooseTime' : 'picker.chooseDate')"
        :style="{
          left: `${point.left}px`,
          top: `${point.top}px`,
          width: `${point.width}px`,
          maxHeight: `${point.maxHeight}px`,
        }"
        @keydown.esc.stop.prevent="close()"
      >
        <header class="app-temporal__heading">
          <span>{{ t(mode === 'time' ? 'picker.chooseTime' : 'picker.chooseDate') }}</span>
          <button
            type="button"
            :aria-label="t('common.close')"
            @click="close()"
          >
            <X
              :size="16"
              aria-hidden="true"
            />
          </button>
        </header>
        <template v-if="mode !== 'time'">
          <div class="app-temporal__navigation">
            <button
              type="button"
              :aria-label="t('picker.previousMonth')"
              @click="changeMonth(-1)"
            >
              <ChevronLeft
                :size="18"
                aria-hidden="true"
              />
            </button>
            <AppDropdown
              v-model="monthValue"
              :options="months"
              :label="t('picker.month')"
              size="s"
            />
            <AppDropdown
              v-model="yearValue"
              :options="years"
              :label="t('picker.year')"
              searchable
              size="s"
            />
            <button
              type="button"
              :aria-label="t('picker.nextMonth')"
              @click="changeMonth(1)"
            >
              <ChevronRight
                :size="18"
                aria-hidden="true"
              />
            </button>
          </div>
          <table
            role="grid"
            :aria-label="`${months[month - 1]?.label} ${year}`"
            @keydown="keyboard"
          >
            <thead>
              <tr>
                <th
                  v-for="(day, index) in weekdays"
                  :key="index"
                  scope="col"
                >
                  {{ day }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(week, index) in weeks"
                :key="index"
              >
                <td
                  v-for="day in week"
                  :key="day"
                  :aria-selected="day === selected"
                >
                  <button
                    type="button"
                    :data-day="day"
                    :aria-label="dayLabel(day)"
                    :aria-current="day === today() ? 'date' : undefined"
                    :tabindex="day === focusedDay ? 0 : -1"
                    :disabled="!allowedDay(day)"
                    :class="{
                      'app-temporal__selected': day === selected,
                      'app-temporal__outside': Number(day.slice(5, 7)) !== month,
                    }"
                    @click="selectDay(day)"
                  >
                    {{ Number(day.slice(8)) }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </template>
        <div
          v-if="mode !== 'date'"
          class="app-temporal__time"
        >
          <AppDropdown
            v-model="hour"
            :options="hours"
            :label="t('picker.hours')"
            searchable
            size="s"
          />
          <span aria-hidden="true">:</span>
          <AppDropdown
            v-model="minute"
            :options="minutes"
            :label="t('picker.minutes')"
            searchable
            size="s"
          />
          <template v-if="seconds">
            <span aria-hidden="true">:</span>
            <AppDropdown
              v-model="second"
              :options="minutes"
              :label="t('picker.seconds')"
              searchable
              size="s"
            />
          </template>
        </div>
        <footer class="app-temporal__footer">
          <AppButton
            v-if="mode !== 'time'"
            size="s"
            variant="ghost"
            :disabled="!allowedDay(today())"
            @click="selectDay(today())"
          >
            {{ t('picker.today') }}
          </AppButton>
          <AppButton
            size="s"
            variant="ghost"
            @click="apply('')"
          >
            {{ t('picker.clear') }}
          </AppButton>
          <AppButton
            v-if="mode !== 'date'"
            size="s"
            :disabled="!canApply"
            @click="apply()"
          >
            {{ t('picker.apply') }}
          </AppButton>
        </footer>
      </section>
    </Transition>
  </Teleport>
</template>
<style scoped>
.temporal-trigger {
  transition: var(--theme-transition);
  flex: none;
  display: grid;
  place-items: center;
  min-width: var(--control-h-s);
  min-height: var(--control-h-s);
  padding: 0;
  border: 0;
  border-radius: var(--radius-control-s);
  background: transparent;
  color: var(--text-2);
  cursor: pointer;
}
.temporal-trigger:hover:not(:disabled) {
  background: var(--surface-hover);
  color: var(--text-1);
}
.temporal-trigger:disabled {
  opacity: 0.5;
  cursor: default;
}
.app-temporal {
  transition: var(--theme-transition);
  position: fixed;
  z-index: var(--z-menu);
  display: grid;
  gap: var(--space-8);
  padding: var(--space-12);
  overflow-y: auto;
  overscroll-behavior: contain;
  border: 1px solid var(--border);
  border-radius: var(--radius-control-l);
  box-shadow: var(--shadow-popover);
  background: var(--overlay-surface);
  color: var(--text-1);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
}
.app-temporal__heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-8);
  font-weight: var(--type-body-medium-emphasis-weight);
}
.app-temporal__heading button,
.app-temporal__navigation > button {
  display: grid;
  place-items: center;
  min-width: var(--control-h-s);
  min-height: var(--control-h-s);
  border: 0;
  padding: 0;
  border-radius: var(--radius-control-s);
  background: transparent;
  color: inherit;
  cursor: pointer;
}
.app-temporal__navigation {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) minmax(0, 1fr) auto;
  align-items: center;
  gap: var(--space-4);
}
.app-temporal__navigation :deep(.app-dropdown__label) {
  display: none;
}
.app-temporal table {
  table-layout: fixed;
  width: 100%;
  border-collapse: collapse;
}
.app-temporal th {
  padding: var(--space-4) 0;
  color: var(--text-2);
  text-align: center;
  font-weight: var(--type-body-medium-emphasis-weight);
}
.app-temporal td {
  padding: var(--space-2);
  text-align: center;
}
.app-temporal td button {
  transition: var(--theme-transition);
  display: block;
  width: 100%;
  min-height: var(--control-h-s);
  padding: var(--space-4);
  border: 1px solid transparent;
  border-radius: var(--radius-control-s);
  background: transparent;
  color: var(--text-1);
  cursor: pointer;
}
.app-temporal td button:hover:not(:disabled),
.app-temporal__navigation > button:hover {
  background: var(--surface-hover);
}
.app-temporal td button:disabled {
  opacity: 0.35;
  cursor: default;
}
.app-temporal td .app-temporal__outside {
  color: var(--text-2);
}
.app-temporal td .app-temporal__selected {
  background: var(--accent);
  color: var(--color-white);
}
.app-temporal td button.app-temporal__selected:hover:not(:disabled) {
  background: var(--accent-hover);
}
.app-temporal td button[aria-current='date'] {
  border-color: var(--accent);
}
.app-temporal button:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 0;
}
.app-temporal__time {
  display: flex;
  gap: var(--space-4);
  align-items: end;
  border-top: 1px solid var(--border);
  padding-top: var(--space-8);
}
.app-temporal__time :deep(.app-dropdown) {
  flex: 1;
  min-width: 0;
}
.app-temporal__time > span {
  align-self: center;
}
.app-temporal__footer {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-4);
  flex-wrap: wrap;
  padding-top: var(--space-8);
  border-top: 1px solid var(--border);
}
.temporal-enter-active,
.temporal-leave-active {
  transition:
    var(--theme-transition),
    opacity var(--motion-enter-duration) var(--motion-ease),
    transform var(--motion-enter-duration) var(--motion-ease);
}
.temporal-enter-from,
.temporal-leave-to {
  opacity: 0;
  transform: translateY(var(--space-4));
}
.temporal-leave-active {
  pointer-events: none;
}
</style>
