<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import ConfigSettingsPanel from './configuration/ConfigSettingsPanel.vue';
import ConfigFlagsPanel from './configuration/ConfigFlagsPanel.vue';
import ConfigPublicPanel from './configuration/ConfigPublicPanel.vue';
import BrandingSettingsPanel from './configuration/BrandingSettingsPanel.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import './configuration/configuration.css';
const auth = useAdminAuthStore();
const tab = ref('settings');
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
watch(
  tabs,
  (value) => {
    if (!value.some((v) => v.key === tab.value)) tab.value = value[0]?.key ?? '';
  },
  { immediate: true },
);
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
        @click="tab = item.key"
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
