<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  privacyOperator,
  requestStatuses,
  type IntakeInput,
  type PrivacyFilters,
  type RequestPage,
} from '@/shared/api/core/privacyOperator';
import { privacyTypes } from '@/shared/api/core/privacy';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { coreAdminSessionEpoch } from '@/core/admin/session';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { ApiError } from '@/shared/api/errors';
import { formatDateTime } from '@/shared/formatting/date';
import { useAdminOperation } from '../useAdminOperation';
import PrivacyPersonPicker from './PrivacyPersonPicker.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppCheckbox from '@/shared/ui/fields/AppCheckbox.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import '../profile/privacy.css';
const { t, te, locale } = useI18n();
const router = useRouter();
const auth = useAdminAuthStore();
const reads = useAdminOperation();
const actions = useAdminOperation();
const page = ref<RequestPage | null>(null);
const loading = ref(false);
const error = ref<unknown>(null);
const filters = ref<PrivacyFilters>({});
const applied = ref<PrivacyFilters>({});
let retryOffset = 0;
let retryFilters: PrivacyFilters = {};
const status = ref('all');
const type = ref('all');
const overdue = ref('all');
const statuses = computed(() => [
  { value: 'all', label: t('privacyOperator.allStatuses') },
  ...requestStatuses.map((value) => ({ value, label: t(`corePrivacy.status_${value}`) })),
]);
const types = computed(() =>
  privacyTypes.map((value) => ({ value, label: t(`corePrivacy.type_${value}`) })),
);
const filterTypes = computed(() => [
  { value: 'all', label: t('privacyOperator.allTypes') },
  ...types.value,
]);
const overdueOptions = computed(() => [
  { value: 'all', label: t('privacyOperator.allDeadlines') },
  { value: 'true', label: t('privacyOperator.overdueOnly') },
  { value: 'false', label: t('privacyOperator.withoutOverdue') },
]);
function label(group: string, value: string) {
  const key = `corePrivacy.${group}_${value}`;
  return te(key) ? t(key) : value;
}
async function load(nextFilters = applied.value, offset = page.value?.pagination.offset ?? 0) {
  if (!auth.can('core.privacy.requests.read')) return;
  const version = reads.begin();
  retryOffset = offset;
  retryFilters = { ...nextFilters };
  loading.value = true;
  error.value = null;
  try {
    const result = await privacyOperator.list({ ...nextFilters }, offset);
    if (!reads.current(version)) return;
    page.value = result;
    applied.value = { ...nextFilters };
  } catch (caught) {
    if (reads.current(version)) {
      error.value = caught;
      if (caught instanceof ApiError && [403, 404].includes(caught.status)) page.value = null;
    }
  } finally {
    if (reads.current(version)) loading.value = false;
  }
}
function apply() {
  filters.value = {
    status: status.value === 'all' ? '' : status.value,
    requestType: type.value === 'all' ? '' : type.value,
    overdue: overdue.value === 'all' ? '' : overdue.value,
  };
  void load(filters.value, 0);
}
const createOpen = ref(false);
const saving = ref(false);
const actionError = ref<unknown>(null);
const uncertain = ref(false);
const acknowledged = ref(false);
const input = ref<IntakeInput>({
  subjectPersonId: '',
  requestType: 'access',
  receivedAt: '',
  identityVerificationCode: '',
  identityEvidenceReference: '',
  sourceChannel: 'paper',
});
const receivedLocal = ref('');
const receivedError = ref(false);
watch(receivedLocal, () => {
  receivedError.value = false;
});
const verification = computed(() =>
  ['document_checked', 'signed_document_checked', 'identity_confirmed'].map((value) => ({
    value,
    label: t('privacyOperator.verification_' + value),
  })),
);
const channels = computed(() =>
  ['paper', 'email', 'signed_electronic_document'].map((value) => ({
    value,
    label: t('privacyOperator.channel_' + value),
  })),
);
function open() {
  if (!uncertain.value) {
    const now = new Date();
    receivedLocal.value = new Date(now.getTime() - now.getTimezoneOffset() * 60000)
      .toISOString()
      .slice(0, 16);
    input.value = {
      subjectPersonId: '',
      requestType: 'access',
      receivedAt: '',
      identityVerificationCode: '',
      identityEvidenceReference: '',
      sourceChannel: 'paper',
    };
    acknowledged.value = false;
    actionError.value = null;
  }
  createOpen.value = true;
}
async function check() {
  await load(applied.value, 0);
  if (!error.value) {
    uncertain.value = false;
    createOpen.value = false;
  }
}
async function submit() {
  if (
    saving.value ||
    uncertain.value ||
    !acknowledged.value ||
    !input.value.subjectPersonId ||
    !auth.can('core.privacy.requests.manage')
  )
    return;
  const received = new Date(receivedLocal.value);
  if (!Number.isFinite(received.getTime()) || received.getTime() > Date.now()) {
    receivedError.value = true;
    return;
  }
  const version = actions.begin();
  saving.value = true;
  actionError.value = null;
  try {
    const id = await privacyOperator.intake({
      ...input.value,
      receivedAt: received.toISOString(),
      ...(input.value.representativeVerificationReference?.trim()
        ? {
            representativeVerificationReference:
              input.value.representativeVerificationReference.trim(),
          }
        : { representativeVerificationReference: undefined }),
    });
    if (!actions.current(version)) return;
    createOpen.value = false;
    await router.push({ name: 'admin-privacy-request', params: { id } });
  } catch (caught) {
    if (actions.current(version)) {
      actionError.value = caught;
      uncertain.value = !(
        caught instanceof ApiError &&
        caught.status >= 400 &&
        caught.status < 500
      );
    }
  } finally {
    if (actions.current(version)) saving.value = false;
  }
}
watch(
  coreAdminSessionEpoch,
  () => {
    reads.begin();
    actions.begin();
    page.value = null;
    createOpen.value = false;
    actionError.value = null;
    error.value = null;
    input.value.subjectPersonId = '';
  },
  { flush: 'sync' },
);
onMounted(() => {
  void load({}, 0);
});
</script>
<template>
  <section class="core-privacy">
    <header class="core-privacy__head">
      <h2>{{ $t('admin.privacyRequests') }}</h2>
      <div class="core-privacy__actions">
        <AppButton
          v-if="auth.can('core.privacy.requests.manage')"
          :disabled="!auth.can('core.persons.read') || saving"
          @click="open"
        >
          {{ $t('privacyOperator.intake') }}
        </AppButton>
        <AppButton
          variant="secondary"
          :loading="loading"
          :disabled="loading"
          @click="load()"
        >
          {{ $t('corePrivacy.refresh') }}
        </AppButton>
      </div>
    </header>
    <p
      v-if="!auth.can('core.privacy.requests.read')"
      role="alert"
    >
      {{ $t('errors.accessDenied') }}
    </p>
    <template v-else>
      <form
        class="privacy-operator__filters"
        @submit.prevent="apply"
      >
        <div class="core-privacy__field">
          <label for="op-status">{{ $t('privacyOperator.status') }}</label>
          <AppDropdown
            id="op-status"
            v-model="status"
            :label="$t('privacyOperator.status')"
            :options="statuses"
          />
        </div>
        <div class="core-privacy__field">
          <label for="op-type">{{ $t('corePrivacy.requestType') }}</label>
          <AppDropdown
            id="op-type"
            v-model="type"
            :label="$t('corePrivacy.requestType')"
            :options="filterTypes"
          />
        </div>
        <div class="core-privacy__field">
          <label for="op-overdue">{{ $t('corePrivacy.deadlines') }}</label>
          <AppDropdown
            id="op-overdue"
            v-model="overdue"
            :label="$t('corePrivacy.deadlines')"
            :options="overdueOptions"
          />
        </div>
        <AppButton
          type="submit"
          :disabled="loading"
        >
          {{ $t('privacyOperator.apply') }}
        </AppButton>
      </form>
      <AppCard v-if="error">
        <p role="alert">{{ resolveErrorMessage(error) }}</p>
        <AppButton
          :disabled="loading"
          variant="secondary"
          @click="load(retryFilters, retryOffset)"
        >
          {{ $t('common.retry') }}
        </AppButton>
      </AppCard>
      <p
        v-if="loading && !page"
        role="status"
      >
        {{ $t('common.loading') }}
      </p>
      <p v-if="page && page.items.length === 0">{{ $t('privacyOperator.noMatches') }}</p>
      <div
        v-if="page"
        class="core-privacy__list"
      >
        <AppCard
          v-for="row in page.items"
          :key="row.id"
        >
          <div class="core-privacy__head">
            <RouterLink :to="{ name: 'admin-privacy-request', params: { id: row.id } }">
              <h3>{{ label('type', row.requestType) }} · {{ row.id.slice(-8) }}</h3>
            </RouterLink>
            <AppBadge>{{ label('status', row.status) }}</AppBadge>
          </div>
          <p>{{ $t('privacyOperator.subjectReference') }}: {{ row.subjectPersonId }}</p>
          <p>{{ $t('corePrivacy.submittedAt') }}: {{ formatDateTime(row.submittedAt, locale) }}</p>
          <p v-if="row.deadlines.some((d) => d.overdue)">{{ $t('privacyOperator.overdueOnly') }}</p>
          <RouterLink :to="{ name: 'admin-privacy-request', params: { id: row.id } }">
            {{ $t('corePrivacy.openRequest') }}
          </RouterLink>
        </AppCard>
      </div>
      <div
        v-if="page"
        class="core-privacy__actions"
      >
        <AppButton
          variant="secondary"
          :disabled="loading || page.pagination.offset === 0"
          @click="load(applied, Math.max(0, page.pagination.offset - 25))"
        >
          {{ $t('privacyOperator.previous') }}
        </AppButton>
        <p>{{ $t('privacyOperator.count', { count: page.pagination.total }) }}</p>
        <AppButton
          variant="secondary"
          :disabled="loading || page.pagination.offset + page.items.length >= page.pagination.total"
          @click="load(applied, page.pagination.offset + 25)"
        >
          {{ $t('privacyOperator.next') }}
        </AppButton>
      </div>
    </template>
    <AppDialog
      v-model="createOpen"
      :title="$t('privacyOperator.intake')"
      :persistent="saving"
      size="l"
    >
      <form
        class="core-privacy"
        @submit.prevent="submit"
      >
        <p>{{ $t('privacyOperator.intakeHint') }}</p>
        <PrivacyPersonPicker
          id="op-person"
          v-model="input.subjectPersonId"
          :label="$t('privacyOperator.person')"
          :disabled="saving || uncertain"
        />
        <div class="core-privacy__field">
          <label for="op-intake-type">{{ $t('corePrivacy.requestType') }}</label>
          <AppDropdown
            id="op-intake-type"
            v-model="input.requestType"
            :label="$t('corePrivacy.requestType')"
            :options="types"
            :disabled="saving || uncertain"
          />
        </div>
        <AppInput
          id="op-received"
          v-model="receivedLocal"
          type="datetime-local"
          :label="$t('privacyOperator.receivedAt')"
          :error="receivedError ? $t('privacyOperator.receivedInvalid') : undefined"
          required
          :disabled="saving || uncertain"
        />
        <div class="core-privacy__field">
          <label for="op-channel">{{ $t('privacyOperator.channel') }}</label>
          <AppDropdown
            id="op-channel"
            v-model="input.sourceChannel"
            :label="$t('privacyOperator.channel')"
            :options="channels"
            :disabled="saving || uncertain"
          />
        </div>
        <div class="core-privacy__field">
          <label for="op-verification">{{ $t('privacyOperator.verification') }}</label>
          <AppDropdown
            id="op-verification"
            v-model="input.identityVerificationCode"
            :label="$t('privacyOperator.verification')"
            :options="verification"
            :disabled="saving || uncertain"
          />
        </div>
        <AppInput
          id="op-evidence"
          v-model="input.identityEvidenceReference"
          :label="$t('privacyOperator.evidenceReference')"
          :hint="$t('privacyOperator.evidenceHint')"
          pattern="(vault|document|case|paper|email|signed)://[^\s]+"
          maxlength="500"
          required
          :disabled="saving || uncertain"
        />
        <AppInput
          id="op-representative-proof"
          :model-value="input.representativeVerificationReference ?? ''"
          @update:model-value="input.representativeVerificationReference = String($event)"
          :label="$t('privacyOperator.representativeProof')"
          :hint="$t('privacyOperator.optionalProof')"
          pattern="(vault|document|case|paper|email|signed)://[^\s]+"
          maxlength="500"
          :disabled="saving || uncertain"
        />
        <AppCheckbox
          id="op-intake-ack"
          v-model="acknowledged"
          :label="$t('privacyOperator.identityAck')"
          :disabled="saving || uncertain"
        />
        <p
          v-if="actionError"
          role="alert"
        >
          {{ resolveErrorMessage(actionError) }}
        </p>
        <p v-if="uncertain">{{ $t('corePrivacy.uncertain') }}</p>
        <AppButton
          v-if="uncertain"
          variant="secondary"
          :disabled="loading"
          @click="check"
        >
          {{ $t('corePrivacy.checkList') }}
        </AppButton>
        <div class="core-privacy__actions">
          <AppButton
            type="submit"
            :loading="saving"
            :disabled="
              saving ||
              uncertain ||
              !acknowledged ||
              !input.subjectPersonId ||
              !input.identityVerificationCode
            "
          >
            {{ $t('privacyOperator.register') }}
          </AppButton>
          <AppButton
            variant="secondary"
            :disabled="saving"
            @click="createOpen = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
        </div>
      </form>
    </AppDialog>
  </section>
</template>
<style scoped>
.privacy-operator__filters {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr));
  align-items: end;
  gap: var(--space-12);
}
</style>
