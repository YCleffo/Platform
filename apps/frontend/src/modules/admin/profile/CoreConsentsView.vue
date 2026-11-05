<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  coreAccount,
  type CoreConsents,
  type CoreConsentDefinition,
} from '@/shared/api/core/account';
import { ApiError } from '@/shared/api/errors';
import { useAdminOperation } from '../useAdminOperation';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { formatDateTime } from '@/shared/formatting/date';
import { consentTemplates } from './consentTemplates';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import AppCheckbox from '@/shared/ui/fields/AppCheckbox.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';

const { t, locale } = useI18n();
const reads = useAdminOperation();
const actions = useAdminOperation();
const data = ref<CoreConsents | null>(null);
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const actionError = ref('');
const success = ref('');
const selected = ref<CoreConsentDefinition | null>(null);
const template = ref<{ title: string; text: string } | null>(null);
const withdrawal = ref<CoreConsentDefinition | null>(null);
const accepted = ref(false);
const examples = computed(() => consentTemplates(locale.value));
const reviewDocument = computed(() => selected.value?.document ?? template.value);
const reviewOpen = computed({
  get: () => selected.value !== null || template.value !== null,
  set: (value) => {
    if (!value) {
      selected.value = null;
      template.value = null;
      accepted.value = false;
    }
  },
});
function row(code: string) {
  return data.value?.consents.find((item) => item.consent_code === code);
}
function title(definition: CoreConsentDefinition): string {
  return definition.document?.title ?? definition.code;
}
function canWithdraw(definition: CoreConsentDefinition): boolean {
  const current = row(definition.code);
  return (
    definition.withdrawable &&
    !!current &&
    current.withdrawn_at === null &&
    current.purpose_code === definition.purpose_code
  );
}
async function load(): Promise<void> {
  const request = reads.begin();
  loading.value = true;
  error.value = '';
  try {
    const result = await coreAccount.consents();
    if (reads.current(request)) data.value = result;
  } catch (caught) {
    if (reads.current(request)) error.value = resolveErrorMessage(caught);
  } finally {
    if (reads.current(request)) loading.value = false;
  }
}
function review(definition: CoreConsentDefinition): void {
  selected.value = definition;
  template.value = definition.document === null ? examples.value[0]! : null;
  accepted.value = false;
  actionError.value = '';
}
async function grant(): Promise<void> {
  if (busy.value || !accepted.value || selected.value?.document == null) return;
  const request = actions.begin();
  busy.value = true;
  actionError.value = '';
  success.value = '';
  try {
    await coreAccount.grant(selected.value);
    if (!actions.current(request)) return;
    reviewOpen.value = false;
    success.value = 'coreProfile.granted';
    await load();
  } catch (caught) {
    if (!actions.current(request)) return;
    if (caught instanceof ApiError && caught.status === 409) {
      reviewOpen.value = false;
      actionError.value = t('coreProfile.documentChanged');
      await load();
    } else actionError.value = resolveErrorMessage(caught);
  } finally {
    if (actions.current(request)) busy.value = false;
  }
}
async function withdraw(): Promise<void> {
  if (busy.value || !withdrawal.value || !canWithdraw(withdrawal.value)) return;
  const request = actions.begin();
  busy.value = true;
  actionError.value = '';
  success.value = '';
  try {
    await coreAccount.withdraw(withdrawal.value.code);
    if (!actions.current(request)) return;
    withdrawal.value = null;
    success.value = 'coreProfile.withdrawn';
    await load();
  } catch (caught) {
    if (actions.current(request)) {
      actionError.value = resolveErrorMessage(caught);
      if (caught instanceof ApiError && caught.status === 404) {
        withdrawal.value = null;
        actionError.value = t('coreProfile.changedElsewhere');
        await load();
      }
    }
  } finally {
    if (actions.current(request)) busy.value = false;
  }
}
onMounted(load);
</script>
<template>
  <section class="core-consents">
    <header>
      <h2>{{ $t('coreProfile.consents') }}</h2>
      <p>{{ $t('coreProfile.consentsHint') }}</p>
    </header>
    <p
      v-if="success"
      role="status"
    >
      {{ $t(success) }}
    </p>
    <p
      v-if="actionError && !reviewOpen && !withdrawal"
      role="alert"
    >
      {{ actionError }}
    </p>
    <AppSkeleton
      v-if="loading"
      variant="card"
      :lines="5"
    />
    <AppErrorState
      v-else-if="error"
      :message="error"
    >
      <AppButton
        variant="secondary"
        @click="load"
      >
        {{ $t('common.retry') }}
      </AppButton>
    </AppErrorState>
    <template v-else-if="data">
      <p v-if="data.definitions.length === 0">{{ $t('coreProfile.noPublishedConsents') }}</p>
      <div class="core-consents__grid">
        <AppCard
          v-for="definition in data.definitions"
          :key="definition.code"
        >
          <div class="core-consents__head">
            <h3>{{ title(definition) }}</h3>
            <AppBadge :variant="row(definition.code)?.current ? 'success' : 'neutral'">
              {{
                $t(
                  row(definition.code)?.current
                    ? 'coreProfile.activeConsent'
                    : row(definition.code)?.withdrawn_at
                      ? 'coreProfile.withdrawnConsent'
                      : 'coreProfile.notGranted',
                )
              }}
            </AppBadge>
          </div>
          <p>{{ $t('coreProfile.version') }} {{ definition.current_document_version }}</p>
          <p v-if="row(definition.code)">
            {{ $t('coreProfile.grantedAt') }}:
            {{ formatDateTime(row(definition.code)?.granted_at, locale) }}
          </p>
          <p v-if="!definition.document">{{ $t('coreProfile.documentNotPublished') }}</p>
          <div class="core-consents__actions">
            <AppButton
              variant="secondary"
              :disabled="busy"
              @click="review(definition)"
            >
              {{ $t('coreProfile.readDocument') }}
            </AppButton>
            <AppButton
              v-if="canWithdraw(definition)"
              variant="danger"
              :disabled="busy"
              @click="
                withdrawal = definition;
                actionError = '';
              "
            >
              {{ $t('coreProfile.withdrawConsent') }}
            </AppButton>
          </div>
          <p v-if="row(definition.code)?.withdrawn_at">
            {{ $t('coreProfile.withdrawnAt') }}:
            {{ formatDateTime(row(definition.code)?.withdrawn_at, locale) }}
          </p>
          <p v-if="row(definition.code) && !definition.withdrawable">
            {{ $t('coreProfile.notWithdrawable') }}
          </p>
        </AppCard>
      </div>
      <template v-if="data.definitions.length === 0">
        <div class="core-consents__grid">
          <AppCard
            v-for="example in examples"
            :key="example.code"
          >
            <div class="core-consents__head">
              <h3>{{ example.title }}</h3>
              <AppBadge variant="warning">{{ $t('coreProfile.template') }}</AppBadge>
            </div>
            <p>{{ $t('coreProfile.templateHint') }}</p>
            <AppButton
              variant="secondary"
              @click="
                template = example;
                selected = null;
                accepted = false;
              "
            >
              {{ $t('coreProfile.readDocument') }}
            </AppButton>
          </AppCard>
        </div>
      </template>
      <AppCard
        v-if="
          data.consents.some(
            (item) =>
              !data?.definitions.some((definition) => definition.code === item.consent_code),
          )
        "
      >
        <h3>{{ $t('coreProfile.history') }}</h3>
        <ul>
          <li
            v-for="item in data.consents.filter(
              (item) =>
                !data?.definitions.some((definition) => definition.code === item.consent_code),
            )"
            :key="item.id"
          >
            <strong>{{ item.consent_code }}</strong>
            · {{ item.document_version }} · {{ formatDateTime(item.granted_at, locale) }}
            <p>
              {{
                $t(
                  item.withdrawn_at
                    ? 'coreProfile.withdrawnConsent'
                    : 'coreProfile.previousDocument',
                )
              }}
            </p>
          </li>
        </ul>
      </AppCard>
    </template>
    <AppDialog
      v-model="reviewOpen"
      :title="reviewDocument?.title ?? $t('coreProfile.consents')"
      :persistent="busy"
      size="l"
    >
      <div class="core-consents__review">
        <AppBadge
          v-if="selected?.document == null"
          variant="warning"
        >
          {{ $t('coreProfile.template') }}
        </AppBadge>
        <p v-if="selected?.document == null">{{ $t('coreProfile.templateHint') }}</p>
        <p v-if="selected?.document">
          {{ $t('coreProfile.version') }} {{ selected.current_document_version }}
        </p>
        <div class="core-consents__document">{{ reviewDocument?.text }}</div>
        <template v-if="selected?.document && !row(selected.code)?.current">
          <AppCheckbox
            id="core-consent-accepted"
            v-model="accepted"
            :label="$t('coreProfile.acknowledge')"
            :disabled="busy"
          />
          <AppButton
            :disabled="!accepted"
            :loading="busy"
            @click="grant"
          >
            {{ $t('coreProfile.grantConsent') }}
          </AppButton>
        </template>
        <p
          v-if="actionError"
          role="alert"
        >
          {{ actionError }}
        </p>
        <AppButton
          v-if="selected?.document == null || row(selected.code)?.current"
          variant="secondary"
          @click="reviewOpen = false"
        >
          {{ $t('coreProfile.closeDocument') }}
        </AppButton>
      </div>
    </AppDialog>
    <AppConfirmDialog
      :model-value="withdrawal !== null"
      :title="$t('coreProfile.withdrawConsent')"
      :description="$t('coreProfile.withdrawHint', { title: withdrawal ? title(withdrawal) : '' })"
      :confirm-label="$t('coreProfile.withdrawConsent')"
      :busy="busy"
      danger
      @update:model-value="
        (value) => {
          if (!value) withdrawal = null;
        }
      "
      @confirm="withdraw"
    >
      <p
        v-if="actionError"
        role="alert"
      >
        {{ actionError }}
      </p>
    </AppConfirmDialog>
  </section>
</template>
<style scoped>
.core-consents,
.core-consents__review {
  display: grid;
  gap: var(--space-16);
}
.core-consents h2 {
  font-size: var(--type-h2-size);
  font-weight: var(--type-h2-weight);
  letter-spacing: var(--type-h2-tracking);
  line-height: var(--type-h2-line);
  color: var(--text-1);
}
.core-consents h3 {
  font-size: var(--type-h3-size);
  font-weight: var(--type-h3-weight);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  color: var(--text-1);
  overflow-wrap: anywhere;
}
.core-consents p {
  color: var(--text-2);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
}
.core-consents__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 360px), 1fr));
  gap: var(--space-16);
}
.core-consents :deep(.app-card) {
  display: grid;
  gap: var(--space-12);
}
.core-consents__head,
.core-consents__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
}
.core-consents__actions {
  justify-content: flex-start;
}
.core-consents__document {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  color: var(--text-1);
  font-size: var(--type-body-large-size);
  font-weight: var(--type-body-large-weight);
  letter-spacing: var(--type-body-large-tracking);
  line-height: var(--type-body-large-line);
}
</style>
