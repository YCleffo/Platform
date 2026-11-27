<script setup lang="ts">
import type { Component } from 'vue';
import { computed, markRaw, nextTick, onBeforeUnmount, onMounted, ref, toRaw, watch } from 'vue';
import { useRoute, useRouter, type RouteLocationRaw } from 'vue-router';
import { activeNavigationRoute } from '@/shared/navigation/activeRoute';

export interface BottomNavItem {
  label: string;
  icon: Component;
  routeName: string;
  to?: RouteLocationRaw;
  badge?: number;
}

const props = defineProps<{ items: BottomNavItem[]; activeRouteName?: string }>();

const route = useRoute();
const router = useRouter();
const active = computed(() => props.activeRouteName ?? activeNavigationRoute(route));

const sanitizedItems = computed(() =>
  props.items.map((item) => ({
    label: item.label,
    routeName: item.routeName,
    to: item.to ?? { name: item.routeName },
    badge: item.badge ?? 0,
    icon: markRaw(toRaw(item.icon)),
  })),
);

const listRef = ref<HTMLElement | null>(null);
const linkRefs = new Map<string, HTMLElement>();
let resizeObserver: ResizeObserver | undefined;

function setLinkRef(routeName: string, el: unknown): void {
  const previous = linkRefs.get(routeName);
  if (el && typeof el === 'object') {
    const domEl =
      '$el' in el && el.$el instanceof HTMLElement ? el.$el : el instanceof HTMLElement ? el : null;
    if (domEl) {
      if (domEl === previous) return;
      if (previous) resizeObserver?.unobserve(previous);
      linkRefs.set(routeName, domEl);
      resizeObserver?.observe(domEl);
      return;
    }
  }
  if (previous) resizeObserver?.unobserve(previous);
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
  const target = linkRefs.get(active.value);
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
  } else pillReady.value = false;
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

let suppressClickUntil = 0;

function resetDrag(): void {
  dragOffset.value = 0;
  isDraggingPill.value = false;
}

function finishDrag(offset: number): void {
  const container = listRef.value;
  const maxLeft = container
    ? Math.max(0, container.getBoundingClientRect().width - pillWidth.value)
    : 400;
  const releaseLeft = Math.max(0, Math.min(maxLeft, pillLeft.value + offset));

  const target = findTargetTab(releaseLeft - pillLeft.value);
  pillLeft.value = releaseLeft;
  resetDrag();
  suppressClickUntil = performance.now() + 300;

  if (target && target.routeName !== active.value) {
    void router.push(target.to ?? { name: target.routeName }).then(() => nextTick(updatePill));
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
let touchId: number | undefined;

function onTouchStart(event: TouchEvent): void {
  const touch = event.touches[0];
  if (!touch || event.touches.length !== 1) {
    onTouchCancel();
    return;
  }
  suppressClickUntil = 0;
  touchStartX = touch.clientX;
  touchStartY = touch.clientY;
  touchId = touch.identifier;
  isTouching = true;
  touchMoved = false;
  dragOffset.value = 0;
}

function onTouchMove(event: TouchEvent): void {
  if (!isTouching) return;
  const touch = event.touches[0];
  if (!touch || event.touches.length !== 1 || touch.identifier !== touchId) {
    onTouchCancel();
    return;
  }
  const dx = touch.clientX - touchStartX;
  const dy = touch.clientY - touchStartY;

  if (!touchMoved) {
    if (Math.abs(dy) > 8 && Math.abs(dy) >= Math.abs(dx)) {
      onTouchCancel();
      return;
    }
    if (Math.abs(dx) > 8) {
      touchMoved = true;
      isDraggingPill.value = true;
    }
  }

  if (touchMoved) {
    if (event.cancelable) {
      event.preventDefault();
    }
    dragOffset.value = dx;
  }
}

function onTouchCancel(): void {
  isTouching = false;
  touchId = undefined;
  touchMoved = false;
  resetDrag();
}

function onTouchEnd(event: TouchEvent): void {
  if (!isTouching || event.changedTouches[0]?.identifier !== touchId) return;
  isTouching = false;
  touchId = undefined;
  if (touchMoved) finishDrag(dragOffset.value);
  else resetDrag();
}

let mouseStartX = 0;
let mouseStartY = 0;
let isMouseDown = false;
let mouseMoved = false;

function onMouseDown(event: MouseEvent): void {
  if (event.button !== 0) return;
  suppressClickUntil = 0;
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

  if (mouseMoved) finishDrag(dragOffset.value);
  else resetDrag();
}

function onLinkClick(event: MouseEvent): void {
  if (event.detail > 0 && performance.now() < suppressClickUntil) {
    event.preventDefault();
    event.stopPropagation();
    suppressClickUntil = 0;
  }
}

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
  if (typeof window !== 'undefined') {
    window.removeEventListener('resize', updatePill);
    window.removeEventListener('mousemove', onWindowMouseMove);
    window.removeEventListener('mouseup', onWindowMouseUp);
  }
});

watch([active, sanitizedItems], updatePill, { flush: 'post' });

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
    @touchcancel="onTouchCancel"
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
            :to="item.to"
            :aria-current="active === item.routeName ? 'page' : undefined"
            :draggable="false"
            active-class=""
            exact-active-class=""
            @click.capture="onLinkClick"
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
              <span
                v-if="item.badge > 0"
                class="app-bottom-nav__badge"
              >
                {{ Math.min(item.badge, 99) }}
              </span>
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
  box-shadow: 0 2px 10px color-mix(in srgb, var(--accent) 14%, transparent);
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

.app-bottom-nav__link:focus-visible {
  outline: 2px solid var(--focus-border);
  outline-offset: -2px;
}

.app-bottom-nav__link--active {
  color: var(--accent-text);
}

.app-bottom-nav__icon {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  transition: var(--theme-transition);
}

.app-bottom-nav__badge {
  position: absolute;
  top: -5px;
  right: -7px;
  min-width: 15px;
  height: 15px;
  padding: 0 4px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-circle);
  background: var(--danger);
  color: var(--on-accent);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-small-emphasis-weight);
}

.app-bottom-nav__label {
  display: inline-block;
  max-width: 0;
  opacity: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
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
