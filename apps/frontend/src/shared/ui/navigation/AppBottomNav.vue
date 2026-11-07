<script setup lang="ts">
import type { Component } from 'vue';
import { computed, markRaw, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';

export interface BottomNavItem {
  label: string;
  icon: Component;
  routeName: string;
}

const props = defineProps<{ items: BottomNavItem[]; activeRouteName?: string }>();

const route = useRoute();
const active = computed(() => props.activeRouteName ?? route.name);

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
  if (typeof window !== 'undefined') {
    window.removeEventListener('resize', updatePill);
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
  return {
    transform: `translateX(${pillLeft.value}px)`,
    width: `${pillWidth.value}px`,
    opacity: 1,
  };
});
</script>

<template>
  <nav
    class="app-bottom-nav"
    :aria-label="$t('nav.primary')"
  >
    <div class="app-bottom-nav__inner">
      <ul
        ref="listRef"
        class="app-bottom-nav__list"
      >
        <!-- The animated sliding pill / tablet indicator -->
        <li
          class="app-bottom-nav__pill"
          :style="pillStyle"
          aria-hidden="true"
        />

        <li
          v-for="item in items"
          :key="item.routeName"
          class="app-bottom-nav__item"
        >
          <RouterLink
            :ref="(el) => setLinkRef(item.routeName, el)"
            class="app-bottom-nav__link"
            :class="{ 'app-bottom-nav__link--active': active === item.routeName }"
            :to="{ name: item.routeName }"
            :aria-current="active === item.routeName ? 'page' : undefined"
            active-class=""
            exact-active-class=""
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
  z-index: 900;
  width: min(calc(100vw - var(--space-32)), 440px);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius-badge-pill);
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12), var(--shadow-popover);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  padding: var(--space-4) var(--space-6);
  pointer-events: auto;
  transition: var(--theme-transition);
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
    transform var(--motion-control-duration) var(--motion-spring-ease),
    width var(--motion-control-duration) var(--motion-spring-ease),
    opacity var(--motion-fast-duration) var(--motion-ease);
  z-index: 1;
}

.app-bottom-nav__item {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 1;
  min-width: 0;
  height: 100%;
}

.app-bottom-nav__link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0 var(--space-12);
  height: 42px;
  border-radius: var(--radius-badge-pill);
  color: var(--text-3);
  text-decoration: none;
  position: relative;
  z-index: 2;
  user-select: none;
  -webkit-user-select: none;
  transition:
    var(--theme-transition),
    transform var(--motion-fast-duration) var(--motion-ease);
}

.app-bottom-nav__link:active {
  transform: scale(0.95);
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
  transition:
    var(--theme-transition),
    transform var(--motion-control-duration) var(--motion-spring-ease);
}

/* Inactive tabs: icons sit slightly lower */
.app-bottom-nav__link:not(.app-bottom-nav__link--active) .app-bottom-nav__icon {
  transform: translateY(3px);
}

.app-bottom-nav__link--active .app-bottom-nav__icon {
  transform: translateY(0) scale(1.06);
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
  transform: translateX(-8px) scale(0.9);
  transition:
    var(--theme-transition),
    max-width var(--motion-control-duration) var(--motion-ease),
    opacity var(--motion-fast-duration) var(--motion-ease),
    transform var(--motion-control-duration) var(--motion-spring-ease),
    margin var(--motion-control-duration) var(--motion-ease);
}

.app-bottom-nav__link--active .app-bottom-nav__label {
  max-width: 140px;
  opacity: 1;
  margin-left: var(--space-8);
  transform: translateX(0) scale(1);
}

@media (min-width: 768px) {
  .app-bottom-nav {
    display: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .app-bottom-nav__pill,
  .app-bottom-nav__icon,
  .app-bottom-nav__label,
  .app-bottom-nav__link {
    transition: none;
  }
}
</style>
