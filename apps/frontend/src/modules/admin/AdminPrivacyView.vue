<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAdminResourceConfigs } from './adminConfigs';
import AdminResourceView from './AdminResourceView.vue';
import AdminOperationPanel from './AdminOperationPanel.vue';
import type { AdminOperation } from './types';

const { t } = useI18n();
const configs = useAdminResourceConfigs();
const requestsConfig = computed(() => configs.value.privacyRequests!);
const holdsConfig = computed(() => configs.value.legalHolds!);
const tab = ref<'requests' | 'holds' | 'advanced'>('requests');
const tabs = computed(() => [
  { key: 'requests' as const, label: requestsConfig.value.title },
  { key: 'holds' as const, label: holdsConfig.value.title },
  { key: 'advanced' as const, label: t('admin.advancedPrivacy') },
]);

const operations = computed<AdminOperation[]>(() => {
  const idInput = { name: 'id', label: t('admin.resourceId'), placeholder: 'uuid' };
  return [
    {
      label: t('admin.createErasureCase'),
      description: t('admin.createErasureCaseHint'),
      method: 'POST',
      path: () => '/core/privacy/erasure-cases',
      body: { subjectPersonId: '', requestId: '', scopeType: 'subject', reasonCode: 'erasure', evidenceReference: '' },
    },
    {
      label: t('admin.getErasureCase'),
      description: t('admin.getErasureCaseHint'),
      method: 'GET',
      path: input => `/core/privacy/erasure-cases/${encodeURIComponent(input.id ?? '')}`,
      inputs: [idInput],
    },
    {
      label: t('admin.executeErasureCase'),
      description: t('admin.executeErasureCaseHint'),
      method: 'POST',
      path: input => `/core/privacy/erasure-cases/${encodeURIComponent(input.id ?? '')}/execute`,
      inputs: [idInput],
      body: {},
      danger: true,
    },
    {
      label: t('admin.erasureEvidence'),
      description: t('admin.erasureEvidenceHint'),
      method: 'GET',
      path: input => `/core/privacy/erasure-cases/${encodeURIComponent(input.id ?? '')}/evidence`,
      inputs: [idInput],
    },
    {
      label: t('admin.attestErasure'),
      description: t('admin.attestErasureHint'),
      method: 'POST',
      path: input => `/core/privacy/erasure-cases/${encodeURIComponent(input.id ?? '')}/evidence/attest`,
      inputs: [idInput],
      body: { evidenceId: '', signedDocumentReference: '', signatureReference: '' },
    },
    {
      label: t('admin.detectIncident'),
      description: t('admin.detectIncidentHint'),
      method: 'POST',
      path: () => '/core/privacy/incidents',
      body: { incidentType: 'unauthorized_access', reasonCode: 'detected', categoryCodes: [], subjectPersonIds: [] },
      danger: true,
    },
    {
      label: t('admin.getIncident'),
      description: t('admin.getIncidentHint'),
      method: 'GET',
      path: input => `/core/privacy/incidents/${encodeURIComponent(input.id ?? '')}`,
      inputs: [idInput],
    },
    {
      label: t('admin.transitionIncident'),
      description: t('admin.transitionIncidentHint'),
      method: 'POST',
      path: input => `/core/privacy/incidents/${encodeURIComponent(input.id ?? '')}/transition`,
      inputs: [idInput],
      body: { toStatus: '', reasonCode: '' },
    },
    {
      label: t('admin.recordIncidentNotification'),
      description: t('admin.recordIncidentNotificationHint'),
      method: 'POST',
      path: input => `/core/privacy/incidents/${encodeURIComponent(input.id ?? '')}/notification-record`,
      inputs: [idInput],
      body: {},
    },
    {
      label: t('admin.declareRepresentation'),
      description: t('admin.declareRepresentationHint'),
      method: 'POST',
      path: () => '/core/privacy/representations',
      body: { subjectPersonId: '', representativePersonId: '', authorityType: 'legal', validFrom: new Date().toISOString(), scopes: ['privacy_requests'] },
    },
    {
      label: t('admin.verifyRepresentation'),
      description: t('admin.verifyRepresentationHint'),
      method: 'POST',
      path: input => `/core/privacy/representations/${encodeURIComponent(input.id ?? '')}/verify`,
      inputs: [idInput],
      body: {},
    },
    {
      label: t('admin.revokeRepresentation'),
      description: t('admin.revokeRepresentationHint'),
      method: 'POST',
      path: input => `/core/privacy/representations/${encodeURIComponent(input.id ?? '')}/revoke`,
      inputs: [idInput],
      body: { reasonCode: 'revoked' },
      danger: true,
    },
    {
      label: t('admin.grantDistributionConsent'),
      description: t('admin.grantDistributionConsentHint'),
      method: 'POST',
      path: () => '/core/privacy/distribution-consents',
      body: { subjectPersonId: '', purposeCode: 'distribution', categoryCodes: [], fieldCodes: [], conditionCodes: [], restrictionCodes: [] },
    },
    {
      label: t('admin.withdrawDistributionConsent'),
      description: t('admin.withdrawDistributionConsentHint'),
      method: 'POST',
      path: input => `/core/privacy/distribution-consents/${encodeURIComponent(input.id ?? '')}/withdraw`,
      inputs: [idInput],
      body: {},
      danger: true,
    },
  ];
});
</script>

<template>
  <section class="admin-tabs">
    <nav class="admin-tabs__nav" :aria-label="$t('admin.privacy')">
      <button v-for="item in tabs" :key="item.key" type="button" :class="{ 'admin-tabs__tab--active': tab === item.key }" @click="tab = item.key">{{ item.label }}</button>
    </nav>
    <AdminResourceView v-if="tab === 'requests'" :config="requestsConfig" />
    <AdminResourceView v-else-if="tab === 'holds'" :config="holdsConfig" />
    <AdminOperationPanel v-else :title="$t('admin.advancedPrivacy')" :description="$t('admin.advancedPrivacyHint')" :operations="operations" />
  </section>
</template>

<style scoped>
.admin-tabs { display: grid; gap: var(--space-16); }
.admin-tabs__nav { display: flex; flex-wrap: wrap; gap: var(--space-4); padding: var(--space-4); border: 1px solid var(--border); border-radius: var(--radius-control-m); background: var(--surface); }
.admin-tabs__nav button { min-height: 36px; padding: 0 var(--space-12); border-radius: var(--radius-control-s); color: var(--text-2); font-size: var(--type-body-small-emphasis-size); font-weight: var(--type-body-small-emphasis-weight); }
.admin-tabs__nav button.admin-tabs__tab--active { background: var(--accent-soft); color: var(--accent-text); }
</style>
