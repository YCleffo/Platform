<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { coreAdminSessionEpoch } from '@/core/admin/session';
import {
  corePrivacy,
  responseKind,
  type OwnPrivacyRequest,
  type PrivacyRepresentation,
  type PrivacyResponse,
} from '@/shared/api/core/privacy';
import { ApiError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { formatDateTime } from '@/shared/formatting/date';
import { useAdminOperation } from '../useAdminOperation';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import AppJsonResponseDialog from '@/shared/ui/overlays/AppJsonResponseDialog.vue';
import './privacy.css';
const { t, te, locale } = useI18n();
const route = useRoute();
const auth = useAdminAuthStore();
const reads = useAdminOperation();
const actions = useAdminOperation();
const row = ref<OwnPrivacyRequest | null>(null);
const authority = ref<PrivacyRepresentation | null>(null);
const loading = ref(false);
const error = ref<unknown>(null);
const busy = ref(false);
const responseError = ref<unknown>(null);
const confirmOpen = ref(false);
const previewOpen = ref(false);
const response = ref<PrivacyResponse | null>(null);
const handedToBrowser = ref(false);
let controller: AbortController | null = null;
let objectUrl: string | null = null;
let releaseTimer: ReturnType<typeof setTimeout> | null = null;
const ownPerson = computed(() => auth.identity?.account.personId ?? null);
const self = computed(() => row.value?.subjectPersonId === ownPerson.value);
const neededScope = computed(() =>
  row.value?.requestType === 'access'
    ? 'privacy.data.access'
    : row.value?.requestType === 'export'
      ? 'privacy.data.export'
      : 'privacy.processing_information.response',
);
const permitted = computed(
  () => self.value || authority.value?.scopes.includes(neededScope.value) === true,
);
const canReceive = computed(
  () =>
    !!row.value &&
    row.value.status === 'in_progress' &&
    responseKind(row.value) !== null &&
    permitted.value &&
    error.value === null &&
    response.value === null,
);
function label(group: string, value: string): string {
  const key = `corePrivacy.${group}_${value}`;
  return te(key) ? t(key) : t(`corePrivacy.${group}_unknown`);
}
const responseHint = computed(() => {
  if (response.value) return 'corePrivacy.receivedHint';
  if (loading.value) return 'common.loading';
  if (row.value?.status === 'completed') return 'corePrivacy.completedResponseHint';
  if (['partially_completed', 'rejected', 'cancelled'].includes(row.value?.status ?? ''))
    return 'corePrivacy.closedHint';
  if (canReceive.value) return 'corePrivacy.readyHint';
  if (row.value?.status === 'in_progress' && !permitted.value)
    return 'corePrivacy.deliveryScopeUnavailable';
  return 'corePrivacy.waitingHint';
});
function revokeUrl(): void {
  if (releaseTimer !== null) clearTimeout(releaseTimer);
  releaseTimer = null;
  if (objectUrl) URL.revokeObjectURL(objectUrl);
  objectUrl = null;
}
function clearResponse(): void {
  controller?.abort();
  controller = null;
  response.value = null;
  previewOpen.value = false;
  confirmOpen.value = false;
  handedToBrowser.value = false;
  revokeUrl();
}
async function load(preserveResponse = false): Promise<void> {
  const request = reads.begin();
  const id = String(route.params.id ?? '');
  if (!preserveResponse) {
    actions.begin();
    clearResponse();
    responseError.value = null;
    busy.value = false;
  }
  if (!preserveResponse) {
    row.value = null;
    authority.value = null;
  }
  error.value = null;
  loading.value = true;
  try {
    const result = await corePrivacy.get(id);
    if (!reads.current(request)) return;
    row.value = result;
    if (result.subjectPersonId !== ownPerson.value) {
      const representations = await corePrivacy.representations();
      if (!reads.current(request)) return;
      authority.value =
        representations.find(
          (item) =>
            item.id === result.representationId && item.subjectPersonId === result.subjectPersonId,
        ) ?? null;
    }
  } catch (caught) {
    if (reads.current(request)) {
      error.value = caught;
      if (caught instanceof ApiError && caught.status === 404 && response.value === null) {
        row.value = null;
        authority.value = null;
      }
    }
  } finally {
    if (reads.current(request)) loading.value = false;
  }
}
async function receive(): Promise<void> {
  if (busy.value || !canReceive.value || !row.value || !ownPerson.value) return;
  const currentRow = row.value;
  const request = actions.begin();
  controller = new AbortController();
  busy.value = true;
  responseError.value = null;
  try {
    const result = await corePrivacy.response(currentRow, ownPerson.value, controller.signal);
    if (!actions.current(request)) return;
    response.value = result;
    confirmOpen.value = false;
    previewOpen.value = true;
    await load(true);
  } catch (caught) {
    if (actions.current(request)) {
      responseError.value = caught;
      confirmOpen.value = false;
      await load(true);
    }
  } finally {
    if (actions.current(request)) {
      busy.value = false;
      controller = null;
    }
  }
}
function download(): void {
  if (!response.value || !row.value) return;
  revokeUrl();
  objectUrl = URL.createObjectURL(
    new Blob([response.value.raw], { type: 'application/json;charset=utf-8' }),
  );
  const link = document.createElement('a');
  link.href = objectUrl;
  link.download = `platform-privacy-${row.value.requestType.replace(/[^a-z0-9_-]/gi, '')}-${row.value.id}.json`;
  document.body.append(link);
  link.click();
  link.remove();
  handedToBrowser.value = true;
  releaseTimer = setTimeout(revokeUrl, 1000);
}
watch(
  () => route.params.id,
  () => {
    void load();
  },
  { immediate: true },
);
watch(
  coreAdminSessionEpoch,
  () => {
    actions.begin();
    reads.begin();
    row.value = null;
    authority.value = null;
    error.value = null;
    responseError.value = null;
    clearResponse();
  },
  { flush: 'sync' },
);
onBeforeUnmount(clearResponse);
</script>
<template>
  <section class="core-privacy">
    <RouterLink :to="{ name: 'admin-profile-privacy' }">{{ $t('corePrivacy.back') }}</RouterLink>
    <header class="core-privacy__head">
      <h2>{{ row ? label('type', row.requestType) : $t('corePrivacy.details') }}</h2>
      <AppButton
        variant="secondary"
        :loading="loading"
        :disabled="loading || busy"
        @click="load(response !== null)"
      >
        {{ $t('corePrivacy.refresh') }}
      </AppButton>
    </header>
    <AppSkeleton
      v-if="loading && !row"
      variant="card"
      :lines="5"
    />
    <AppCard v-if="error">
      <p role="alert">
        {{
          error instanceof ApiError && error.status === 404
            ? $t('corePrivacy.notVisible')
            : resolveErrorMessage(error)
        }}
      </p>
    </AppCard>
    <template v-if="row">
      <AppCard>
        <AppBadge
          :variant="
            row.status === 'completed'
              ? 'success'
              : row.status === 'rejected'
                ? 'danger'
                : 'neutral'
          "
        >
          {{ label('status', row.status) }}
        </AppBadge>
        <p>
          {{ $t(self ? 'corePrivacy.forMyself' : 'corePrivacy.asRepresentative') }}
          <span v-if="!self && authority?.subjectName">· {{ authority.subjectName }}</span>
        </p>
        <dl>
          <div>
            <dt>{{ $t('corePrivacy.reference') }}</dt>
            <dd>{{ row.id }}</dd>
          </div>
          <div>
            <dt>{{ $t('corePrivacy.submittedAt') }}</dt>
            <dd>{{ formatDateTime(row.submittedAt, locale) }}</dd>
          </div>
          <div v-if="row.completedAt">
            <dt>{{ $t('corePrivacy.completedAt') }}</dt>
            <dd>{{ formatDateTime(row.completedAt, locale) }}</dd>
          </div>
          <div v-if="row.resolutionCode">
            <dt>{{ $t('corePrivacy.resolutionCode') }}</dt>
            <dd>{{ row.resolutionCode }}</dd>
          </div>
        </dl>
      </AppCard>
      <AppCard v-if="row.deadlines.length">
        <h3>{{ $t('corePrivacy.deadlines') }}</h3>
        <ul class="core-privacy__deadlines">
          <li
            v-for="deadline in row.deadlines"
            :key="deadline.code"
          >
            <h3>{{ label('deadline', deadline.code) }}</h3>
            <p>
              {{ $t('corePrivacy.dueAt') }}: {{ formatDateTime(deadline.effectiveDueAt, locale) }}
            </p>
            <p v-if="deadline.extendedDueAt">
              {{ $t('corePrivacy.extendedFrom') }} {{ formatDateTime(deadline.dueAt, locale) }}
            </p>
            <AppBadge
              v-if="deadline.overdue"
              variant="warning"
            >
              {{ $t('corePrivacy.overdue') }}
            </AppBadge>
            <p v-if="deadline.satisfiedAt">
              {{ $t('corePrivacy.satisfiedAt') }}:
              {{ formatDateTime(deadline.satisfiedAt, locale) }}
            </p>
          </li>
        </ul>
      </AppCard>
      <AppCard v-if="responseKind(row)">
        <h3>{{ $t('corePrivacy.responseTitle') }}</h3>
        <p>
          {{ $t(responseHint) }}
        </p>
        <p
          v-if="responseError"
          role="alert"
        >
          {{
            responseError instanceof ApiError && responseError.status === 404
              ? $t('corePrivacy.responseUnavailable')
              : responseError instanceof ApiError && responseError.status === 413
                ? $t('corePrivacy.tooLarge')
                : $t('corePrivacy.responseFailed')
          }}
        </p>
        <div class="core-privacy__actions">
          <AppButton
            v-if="canReceive"
            :disabled="busy || loading"
            :loading="busy"
            @click="
              confirmOpen = true;
              responseError = null;
            "
          >
            {{ $t('corePrivacy.receive') }}
          </AppButton>
          <AppButton
            v-if="response"
            @click="download"
          >
            {{ $t('corePrivacy.download') }}
          </AppButton>
          <AppButton
            v-if="response"
            variant="secondary"
            @click="previewOpen = true"
          >
            {{ $t('corePrivacy.preview') }}
          </AppButton>
        </div>
        <p
          v-if="handedToBrowser"
          role="status"
        >
          {{ $t('corePrivacy.downloadStarted') }}
        </p>
      </AppCard>
      <AppCard v-else>
        <p>{{ $t('corePrivacy.noAutomaticResponse') }}</p>
      </AppCard>
    </template>
    <AppConfirmDialog
      v-model="confirmOpen"
      :title="$t('corePrivacy.receive')"
      :description="$t('corePrivacy.oneTimeWarning')"
      :confirm-label="$t('corePrivacy.receive')"
      :busy="busy"
      @confirm="receive"
    />
    <AppJsonResponseDialog
      v-model="previewOpen"
      :title="$t('corePrivacy.responseTitle')"
      :description="$t('corePrivacy.receivedHint')"
      :data="response?.document ?? null"
    >
      <template #actions>
        <AppButton
          :disabled="!response || !row"
          @click="download"
        >
          {{ $t('corePrivacy.download') }}
        </AppButton>
        <p
          v-if="handedToBrowser"
          role="status"
        >
          {{ $t('corePrivacy.downloadStarted') }}
        </p>
      </template>
    </AppJsonResponseDialog>
  </section>
</template>
