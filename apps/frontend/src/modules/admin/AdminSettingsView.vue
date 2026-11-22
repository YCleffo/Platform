<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import ConfigSettingsPanel from './configuration/ConfigSettingsPanel.vue';
import ConfigFlagsPanel from './configuration/ConfigFlagsPanel.vue';
import ConfigPublicPanel from './configuration/ConfigPublicPanel.vue';
import BrandingSettingsPanel from './configuration/BrandingSettingsPanel.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import './configuration/configuration.css';
const auth = useAdminAuthStore();
const route = useRoute();
const router = useRouter();
const tabs = computed(() =>
  [
    { key: 'branding', label: 'branding.title', allowed: auth.can('core.settings.read') },
    {
      key: 'settings',
      label: 'configuration.systemSettings',
      allowed: auth.can('core.settings.read'),
    },
    { key: 'flags', label: 'admin.featureFlags', allowed: auth.can('core.feature_flags.read') },
    { key: 'public', label: 'configuration.publicConfig', allowed: auth.can('core.settings.read') },
  ].filter((v) => v.allowed),
);
const tab = computed(() => {
  const requested = typeof route.query.tab === 'string' ? route.query.tab : 'settings';
  return tabs.value.find(({ key }) => key === requested)?.key ?? tabs.value[0]?.key;
});
function chooseTab(key: string): void {
  if (key !== tab.value) void router.push({ query: { ...route.query, tab: key } });
}
</script>
<template>
  <section class="config-page">
    <nav
      class="config-tabs"
      :aria-label="$t('admin.settings')"
    >
      <AppButton
        v-for="item in tabs"
        :key="item.key"
        :variant="tab === item.key ? 'primary' : 'secondary'"
        :aria-current="tab === item.key ? 'page' : undefined"
        @click="chooseTab(item.key)"
      >
        {{ $t(item.label) }}
      </AppButton>
    </nav>
    <BrandingSettingsPanel v-if="tab === 'branding'" />
    <ConfigSettingsPanel v-else-if="tab === 'settings'" />
    <ConfigFlagsPanel v-else-if="tab === 'flags'" />
    <ConfigPublicPanel v-else-if="tab === 'public'" />
    <p
      v-else
      class="config-note"
    >
      {{ $t('configuration.noRead') }}
    </p>
  </section>
</template>
