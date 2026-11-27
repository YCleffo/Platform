<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { Search } from '@/shared/ui/icons';
import {
  workspaceApi,
  type WorkspaceSearchCategory,
  type WorkspaceSearchItem,
  type WorkspaceSearchScope,
} from '@/shared/api/workspaceApi';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppEmptyState from '@/shared/ui/feedback/AppEmptyState.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import { useOperationsCenter } from './useOperationsCenter';

const CATEGORIES: readonly WorkspaceSearchCategory[] = [
  'all',
  'users',
  'orgs',
  'students',
  'teachers',
  'groups',
  'lessons',
  'homework',
  'tests',
  'documents',
  'files',
  'chats',
  'messages',
  'devices',
  'sections',
];

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const operations = useOperationsCenter();

const query = ref(typeof route.query.q === 'string' ? route.query.q : '');
const category = ref<string>(
  typeof route.query.category === 'string' ? route.query.category : 'all',
);
const scope = ref<WorkspaceSearchScope>(route.query.scope === 'all' ? 'all' : 'current_org');

const loading = ref(false);
const error = ref('');
const total = ref(0);
const items = ref<WorkspaceSearchItem[]>([]);
const facets = ref<Record<string, number>>({});
const activeResultIndex = ref(0);
const hasSearched = ref(false);

let debounceTimer: ReturnType<typeof setTimeout> | null = null;
let controller: AbortController | null = null;

interface HighlightSegment {
  text: string;
  match: boolean;
}

function splitHighlighted(rawText: string, searchQuery: string): HighlightSegment[] {
  const clean = searchQuery.trim();
  if (!clean) return [{ text: rawText, match: false }];
  const terms = clean
    .split(/\s+/)
    .filter((part) => part.length > 0)
    .map((part) => part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
  if (terms.length === 0) return [{ text: rawText, match: false }];

  const regex = new RegExp(`(${terms.join('|')})`, 'gi');
  const parts = rawText.split(regex);
  const matcher = new RegExp(`^(?:${terms.join('|')})$`, 'i');
  return parts
    .filter((p) => p.length > 0)
    .map((part) => ({
      text: part,
      match: matcher.test(part),
    }));
}

const visibleCategories = computed(() =>
  CATEGORIES.map((cat) => ({
    key: cat,
    label: t(`workspace.cat_${cat}`),
    count: cat === 'all' ? total.value : facets.value[cat],
  })),
);

interface SearchResultGroup {
  category: string;
  label: string;
  entries: Array<{ item: WorkspaceSearchItem; flatIndex: number }>;
}

const groupedResults = computed<SearchResultGroup[]>(() => {
  const groups = new Map<string, Array<{ item: WorkspaceSearchItem; flatIndex: number }>>();
  items.value.forEach((item, flatIndex) => {
    const key = item.category || 'general';
    const list = groups.get(key) ?? [];
    list.push({ item, flatIndex });
    groups.set(key, list);
  });
  return Array.from(groups.entries()).map(([cat, entries]) => {
    const key = `workspace.cat_${cat}`;
    const translated = t(key);
    return {
      category: cat,
      label: translated === key ? cat : translated,
      entries,
    };
  });
});

async function runSearch(): Promise<void> {
  controller?.abort();
  controller = new AbortController();
  const signal = controller.signal;

  loading.value = true;
  error.value = '';
  hasSearched.value = true;
  try {
    await operations.ensureOrganization();
    const res = await workspaceApi.search(
      {
        q: query.value.trim(),
        category: category.value,
        scope: scope.value,
        limit: 40,
        organizationId: operations.organizationId,
      },
      signal,
    );
    if (signal.aborted) return;
    items.value = res.items ?? [];
    total.value = res.total ?? items.value.length;
    facets.value = res.facets ?? {};
    activeResultIndex.value = 0;
  } catch (caught) {
    if (signal.aborted || (caught instanceof DOMException && caught.name === 'AbortError')) return;
    error.value = resolveErrorMessage(caught);
  } finally {
    if (!signal.aborted) {
      loading.value = false;
    }
  }
}

function syncUrlAndSearch(immediate = false): void {
  void router.replace({
    query: {
      ...(query.value.trim() ? { q: query.value.trim() } : {}),
      ...(category.value !== 'all' ? { category: category.value } : {}),
      ...(scope.value !== 'current_org' ? { scope: scope.value } : {}),
    },
  });

  if (debounceTimer) {
    clearTimeout(debounceTimer);
    debounceTimer = null;
  }

  if (immediate) {
    void runSearch();
  } else {
    debounceTimer = setTimeout(() => {
      void runSearch();
    }, 200);
  }
}

function selectCategory(cat: string): void {
  category.value = cat;
  syncUrlAndSearch(true);
}

function setScope(next: WorkspaceSearchScope): void {
  scope.value = next;
  syncUrlAndSearch(true);
}

async function openResult(item: WorkspaceSearchItem): Promise<void> {
  if (!item.route) return;
  await router.push(item.route);
}

function onSearchKeydown(event: KeyboardEvent): void {
  const len = items.value.length;
  if (event.key === 'ArrowDown' && len > 0) {
    event.preventDefault();
    activeResultIndex.value = (activeResultIndex.value + 1) % len;
  } else if (event.key === 'ArrowUp' && len > 0) {
    event.preventDefault();
    activeResultIndex.value = (activeResultIndex.value - 1 + len) % len;
  } else if (event.key === 'Enter' && len > 0) {
    const selected = items.value[activeResultIndex.value];
    if (selected) {
      event.preventDefault();
      void openResult(selected);
    }
  }
}

watch(
  () => route.query.q,
  (nextQ) => {
    const normalized = typeof nextQ === 'string' ? nextQ : '';
    if (normalized !== query.value) {
      query.value = normalized;
      void runSearch();
    }
  },
);

watch(
  () => operations.organizationId,
  () => {
    if (hasSearched.value && (query.value.trim() || category.value !== 'all')) {
      void runSearch();
    }
  },
);

onMounted(() => {
  void operations.ensureOrganization();
  if (query.value.trim() || category.value !== 'all') {
    void runSearch();
  }
});

onBeforeUnmount(() => {
  controller?.abort();
  if (debounceTimer) clearTimeout(debounceTimer);
});
</script>

<template>
  <section
    class="ws-search"
    data-testid="global-search-page"
  >
    <header class="ws-search__header">
      <div>
        <h1 class="ws-search__title">{{ $t('workspace.searchTitle') }}</h1>
        <p class="ws-search__subtitle">{{ $t('workspace.searchSubtitle') }}</p>
      </div>

      <div
        class="ws-search__scope"
        role="group"
        :aria-label="$t('workspace.searchScopeLabel')"
        data-testid="search-scope-toggle"
      >
        <button
          type="button"
          class="ws-search__scope-btn"
          :class="{ 'ws-search__scope-btn--active': scope === 'current_org' }"
          :aria-pressed="scope === 'current_org'"
          @click="setScope('current_org')"
        >
          {{ $t('workspace.scopeCurrentOrg') }}
        </button>
        <button
          type="button"
          class="ws-search__scope-btn"
          :class="{ 'ws-search__scope-btn--active': scope === 'all' }"
          :aria-pressed="scope === 'all'"
          @click="setScope('all')"
        >
          {{ $t('workspace.scopeAll') }}
        </button>
      </div>
    </header>

    <form
      class="ws-search__bar"
      role="search"
      @submit.prevent="syncUrlAndSearch(true)"
    >
      <div class="ws-search__input-wrap">
        <Search
          :size="18"
          class="ws-search__icon"
          aria-hidden="true"
        />
        <input
          v-model="query"
          type="search"
          class="ws-search__input"
          :placeholder="$t('workspace.searchPlaceholder')"
          :aria-label="$t('workspace.searchPlaceholder')"
          data-testid="global-search-input"
          @input="syncUrlAndSearch(false)"
          @keydown="onSearchKeydown"
        />
      </div>
      <AppButton
        type="submit"
        variant="primary"
      >
        {{ $t('actions.search') }}
      </AppButton>
    </form>

    <div
      class="ws-search__tabs"
      role="tablist"
      :aria-label="$t('workspace.searchTitle')"
      data-testid="search-category-tabs"
    >
      <button
        v-for="cat in visibleCategories"
        :key="cat.key"
        type="button"
        role="tab"
        class="ws-search__tab"
        :class="{ 'ws-search__tab--active': category === cat.key }"
        :aria-selected="category === cat.key"
        @click="selectCategory(cat.key)"
      >
        <span>{{ cat.label }}</span>
        <span
          v-if="typeof cat.count === 'number' && hasSearched"
          class="ws-search__tab-count"
        >
          {{ cat.count }}
        </span>
      </button>
    </div>

    <AppSkeleton
      v-if="loading"
      variant="list"
      :lines="5"
    />

    <AppErrorState
      v-else-if="error"
      :message="error"
    >
      <AppButton
        variant="secondary"
        @click="runSearch"
      >
        {{ $t('common.retry') }}
      </AppButton>
    </AppErrorState>

    <AppEmptyState
      v-else-if="!hasSearched"
      :title="$t('workspace.searchPrompt')"
    />

    <AppEmptyState
      v-else-if="items.length === 0"
      :title="$t('workspace.searchNoResults')"
    />

    <div
      v-else
      class="ws-search__results"
    >
      <p class="ws-search__count">{{ $t('workspace.searchResultsCount', { count: total }) }}</p>
      <div
        v-for="group in groupedResults"
        :key="group.category"
        class="ws-search__group"
        data-testid="search-result-group"
      >
        <header
          v-if="groupedResults.length > 1"
          class="ws-search__group-header"
        >
          <span class="ws-search__group-title">{{ group.label }}</span>
          <AppBadge variant="neutral">{{ group.entries.length }}</AppBadge>
        </header>
        <ul
          class="ws-search__list"
          role="listbox"
        >
          <li
            v-for="{ item, flatIndex: idx } in group.entries"
            :key="item.id"
          >
          <button
            type="button"
            role="option"
            class="ws-search__item"
            :class="{ 'ws-search__item--active': activeResultIndex === idx }"
            :aria-selected="activeResultIndex === idx"
            data-testid="search-result-item"
            @mouseenter="activeResultIndex = idx"
            @click="openResult(item)"
          >
            <div class="ws-search__item-head">
              <strong class="ws-search__item-title">
                <template
                  v-for="(seg, sIdx) in splitHighlighted(item.title, query)"
                  :key="sIdx"
                >
                  <mark
                    v-if="seg.match"
                    class="ws-search__mark"
                  >
                    {{ seg.text }}
                  </mark>
                  <template v-else>{{ seg.text }}</template>
                </template>
              </strong>
              <div class="ws-search__badges">
                <AppBadge variant="primary">{{ item.category }}</AppBadge>
                <AppBadge
                  v-if="item.module"
                  variant="neutral"
                >
                  {{ item.module }}
                </AppBadge>
              </div>
            </div>
            <p
              v-if="item.subtitle"
              class="ws-search__item-sub"
            >
              {{ item.subtitle }}
            </p>
            <p
              v-if="item.snippet"
              class="ws-search__item-snippet"
            >
              <template
                v-for="(seg, sIdx) in splitHighlighted(item.snippet, query)"
                :key="sIdx"
              >
                <mark
                  v-if="seg.match"
                  class="ws-search__mark"
                >
                  {{ seg.text }}
                </mark>
                <template v-else>{{ seg.text }}</template>
              </template>
            </p>
          </button>
        </li>
      </ul>
      </div>
    </div>
  </section>
</template>

<style scoped>
.ws-search {
  display: flex;
  flex-direction: column;
  gap: var(--space-16);
  max-width: 1080px;
  margin: 0 auto;
  width: 100%;
}

.ws-search__header {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-start;
  gap: var(--space-12);
}

.ws-search__title {
  font-size: var(--type-h1-size);
  line-height: var(--type-h1-line);
  font-weight: var(--type-h1-weight);
  color: var(--text-1);
}

.ws-search__subtitle {
  margin-top: var(--space-4);
  color: var(--text-2);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
}

.ws-search__scope {
  display: inline-flex;
  padding: var(--space-2);
  border-radius: var(--radius-control-m);
  border: 1px solid var(--border);
  background: var(--surface-2);
  gap: var(--space-2);
}

.ws-search__scope-btn {
  padding: var(--space-6) var(--space-12);
  border-radius: var(--radius-control-s);
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-emphasis-weight);
  transition: var(--theme-transition);
}

.ws-search__scope-btn--active {
  background: var(--surface);
  color: var(--text-1);
  border: 1px solid var(--border);
}

.ws-search__bar {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
}

.ws-search__input-wrap {
  flex: 1;
  min-width: 220px;
  display: flex;
  align-items: center;
  gap: var(--space-8);
  padding: 0 var(--space-12);
  min-height: var(--control-h-m);
  border-radius: var(--radius-control-m);
  border: 1px solid var(--border);
  background: var(--surface);
  transition: var(--theme-transition);
}

.ws-search__input-wrap:focus-within {
  border-color: var(--focus-border);
}

.ws-search__icon {
  color: var(--text-2);
  flex: 0 0 auto;
}

.ws-search__input {
  flex: 1;
  min-width: 0;
  border: none;
  background: transparent;
  color: var(--text-1);
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
}

.ws-search__tabs {
  display: flex;
  flex-wrap: nowrap;
  overflow-x: auto;
  gap: var(--space-6);
  padding-bottom: var(--space-4);
}

.ws-search__tab {
  display: inline-flex;
  align-items: center;
  gap: var(--space-6);
  flex: 0 0 auto;
  padding: var(--space-6) var(--space-12);
  border-radius: var(--radius-badge-pill);
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-emphasis-weight);
  transition: var(--theme-transition);
}

.ws-search__tab--active {
  background: var(--selected-surface);
  border-color: var(--selected-accent);
  color: var(--accent-text);
}

.ws-search__tab-count {
  padding: 0 var(--space-6);
  border-radius: var(--radius-badge-pill);
  background: var(--surface-2);
  color: var(--text-1);
  font-size: var(--type-body-caption-size);
  line-height: var(--type-body-caption-line);
  font-weight: var(--type-body-caption-weight);
}

.ws-search__count {
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.ws-search__group {
  display: flex;
  flex-direction: column;
  gap: var(--space-8);
}

.ws-search__group-header {
  display: flex;
  align-items: center;
  gap: var(--space-8);
  padding-top: var(--space-8);
}

.ws-search__group-title {
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-emphasis-weight);
  color: var(--text-2);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.ws-search__list {
  display: flex;
  flex-direction: column;
  gap: var(--space-8);
}

.ws-search__item {
  width: 100%;
  text-align: left;
  display: flex;
  flex-direction: column;
  gap: var(--space-6);
  padding: var(--space-12) var(--space-16);
  border-radius: var(--radius-card-m);
  border: 1px solid var(--border);
  background: var(--card);
  transition: var(--theme-transition);
}

.ws-search__item:hover,
.ws-search__item--active {
  border-color: var(--accent);
  background: var(--surface-hover);
}

.ws-search__item-head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-8);
}

.ws-search__item-title {
  color: var(--text-1);
  font-size: var(--type-body-large-size);
  line-height: var(--type-body-large-line);
  font-weight: var(--type-body-large-emphasis-weight);
}

.ws-search__badges {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-6);
}

.ws-search__item-sub,
.ws-search__item-snippet {
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}

.ws-search__mark {
  background: var(--accent-soft-2);
  color: var(--text-1);
  border-radius: var(--radius-control-s);
  padding: 0 2px;
}
</style>
