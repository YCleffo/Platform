<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAdminResourceConfigs } from './adminConfigs';
import AdminResourceView from './AdminResourceView.vue';
import WorkflowPanel from './workbench/WorkflowPanel.vue';
import PrivacyOperatorRequests from './privacy/PrivacyOperatorRequests.vue';

const { t } = useI18n();
const configs = useAdminResourceConfigs();
const holdsConfig = computed(() => configs.value.legalHolds!);
const tab = ref<'requests' | 'holds' | 'advanced'>('requests');
const tabs = computed(() => [
  { key: 'requests' as const, label: t('admin.privacyRequests') },
  { key: 'holds' as const, label: holdsConfig.value.title },
  { key: 'advanced' as const, label: t('admin.advancedPrivacy') },
]);
</script>

<template>
  <section class="admin-tabs">
    <nav
      class="admin-tabs__nav"
      :aria-label="$t('admin.privacy')"
    >
      <button
        v-for="item in tabs"
        :key="item.key"
        type="button"
        :class="{ 'admin-tabs__tab--active': tab === item.key }"
        @click="tab = item.key"
      >
        {{ item.label }}
      </button>
    </nav>
    <PrivacyOperatorRequests v-if="tab === 'requests'" />
    <AdminResourceView
      v-else-if="tab === 'holds'"
      :config="holdsConfig"
    />
    <WorkflowPanel v-else />
  </section>
</template>

<style scoped>
.admin-tabs {
  display: grid;
  gap: var(--space-16);
}
.admin-tabs__nav {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-4);
  padding: var(--space-4);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--surface);
}
.admin-tabs__nav button {
  min-height: 36px;
  padding: 0 var(--space-12);
  border-radius: var(--radius-control-s);
  color: var(--text-2);
  font-size: var(--type-body-small-emphasis-size);
  letter-spacing: var(--type-body-small-emphasis-tracking);
  line-height: var(--type-body-small-emphasis-line);
  font-weight: var(--type-body-small-emphasis-weight);
}
.admin-tabs__nav button.admin-tabs__tab--active {
  background: var(--accent-soft);
  color: var(--accent-text);
}
</style>
