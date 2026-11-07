<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAdminResourceConfigs } from './adminConfigs';
import AdminResourceView from './AdminResourceView.vue';

type RegistryKind = 'representation' | 'erasure' | 'incident' | 'distribution';

const { t } = useI18n();
const configs = useAdminResourceConfigs();
const kinds: Array<{ key: RegistryKind; config: string }> = [
  { key: 'representation', config: 'representations' },
  { key: 'erasure', config: 'erasureCases' },
  { key: 'incident', config: 'privacyIncidents' },
  { key: 'distribution', config: 'distributionConsents' },
];
const tab = ref<RegistryKind>('representation');
const tabs = computed(() =>
  kinds.map((item) => ({
    ...item,
    label: t('workbench.groups.' + item.key),
    hint: t('workbench.hints.' + item.key),
    resource: configs.value[item.config],
  })),
);
const active = computed(() => tabs.value.find((item) => item.key === tab.value) ?? tabs.value[0]);
</script>

<template>
  <section class="privacy-registry">
    <header class="privacy-registry__head">
      <h2>{{ $t('admin.privacyRegistry') }}</h2>
      <p>{{ $t('admin.privacyRegistrySubtitle') }}</p>
    </header>
    <nav
      class="privacy-registry__tabs"
      :aria-label="$t('admin.privacyRegistry')"
    >
      <button
        v-for="item in tabs"
        :key="item.key"
        type="button"
        :class="{ 'privacy-registry__tab--active': tab === item.key }"
        @click="tab = item.key"
      >
        {{ item.label }}
      </button>
    </nav>
    <p
      v-if="active"
      class="privacy-registry__hint"
    >
      {{ active.hint }}
    </p>
    <AdminResourceView
      v-if="active?.resource"
      :key="active.key"
      :config="active.resource"
    />
  </section>
</template>

<style scoped>
.privacy-registry {
  display: grid;
  gap: var(--space-16);
  min-width: 0;
}
.privacy-registry__head h2 {
  font-size: var(--type-h2-size);
  letter-spacing: var(--type-h2-tracking);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  color: var(--text-1);
  transition: var(--theme-transition);
}
.privacy-registry__head p {
  margin-top: var(--space-4);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
  transition: var(--theme-transition);
}
.privacy-registry__tabs {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-4);
  padding: var(--space-4);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--surface);
  transition: var(--theme-transition);
}
.privacy-registry__tabs button {
  min-height: 36px;
  padding: 0 var(--space-12);
  border-radius: var(--radius-control-s);
  color: var(--text-2);
  font-size: var(--type-body-small-emphasis-size);
  letter-spacing: var(--type-body-small-emphasis-tracking);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
  transition: var(--theme-transition);
}
.privacy-registry__tabs button.privacy-registry__tab--active {
  background: var(--accent-soft);
  color: var(--accent-text);
}
.privacy-registry__hint {
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-2);
}
</style>
