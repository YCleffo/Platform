<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ChevronRight } from '@/shared/ui/icons';
import AppMenu from '@/shared/ui/menus/AppMenu.vue';
import type { MenuItem } from '@/shared/ui/menus/types';
import type { Breadcrumb } from '@/shared/navigation/breadcrumbs';
const props = defineProps<{ items: Breadcrumb[] }>();
const { t } = useI18n();
const router = useRouter();
const open = ref(false);
const anchor = ref<HTMLButtonElement | null>(null);
const navigation = ref<HTMLElement | null>(null);
const compact = ref(typeof window !== 'undefined' && window.innerWidth < 768);
let observer: ResizeObserver | undefined;
onMounted(() => {
  if (typeof ResizeObserver !== 'undefined' && navigation.value) {
    observer = new ResizeObserver(([entry]) => {
      if (entry) compact.value = entry.contentRect.width < 180;
    });
    observer.observe(navigation.value);
  }
});
onBeforeUnmount(() => observer?.disconnect());
const hidden = computed(() =>
  props.items.length > 1 ? props.items.slice(compact.value ? 0 : 1, -1) : [],
);
const options = computed<MenuItem[]>(() =>
  hidden.value.map((item, index) => ({ id: String(index), label: item.label, disabled: !item.to })),
);
function choose(item: MenuItem): void {
  const to = hidden.value[Number(item.id)]?.to;
  if (to) void router.push(to);
}
</script>
<template>
  <nav
    ref="navigation"
    v-if="items.length"
    class="app-breadcrumbs"
    :aria-label="t('navigation.path')"
  >
    <ol>
      <li
        v-if="items.length > 1 && !compact"
        class="app-breadcrumbs__root"
      >
        <RouterLink
          v-if="items[0]?.to"
          :to="items[0].to"
          :title="items[0].label"
        >
          {{ items[0].label }}
        </RouterLink>
        <span v-else>{{ items[0]?.label }}</span>
      </li>
      <li
        v-if="hidden.length"
        class="app-breadcrumbs__overflow"
      >
        <ChevronRight
          v-if="!compact"
          :size="12"
          aria-hidden="true"
        />
        <button
          ref="anchor"
          type="button"
          :aria-label="t('navigation.hidden')"
          aria-haspopup="menu"
          :aria-expanded="open"
          @click="open = !open"
          @keydown.down.prevent="open = true"
        >
          …
        </button>
        <AppMenu
          v-model="open"
          :items="options"
          :anchor="anchor"
          :label="t('navigation.hidden')"
          size="s"
          @select="choose"
        />
      </li>
      <li
        v-if="items.length > 1"
        class="app-breadcrumbs__separator"
      >
        <ChevronRight
          :size="12"
          aria-hidden="true"
        />
      </li>
      <li
        class="app-breadcrumbs__current"
        aria-current="page"
        :title="items.at(-1)?.label"
      >
        {{ items.at(-1)?.label }}
      </li>
    </ol>
  </nav>
</template>
<style scoped>
.app-breadcrumbs {
  width: 100%;
  transition: var(--theme-transition);
  min-width: 0;
  max-width: 100%;
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
}
.app-breadcrumbs ol {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  min-width: 0;
  margin: 0;
  padding: 0;
  list-style: none;
}
.app-breadcrumbs li {
  display: flex;
  align-items: center;
  min-width: 0;
}
.app-breadcrumbs__root {
  flex: 0 1 auto;
  max-width: 6rem;
}
.app-breadcrumbs__root a,
.app-breadcrumbs__root span {
  color: inherit;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.app-breadcrumbs__overflow {
  gap: var(--space-4);
  flex: none;
}
.app-breadcrumbs__overflow button {
  transition: var(--theme-transition);
  display: grid;
  place-items: center;
  width: var(--control-h-s);
  height: var(--control-h-s);
  border: 0;
  border-radius: var(--radius-control-s);
  color: var(--text-1);
  background: transparent;
  cursor: pointer;
}
.app-breadcrumbs__overflow button:hover {
  background: var(--surface-hover);
}
.app-breadcrumbs__overflow button:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 0;
}
.app-breadcrumbs .app-breadcrumbs__separator {
  flex: none;
}
.app-breadcrumbs .app-breadcrumbs__current {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--text-1);
}
</style>
