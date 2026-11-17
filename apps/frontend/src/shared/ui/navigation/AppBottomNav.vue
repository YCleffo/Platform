<script setup lang="ts">
import type { Component } from 'vue';
import { computed, markRaw, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

export interface BottomNavItem {
  label: string;
  icon: Component;
  routeName: string;
}

const props = defineProps<{ items: BottomNavItem[]; activeRouteName?: string }>();

const route = useRoute();
const router = useRouter();
const active = computed(() => props.activeRouteName ?? route.name);

const sanitizedItems = computed(() =>
  props.items.map((item) => ({
    label: item.label,
    routeName: item.routeName,
    icon: markRaw(item.icon),
  })),
);

const listRef = ref<HTMLElement | null>(null);
const linkRefs = new Map<string, HTMLElement>();

function setLinkRef(routeName: string, el: unknown): void {
  if (el && typeof el === 'object') {
    const domEl =
      '$el' in el && el.$el instanceof HTMLElement ? el.$el : el instanceof HTMLElement ? el : null;
    if (domEl) {
      linkRefs.set(routeName, domEl);
      return;
    }
  }
  linkRefs.delete(routeName);
}

const currentIndex = computed(() =>
  sanitizedItems.value.findIndex((item) => item.routeName === active.value),
);

const dragOffset = ref(0);
const isDraggingPill = ref(false);
const pillLeft = ref(0);
const pillWidth = ref(0);
const pillReady = ref(false);

function updatePill(): void {
  const current = active.value as string;
  const target = linkRefs.get(current);
  const container = listRef.value;
  if (!target || !container) {
    pillReady.value = false;
    return;
  }
  const targetRect = target.getBoundingClientRect();
  const containerRect = container.getBoundingClientRect();
  if (targetRect.width > 0) {
    pillLeft.value = targetRect.left - containerRect.left;
    pillWidth.value = targetRect.width;
    pillReady.value = true;
  }
}

function findTargetTab(offset: number): BottomNavItem | null {
  const container = listRef.value;
  if (!container || sanitizedItems.value.length === 0) return null;
  const containerRect = container.getBoundingClientRect();
  const currentPillCenter = pillLeft.value + offset + pillWidth.value / 2;

  let closestItem: BottomNavItem | null = null;
  let minDistance = Infinity;

  sanitizedItems.value.forEach((item) => {
    const el = linkRefs.get(item.routeName);
    if (!el) return;
    const rect = el.getBoundingClientRect();
    const itemCenter = rect.left - containerRect.left + rect.width / 2;
    const dist = Math.abs(currentPillCenter - itemCenter);
    if (dist < minDistance) {
      minDistance = dist;
      closestItem = item;
    }
  });

  const curIdx = currentIndex.value;
  if (closestItem && (closestItem as BottomNavItem).routeName === active.value) {
    if (offset > 35 && curIdx < sanitizedItems.value.length - 1) {
      return sanitizedItems.value[curIdx + 1] ?? closestItem;
    }
    if (offset < -35 && curIdx > 0) {
      return sanitizedItems.value[curIdx - 1] ?? closestItem;
    }
  }

  return closestItem;
}

let didDrag = false;
let suppressClick = false;
let suppressTimer: ReturnType<typeof setTimeout> | undefined;

function suppressNextClick(): void {
  suppressClick = true;
  if (suppressTimer) clearTimeout(suppressTimer);
  suppressTimer = setTimeout(() => {
    suppressClick = false;
  }, 120);
}

function finishDrag(offset: number): void {
  const container = listRef.value;
  const maxLeft = container
    ? Math.max(0, container.getBoundingClientRect().width - pillWidth.value)
    : 400;
  const releaseLeft = Math.max(0, Math.min(maxLeft, pillLeft.value + offset));

  pillLeft.value = releaseLeft;
  dragOffset.value = 0;
  isDraggingPill.value = false;

  const target = findTargetTab(offset);
  if (target && target.routeName !== active.value) {
    void router?.push({ name: target.routeName });
  } else {
    void nextTick(() => {
      updatePill();
    });
  }
}

let touchStartX = 0;
let touchStartY = 0;
let isTouching = false;
let touchMoved = false;

function onTouchStart(event: TouchEvent): void {
  const touch = event.touches[0];
  if (!touch) return;
  touchStartX = touch.clientX;
  touchStartY = touch.clientY;
  isTouching = true;
  touchMoved = false;
  dragOffset.value = 0;
}

function onTouchMove(event: TouchEvent): void {
  if (!isTouching) return;
  const touch = event.touches[0];
  if (!touch) return;
  const dx = touch.clientX - touchStartX;
  const dy = touch.clientY - touchStartY;

  if (!touchMoved) {
    if (Math.abs(dx) > 8 && Math.abs(dx) > Math.abs(dy)) {
      touchMoved = true;
      isDraggingPill.value = true;
      didDrag = true;
    }
  }

  if (touchMoved) {
    if (event.cancelable) {
      event.preventDefault();
    }
    dragOffset.value = dx;
  }
}

function onTouchEnd(): void {
  if (!isTouching) return;
  isTouching = false;

  if (touchMoved) {
    const finalOffset = dragOffset.value;
    suppressNextClick();
    finishDrag(finalOffset);
  } else {
    dragOffset.value = 0;
    isDraggingPill.value = false;
  }
}

let mouseStartX = 0;
let mouseStartY = 0;
let isMouseDown = false;
let mouseMoved = false;

function onMouseDown(event: MouseEvent): void {
  if (event.button !== 0) return;
  mouseStartX = event.clientX;
  mouseStartY = event.clientY;
  isMouseDown = true;
  mouseMoved = false;
  dragOffset.value = 0;

  if (typeof window !== 'undefined') {
    window.addEventListener('mousemove', onWindowMouseMove);
    window.addEventListener('mouseup', onWindowMouseUp);
  }
}

function onWindowMouseMove(event: MouseEvent): void {
  if (!isMouseDown) return;
  const dx = event.clientX - mouseStartX;
  const dy = event.clientY - mouseStartY;

  if (!mouseMoved) {
    if (Math.abs(dx) > 8 && Math.abs(dx) > Math.abs(dy)) {
      mouseMoved = true;
      isDraggingPill.value = true;
      didDrag = true;
    }
  }

  if (mouseMoved) {
    dragOffset.value = dx;
  }
}

function onWindowMouseUp(): void {
  if (typeof window !== 'undefined') {
    window.removeEventListener('mousemove', onWindowMouseMove);
    window.removeEventListener('mouseup', onWindowMouseUp);
  }

  if (!isMouseDown) return;
  isMouseDown = false;

  if (mouseMoved) {
    const finalOffset = dragOffset.value;
    suppressNextClick();
    finishDrag(finalOffset);
  } else {
    dragOffset.value = 0;
    isDraggingPill.value = false;
  }
}

function onLinkClick(event: MouseEvent): void {
  if (suppressClick || didDrag) {
    event.preventDefault();
    event.stopPropagation();
    suppressClick = false;
    didDrag = false;
  }
}

let resizeObserver: ResizeObserver | undefined;

onMounted(() => {
  void nextTick(() => {
    updatePill();
    if (typeof ResizeObserver !== 'undefined' && listRef.value) {
      resizeObserver = new ResizeObserver(() => {
        updatePill();
      });
      resizeObserver.observe(listRef.value);
      for (const el of linkRefs.values()) {
        resizeObserver.observe(el);
      }
    }
  });
  if (typeof window !== 'undefined') {
    window.addEventListener('resize', updatePill);
  }
});

onBeforeUnmount(() => {
  resizeObserver?.disconnect();
  if (suppressTimer) clearTimeout(suppressTimer);
  if (typeof window !== 'undefined') {
    window.removeEventListener('resize', updatePill);
    window.removeEventListener('mousemove', onWindowMouseMove);
    window.removeEventListener('mouseup', onWindowMouseUp);
  }
});

watch(
  active,
  () => {
    void nextTick(() => {
      updatePill();
      setTimeout(updatePill, 120);
      setTimeout(updatePill, 360);
    });
  },
  { flush: 'post' },
);

const pillStyle = computed(() => {
  if (!pillReady.value) {
    return { opacity: 0 };
  }
  let currentLeft = pillLeft.value;
  if (isDraggingPill.value) {
    const container = listRef.value;
    const maxLeft = container
      ? Math.max(0, container.getBoundingClientRect().width - pillWidth.value)
      : 400;
    currentLeft = Math.max(0, Math.min(maxLeft, pillLeft.value + dragOffset.value));
  }
  return {
    transform: `translateX(${currentLeft}px)`,
    width: `${pillWidth.value}px`,
    opacity: 1,
  };
});
</script>

<template>
  <nav
    class="app-bottom-nav"
    :class="{ 'app-bottom-nav--dragging': isDraggingPill }"
    :aria-label="$t('nav.primary')"
    @touchstart="onTouchStart"
    @touchmove="onTouchMove"
    @touchend="onTouchEnd"
    @touchcancel="onTouchEnd"
    @mousedown="onMouseDown"
  >
    <div class="app-bottom-nav__inner">
      <ul
        ref="listRef"
        class="app-bottom-nav__list"
      >
        <!-- The animated sliding pill / tablet indicator -->
        <li
          class="app-bottom-nav__pill"
          :class="{ 'app-bottom-nav__pill--dragging': isDraggingPill }"
          :style="pillStyle"
          aria-hidden="true"
        />

        <li
          v-for="item in sanitizedItems"
          :key="item.routeName"
          class="app-bottom-nav__item"
          :class="{ 'app-bottom-nav__item--active': active === item.routeName }"
        >
          <RouterLink
            :ref="(el) => setLinkRef(item.routeName, el)"
            class="app-bottom-nav__link"
            :class="{ 'app-bottom-nav__link--active': active === item.routeName }"
            :to="{ name: item.routeName }"
            :aria-current="active === item.routeName ? 'page' : undefined"
            :draggable="false"
            active-class=""
            exact-active-class=""
            @click="onLinkClick"
            @dragstart.prevent
          >
            <span
              class="app-bottom-nav__icon"
              aria-hidden="true"
            >
              <component
                :is="markRaw(item.icon)"
                :size="22"
              />
            </span>
            <span class="app-bottom-nav__label">{{ item.label }}</span>
          </RouterLink>
        </li>
      </ul>
    </div>
  </nav>
</template>

<style scoped>
.app-bottom-nav {
  position: fixed;
  left: 50vw;
  transform: translateX(-50%);
  bottom: max(var(--space-12), calc(env(safe-area-inset-bottom) + var(--space-8)));
  z-index: 800;
  width: min(calc(100vw - var(--space-32)), 440px);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-badge-pill);
  box-shadow:
    0 8px 32px rgba(0, 0, 0, 0.12),
    var(--shadow-popover);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  padding: var(--space-4) var(--space-6);
  pointer-events: auto;
  user-select: none;
  -webkit-user-select: none;
  -webkit-user-drag: none;
  touch-action: pan-y;
  cursor: grab;
  transition: var(--theme-transition);
}

.app-bottom-nav--dragging {
  cursor: grabbing;
}

.app-bottom-nav__inner {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 52px;
}

.app-bottom-nav__list {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-around;
  width: 100%;
  height: 100%;
  list-style: none;
  margin: 0;
  padding: 0;
}

.app-bottom-nav__pill {
  position: absolute;
  top: 50%;
  left: 0;
  height: 42px;
  margin-top: -21px;
  border-radius: var(--radius-badge-pill);
  background: var(--accent-soft);
  border: 1px solid var(--border);
  pointer-events: none;
  box-shadow: 0 2px 10px rgba(34, 96, 255, 0.1);
  transition:
    var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-ease),
    width var(--motion-control-duration) var(--motion-ease),
    opacity var(--motion-fast-duration) var(--motion-ease);
  z-index: 1;
}

.app-bottom-nav__pill--dragging {
  transition: none;
}

.app-bottom-nav__item {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 1 1 0;
  min-width: 0;
  height: 100%;
  cursor: pointer;
  transition:
    var(--theme-transition),
    flex-grow var(--motion-control-duration) var(--motion-ease);
}

.app-bottom-nav__item--active {
  flex-grow: 1.8;
}

.app-bottom-nav__link {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  padding: 0 var(--space-8);
  border-radius: var(--radius-badge-pill);
  color: var(--text-3);
  text-decoration: none;
  position: relative;
  z-index: 2;
  user-select: none;
  -webkit-user-select: none;
  -webkit-user-drag: none;
  cursor: pointer;
  transition:
    var(--theme-transition),
    color var(--motion-fast-duration) var(--motion-ease);
}

.app-bottom-nav__link:hover {
  color: var(--text-1);
}

.app-bottom-nav__link--active {
  color: var(--accent-text);
}

.app-bottom-nav__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  transition: var(--theme-transition);
}

.app-bottom-nav__label {
  display: inline-block;
  max-width: 0;
  opacity: 0;
  white-space: nowrap;
  overflow: hidden;
  font-size: var(--type-body-small-emphasis-size);
  font-weight: var(--type-body-small-emphasis-weight);
  line-height: var(--type-body-small-emphasis-line);
  letter-spacing: var(--type-body-small-emphasis-tracking);
  color: var(--accent-text);
  margin-left: 0;
  transition:
    var(--theme-transition),
    max-width var(--motion-control-duration) var(--motion-ease),
    opacity var(--motion-fast-duration) var(--motion-ease),
    margin var(--motion-control-duration) var(--motion-ease);
}

.app-bottom-nav__link--active .app-bottom-nav__label {
  max-width: 140px;
  opacity: 1;
  margin-left: var(--space-8);
}

@media (min-width: 768px) {
  .app-bottom-nav {
    display: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .app-bottom-nav__item,
  .app-bottom-nav__pill,
  .app-bottom-nav__icon,
  .app-bottom-nav__label,
  .app-bottom-nav__link {
    transition: none;
  }
}
</style>
