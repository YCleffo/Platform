<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { Check, ChevronLeft, ChevronRight, X } from '@/shared/ui/icons';
import { searchMenu, type MenuItem } from './types';

const props = withDefaults(defineProps<{
  items: readonly MenuItem[];
  label: string;
  anchor?: HTMLElement | null;
  point?: { x: number; y: number } | null;
  id?: string;
  searchable?: boolean;
  closeOnSelect?: boolean;
  initialFocus?: 'first' | 'last' | 'selected';
  size?: 'm' | 's';
}>(), { anchor: null, point: null, id: undefined, searchable: false, closeOnSelect: true, initialFocus: 'first', size: 'm' });
const open = defineModel<boolean>({ default: false });
const emit = defineEmits<{ select: [item: MenuItem] }>();
const uid = useId();
const menuId = computed(() => props.id ?? `menu-${uid}`);
const layer = ref<HTMLElement | null>(null);
const query = ref('');
const path = ref<MenuItem[]>([]);
const viewport = ref(typeof window === 'undefined' ? 1024 : window.innerWidth);
const panelWidth = ref(280);
const positions = ref<{ left: number; top: number }[]>([]);
const drill = computed(() => viewport.value < 640 || (path.value.length + 1) * (panelWidth.value + 6) > viewport.value - 18);
const levels = computed(() => {
  if (query.value.trim()) return [{ items: searchMenu(props.items, query.value), label: props.label, depth: 0 }];
  const all = [{ items: props.items, label: props.label, depth: 0 }, ...path.value.map((item, index) => ({ items: item.children ?? [], label: item.label, depth: index + 1 }))];
  return drill.value ? all.slice(-1) : all;
});
const teleportTarget = computed(() => props.anchor?.closest('[role="dialog"]') ?? 'body');
let previousFocus: HTMLElement | null = null;
let observer: ResizeObserver | undefined;
let frame = 0;
let typeAhead = '';
let typeTime = 0;
let disposed = false;

function panels(): HTMLElement[] { return Array.from(layer.value?.querySelectorAll<HTMLElement>('.app-menu__panel') ?? []); }
function buttons(panel?: HTMLElement): HTMLButtonElement[] {
  return Array.from(panel?.querySelectorAll<HTMLButtonElement>('[data-menu-id]') ?? []);
}
function focusItem(panelIndex: number, last = false): void {
  const panel = panels()[panelIndex];
  const items = buttons(panel);
  (last ? items.at(-1) : items[0])?.focus({ preventScroll: true });
  if (!items.length) panel?.querySelector<HTMLInputElement>('input')?.focus({ preventScroll: true });
}
async function clearSearch(): Promise<void> {
  query.value = '';
  await nextTick();
  layer.value?.querySelector<HTMLInputElement>('input')?.focus({ preventScroll: true });
}
function row(id: string): HTMLButtonElement | undefined {
  return panels().flatMap(buttons).find(button => button.dataset.menuId === id);
}
function close(restore = true): void {
  open.value = false;
  if (restore) (props.anchor && props.anchor.tabIndex >= 0 ? props.anchor : previousFocus)?.focus();
}
async function back(depth: number): Promise<void> {
  const parent = path.value[depth - 1];
  path.value = path.value.slice(0, depth - 1);
  await nextTick();
  layout();
  await nextTick();
  if (parent) row(parent.id)?.focus({ preventScroll: true });
}
async function enter(item: MenuItem, depth: number, focus: boolean): Promise<void> {
  if (item.disabled) return;
  path.value = path.value.slice(0, depth);
  if (item.children?.length) {
    path.value.push(item);
    await nextTick();
    layout();
    await nextTick();
    if (focus) focusItem(panels().length - 1);
  }
}
function activate(item: MenuItem, depth: number): void {
  if (item.disabled) return;
  if (item.children?.length) { void enter(item, depth, true); return; }
  emit('select', item);
  if (props.closeOnSelect) close();
}
function hover(item: MenuItem, depth: number, event: PointerEvent): void {
  // A hover must never replace its own row with the mobile/drill panel before click.
  if (event.pointerType !== 'mouse' || query.value.trim() || drill.value || (depth + 2) * (panelWidth.value + 6) > viewport.value - 18) return;
  if (path.value[depth]?.id !== item.id) void enter(item, depth, false);
}
function layout(): void {
  const width = window.innerWidth;
  viewport.value = width;
  const rect = props.anchor?.getBoundingClientRect();
  const anchorWidth = rect?.width ?? 0;
  panelWidth.value = props.point
    ? Math.min(280, width - 24)
    : Math.min(Math.max(anchorWidth, 96), 360, width - 24);
  const elements = panels();
  const w = panelWidth.value;
  const total = elements.length * (w + 6) - 6;
  const x = props.point?.x ?? rect?.left ?? 12;
  const right = x + total <= width - 12;
  const left = x + w - total >= 12;
  const origin = right || !left ? Math.min(Math.max(12, x), Math.max(12, width - total - 12)) : Math.min(x, width - w - 12);
  const down = props.point?.y ?? rect?.bottom ?? 12;
  let top = down + (props.point ? 0 : 6);
  positions.value = elements.map((panel, index) => {
    if (index > 0) {
      const parent = path.value[index - 1];
      const parentRow = parent ? row(parent.id) : undefined;
      const parentPanel = elements[index - 1];
      top = parentRow && parentPanel ? top + parentRow.getBoundingClientRect().top - parentPanel.getBoundingClientRect().top : top;
    } else if (!props.point && rect && top + panel.offsetHeight > window.innerHeight - 12 && rect.top - panel.offsetHeight - 6 >= 12) {
      top = rect.top - panel.offsetHeight - 6;
    }
    top = Math.max(12, Math.min(top, window.innerHeight - panel.offsetHeight - 12));
    return { left: origin + (right || !left ? index : -index) * (w + 6), top };
  });
}
function scheduleLayout(event?: Event): void {
  if (disposed || !open.value) return;
  if (event?.type === 'scroll' && event.target instanceof Node && layer.value?.contains(event.target)) {
    // Scrolling a parent list invalidates its hovered row alignment.
    const panel = event.target instanceof HTMLElement ? event.target.closest<HTMLElement>('[data-menu-depth]') : null;
    if (!drill.value && panel && Number(panel.dataset.menuDepth) < path.value.length) path.value = path.value.slice(0, Number(panel.dataset.menuDepth));
  }
  cancelAnimationFrame(frame);
  frame = requestAnimationFrame(layout);
}
function isAnchorLabel(target: Node): boolean {
  if (!(target instanceof Element) || props.anchor === null) return false;
  const label = target.closest('label');
  if (!(label instanceof HTMLLabelElement)) return false;
  return label.control === props.anchor || (props.anchor.id !== '' && label.htmlFor === props.anchor.id);
}
function outside(event: PointerEvent): void {
  const target = event.target;
  if (!(target instanceof Node) || layer.value?.contains(target) || props.anchor?.contains(target)) return;
  if (isAnchorLabel(target)) return;
  close(false);
}
function onKeydown(event: KeyboardEvent): void {
  if (!open.value || disposed) return;
  const target = event.target instanceof HTMLElement ? event.target : null;
  if (event.key === 'Escape') {
    event.preventDefault(); event.stopImmediatePropagation();
    const depth = Number(target?.closest<HTMLElement>('[data-menu-depth]')?.dataset.menuDepth ?? path.value.length);
    if (depth && !query.value) void back(depth); else close();
    return;
  }
  if (!target || !layer.value?.contains(target)) return;
  if (event.key === 'Tab' && !event.ctrlKey && !event.metaKey) {
    // Let the browser advance from the trigger in its original form/dialog order.
    close(); return;
  }
  const panel = target.closest<HTMLElement>('.app-menu__panel');
  if (!panel) return;
  const panelIndex = panels().indexOf(panel);
  const items = buttons(panel);
  const current = items.indexOf(target as HTMLButtonElement);
  const depth = Number(panel.dataset.menuDepth);
  if (target instanceof HTMLInputElement && !['ArrowDown', 'ArrowUp'].includes(event.key)) return;
  let index = current;
  switch (event.key) {
    case 'ArrowDown': index = current < 0 ? 0 : (current + 1) % items.length; break;
    case 'ArrowUp': index = current < 0 ? items.length - 1 : (current - 1 + items.length) % items.length; break;
    case 'Home': index = 0; break;
    case 'End': index = items.length - 1; break;
    case 'ArrowLeft': if (depth) { event.preventDefault(); void back(depth); } return;
    case 'ArrowRight': {
      const item = levels.value[panelIndex]?.items.find(item => item.id === target.dataset.menuId);
      if (item?.children?.length) { event.preventDefault(); void enter(item, depth, true); }
      return;
    }
    default:
      if (event.key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey || event.key === ' ') return;
      typeAhead = Date.now() - typeTime > 600 ? event.key : typeAhead + event.key;
      typeTime = Date.now();
      index = items.findIndex((item, index) => index > current && item.textContent?.trim().toLocaleLowerCase().startsWith(typeAhead.toLocaleLowerCase()));
      if (index < 0) index = items.findIndex(item => item.textContent?.trim().toLocaleLowerCase().startsWith(typeAhead.toLocaleLowerCase()));
  }
  event.preventDefault();
  items[index]?.focus({ preventScroll: true });
  items[index]?.scrollIntoView({ block: 'nearest' });
}
function cleanup(): void {
  document.removeEventListener('pointerdown', outside, true);
  document.removeEventListener('keydown', onKeydown, true);
  window.removeEventListener('resize', scheduleLayout);
  window.removeEventListener('scroll', scheduleLayout, true);
  observer?.disconnect();
  cancelAnimationFrame(frame);
}
watch(open, async value => {
  cleanup();
  if (!value) return;
  previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  query.value = ''; path.value = []; typeAhead = '';
  await nextTick();
  if (!open.value || disposed) return;
  layout(); await nextTick(); layout();
  if (props.searchable && props.initialFocus !== 'last') layer.value?.querySelector<HTMLInputElement>('input')?.focus({ preventScroll: true });
  else {
    const selected = props.initialFocus === 'selected' ? panels()[0]?.querySelector<HTMLButtonElement>('[aria-checked="true"]') : null;
    if (selected) selected.focus({ preventScroll: true });
    else focusItem(0, props.initialFocus === 'last');
  }
  document.addEventListener('pointerdown', outside, true);
  document.addEventListener('keydown', onKeydown, true);
  window.addEventListener('resize', scheduleLayout);
  window.addEventListener('scroll', scheduleLayout, true);
  if (typeof ResizeObserver !== 'undefined') { observer = new ResizeObserver(() => scheduleLayout()); panels().forEach(panel => observer?.observe(panel)); }
}, { immediate: true });
watch(query, async () => {
  const input = document.activeElement instanceof HTMLInputElement && layer.value?.contains(document.activeElement) ? document.activeElement : null;
  const start = input?.selectionStart ?? null;
  const end = input?.selectionEnd ?? null;
  path.value = [];
  await nextTick(); layout();
  if (input) {
    const replacement = layer.value?.querySelector<HTMLInputElement>('input');
    replacement?.focus({ preventScroll: true });
    if (start !== null && end !== null) replacement?.setSelectionRange(start, end);
  }
});
watch(levels, async () => { await nextTick(); observer?.disconnect(); panels().forEach(panel => observer?.observe(panel)); if (open.value) scheduleLayout(); });
watch(() => props.items, () => { if (open.value) scheduleLayout(); });
onBeforeUnmount(() => { disposed = true; cleanup(); });
</script>

<template>
  <Teleport :to="teleportTarget">
    <div v-if="open" :id="`${menuId}-layer`" ref="layer" class="app-menu" @contextmenu.prevent>
      <section v-for="(level, index) in levels" :key="level.depth" class="app-menu__panel" :class="{ 'app-menu__panel--s': size === 's' }" :data-menu-depth="level.depth"
        :style="{ width: `${panelWidth}px`, left: `${positions[index]?.left ?? 12}px`, top: `${positions[index]?.top ?? 12}px` }">
        <header v-if="level.depth || searchable" class="app-menu__header" :class="{ 'app-menu__header--nested': level.depth }">
          <button v-if="level.depth" type="button" class="app-menu__back" @click="back(level.depth)"><ChevronLeft :size="18" aria-hidden="true" /><span>{{ $t('menus.back') }} <small>{{ level.label }}</small></span></button>
          <div v-if="searchable && (!level.depth || drill)" class="app-menu__search">
            <input v-model="query" type="search" :aria-label="$t('menus.search')" :placeholder="$t('menus.search')" autocomplete="off" />
            <button v-if="query" type="button" :aria-label="$t('menus.clearSearch')" @click="clearSearch"><X :size="16" aria-hidden="true" /></button>
          </div>
        </header>
        <ul :id="index === 0 ? menuId : `${menuId}-${level.depth}`" role="menu" :aria-label="level.label" class="app-menu__list">
          <li v-for="item in level.items" :key="item.id" :role="item.kind === 'separator' ? 'separator' : 'none'" :class="{ 'app-menu__separator': item.kind === 'separator' }">
            <span v-if="item.kind === 'group'" class="app-menu__group">{{ item.label }}</span>
            <button v-else-if="item.kind !== 'separator'" type="button" :data-menu-id="item.id" tabindex="-1"
              :role="item.checkType ? `menuitem${item.checkType}` : 'menuitem'" :aria-checked="item.checkType ? !!item.checked : undefined"
              :aria-disabled="item.disabled || undefined" :aria-haspopup="item.children?.length ? 'menu' : undefined"
              :aria-expanded="item.children?.length ? path[level.depth]?.id === item.id : undefined"
              :aria-controls="item.children?.length && path[level.depth]?.id === item.id ? `${menuId}-${level.depth + 1}` : undefined"
              :aria-owns="item.children?.length && path[level.depth]?.id === item.id && !drill ? `${menuId}-${level.depth + 1}` : undefined"
              class="app-menu__item" @click="activate(item, level.depth)" @pointerenter="hover(item, level.depth, $event)">
              <component :is="item.icon" v-if="item.icon" :size="18" aria-hidden="true" />
              <span class="app-menu__text"><span>{{ item.label }}</span><small v-if="item.description">{{ item.description }}</small></span>
              <kbd v-if="item.shortcut">{{ item.shortcut }}</kbd>
              <Check v-if="item.checked" :size="18" aria-hidden="true" />
              <ChevronRight v-if="item.children?.length" :size="18" aria-hidden="true" />
            </button>
          </li>
        </ul>
        <p v-if="!level.items.length" class="app-menu__empty" role="status">{{ $t('menus.noResults') }}</p>
        <slot name="footer" />
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.app-menu { position: fixed; inset: 0; z-index: var(--z-menu); pointer-events: none; }
.app-menu__panel { position: fixed; display: flex; flex-direction: column; max-height: calc(100dvh - 24px); pointer-events: auto; padding: var(--space-6); color: var(--text-1); background: var(--surface); border: 1px solid var(--border-strong); border-radius: var(--radius-control-l); box-shadow: var(--shadow-popover); transition: var(--theme-transition); animation: menu-enter var(--motion-enter-duration) var(--motion-ease); }
@keyframes menu-enter { from { opacity: 0; transform: translateY(var(--space-4)); } to { opacity: 1; transform: translateY(0); } }
.app-menu__header { display: grid; gap: var(--space-6); padding: var(--space-4); transition: var(--theme-transition); }
.app-menu__header--nested { border-bottom: 1px solid var(--border); }
.app-menu__back, .app-menu__search { display: flex; gap: var(--space-8); align-items: center; min-width: 0; }
.app-menu__back { padding: var(--space-8); text-align: left; }
.app-menu__back span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.app-menu__back small { display: block; overflow: hidden; text-overflow: ellipsis; font-size: var(--type-body-small-size); line-height: var(--type-body-small-line); color: var(--text-2); transition: var(--theme-transition); }
.app-menu__search { border: 1px solid var(--border); border-radius: var(--radius-control-s); padding: var(--space-6) var(--space-8); background: var(--bg); transition: var(--theme-transition); }
.app-menu__search:focus-within { border-color: var(--focus-border); box-shadow: inset 0 0 0 1px var(--focus-border); transition: var(--theme-transition); }
.app-menu__search input { width: 100%; min-width: 0; border: 0; outline: none; appearance: none; background: transparent; color: inherit; font: inherit; font-size: var(--type-body-medium-size); line-height: var(--type-body-medium-line); }
.app-menu__search input:focus-visible { box-shadow: none; }
.app-menu__search input::-webkit-search-cancel-button { display: none; }
.app-menu__search button { display: flex; padding: var(--space-4); border-radius: var(--radius-control-s); }
.app-menu__search button:hover { background: var(--surface-hover); }
.app-menu__list { overflow-y: auto; overscroll-behavior: contain; min-height: 0; margin: 0; padding: 0; list-style: none; }
.app-menu__item, .app-menu__back { width: 100%; min-height: var(--button-height-m); border: 0; border-radius: var(--radius-control-s); background: transparent; color: inherit; cursor: pointer; font: inherit; font-size: var(--type-body-medium-size); line-height: var(--type-body-medium-line); transition: var(--theme-transition); }
.app-menu__item { display: flex; align-items: center; gap: var(--gap-s); padding: var(--space-8) var(--space-12); text-align: left; }
.app-menu__item:hover, .app-menu__item:focus, .app-menu__back:hover, .app-menu__item[aria-expanded="true"] { background: var(--surface-hover); outline: none; transition: var(--theme-transition); }
.app-menu__item[aria-checked="true"] { color: var(--accent-text); background: var(--accent-soft); transition: var(--theme-transition); }
.app-menu__item:focus-visible, .app-menu__back:focus-visible { box-shadow: inset 0 0 0 2px var(--focus-border); transition: var(--theme-transition); }
.app-menu__item[aria-disabled="true"] { opacity: .45; cursor: not-allowed; }
.app-menu__panel--s { padding: var(--space-4); border-radius: var(--radius-control-m); }
.app-menu__panel--s .app-menu__header { padding: var(--space-2); gap: var(--space-4); }
.app-menu__panel--s .app-menu__item, .app-menu__panel--s .app-menu__back { min-height: 30px; padding: var(--space-4) var(--space-8); gap: var(--space-6); font-size: var(--type-body-small-size); line-height: var(--type-body-small-line); }
.app-menu__panel--s .app-menu__item svg { width: 16px; height: 16px; }
.app-menu__panel--s .app-menu__search { padding: var(--space-4) var(--space-6); }
.app-menu__panel--s .app-menu__search input { font-size: var(--type-body-small-size); line-height: var(--type-body-small-line); }
.app-menu__item > svg { flex: 0 0 auto; }
.app-menu__text { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.app-menu__text small { display: block; color: var(--text-2); font-size: var(--type-body-small-size); line-height: var(--type-body-small-line); transition: var(--theme-transition); }
.app-menu__item kbd { font-size: var(--type-body-caption-size); color: var(--text-3); white-space: nowrap; transition: var(--theme-transition); }
.app-menu__separator { border-top: 1px solid var(--border); margin: var(--space-6); transition: var(--theme-transition); }
.app-menu__group, .app-menu__empty { display: block; color: var(--text-2); padding: var(--space-8) var(--space-12); font-size: var(--type-body-small-size); line-height: var(--type-body-small-line); transition: var(--theme-transition); }
</style>
