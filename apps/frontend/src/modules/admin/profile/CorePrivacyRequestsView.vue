<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { coreAdminSessionEpoch } from '@/core/admin/session';
import { useCorePrivacyRequests } from '@/core/admin/privacyRequestsStore';
import {
  corePrivacy,
  privacyTypes,
  requestScope,
  type PrivacyRequestType,
  type PrivacyRepresentation,
} from '@/shared/api/core/privacy';
import { ApiError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { formatDateTime } from '@/shared/formatting/date';
import { useAdminOperation } from '../useAdminOperation';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppCheckbox from '@/shared/ui/fields/AppCheckbox.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import './privacy.css';
const { t, te, locale } = useI18n();
const auth = useAdminAuthStore();
const store = useCorePrivacyRequests();
const router = useRouter();
const discoveryGuard = useAdminOperation();
const actions = useAdminOperation();
const authorities = ref<PrivacyRepresentation[]>([]);
const discoveryLoading = ref(false);
const discoveryError = ref<unknown>(null);
const createOpen = ref(false);
const subject = ref('self');
const type = ref<PrivacyRequestType>('access');
const acknowledged = ref(false);
const submitting = ref(false);
const actionError = ref<unknown>(null);
const uncertain = ref(false);
const created = ref<string | null>(null);
const limited = ref(false);
const human = computed(
  () => auth.identity?.account.personId !== null && auth.identity?.account.personId !== undefined,
);
const authority = computed(() =>
  subject.value === 'self'
    ? undefined
    : authorities.value.find((item) => item.id === subject.value),
);
const subjectValue = computed({
  get: () => subject.value,
  set: (value: string | string[]) => {
    subject.value = String(value);
  },
});
const typeValue = computed({
  get: () => type.value,
  set: (value: string | string[]) => {
    if (privacyTypes.includes(value as PrivacyRequestType))
      type.value = value as PrivacyRequestType;
  },
});
const risky = computed(() => type.value === 'erasure' || type.value === 'processing_termination');
const allowed = computed(
  () =>
    subject.value === 'self' || authority.value?.scopes.includes(requestScope[type.value]) === true,
);
const subjects = computed(() => [
  { value: 'self', label: t('corePrivacy.myself') },
  ...authorities.value.map((item, index) => ({
    value: item.id,
    label:
      (item.subjectName || t('corePrivacy.representedPerson')) +
      ' · ' +
      t('corePrivacy.authorityNumber', { number: index + 1 }),
  })),
]);
const types = computed(() =>
  privacyTypes.map((code) => ({
    value: code,
    label: t(`corePrivacy.type_${code}`),
    disabled:
      subject.value !== 'self' && authority.value?.scopes.includes(requestScope[code]) !== true,
  })),
);
function label(group: 'type' | 'status', value: string): string {
  const key = `corePrivacy.${group}_${value}`;
  return te(key) ? t(key) : t(`corePrivacy.${group}_unknown`);
}
function open(): void {
  if (uncertain.value) {
    createOpen.value = true;
    return;
  }
  subject.value = 'self';
  type.value = 'access';
  acknowledged.value = false;
  actionError.value = null;
  uncertain.value = false;
  createOpen.value = true;
}
watch(type, () => {
  acknowledged.value = false;
});
watch(
  coreAdminSessionEpoch,
  () => {
    actions.begin();
    discoveryGuard.begin();
    authorities.value = [];
    createOpen.value = false;
    created.value = null;
    actionError.value = null;
    discoveryError.value = null;
    submitting.value = false;
  },
  { flush: 'sync' },
);
async function discover(): Promise<void> {
  if (!human.value) return;
  const request = discoveryGuard.begin();
  discoveryLoading.value = true;
  discoveryError.value = null;
  try {
    const result = await corePrivacy.representations();
    if (discoveryGuard.current(request)) authorities.value = result;
  } catch (caught) {
    if (discoveryGuard.current(request)) {
      authorities.value = [];
      discoveryError.value = caught;
    }
  } finally {
    if (discoveryGuard.current(request)) discoveryLoading.value = false;
  }
}
async function checkBeforeRetry(): Promise<void> {
  await store.load();
  if (store.error === null) {
    uncertain.value = false;
    createOpen.value = false;
  }
}
async function submit(): Promise<void> {
  if (
    submitting.value ||
    uncertain.value ||
    !human.value ||
    !allowed.value ||
    (risky.value && !acknowledged.value)
  )
    return;
  const request = actions.begin();
  const selected = authority.value;
  submitting.value = true;
  actionError.value = null;
  created.value = null;
  try {
    const id = await corePrivacy.submit(type.value, selected);
    if (!actions.current(request)) return;
    created.value = id;
    limited.value = !!selected && !selected.scopes.includes('privacy.request.view');
    createOpen.value = false;
    await store.load();
    if (!actions.current(request) || limited.value) return;
    await router.push({ name: 'admin-profile-privacy-request', params: { id } });
  } catch (caught) {
    if (actions.current(request)) {
      actionError.value = caught;
      uncertain.value = !(
        caught instanceof ApiError &&
        caught.status >= 400 &&
        caught.status < 500
      );
    }
  } finally {
    if (actions.current(request)) submitting.value = false;
  }
}
onMounted(() => {
  if (human.value) {
    void store.load();
    void discover();
  }
});
</script>
<template>
  <section class="core-privacy">
    <header class="core-privacy__head">
      <div>
        <h2>{{ $t('corePrivacy.title') }}</h2>
        <p>{{ $t('corePrivacy.intro') }}</p>
      </div>
      <div class="core-privacy__actions">
        <AppButton
          :disabled="!human || submitting"
          @click="open"
        >
          {{ $t('corePrivacy.newRequest') }}
        </AppButton>
        <AppButton
          variant="secondary"
          :loading="store.loading"
          :disabled="!human || store.loading || submitting"
          @click="
            store.load();
            discover();
          "
        >
          {{ $t('corePrivacy.refresh') }}
        </AppButton>
      </div>
    </header>
    <AppCard v-if="!human">
      <p>{{ $t('corePrivacy.humanRequired') }}</p>
    </AppCard>
    <template v-else>
      <p
        v-if="created"
        role="status"
      >
        {{ $t(limited ? 'corePrivacy.acceptedLimited' : 'corePrivacy.accepted') }}
        <span>{{ $t('corePrivacy.reference') }}: {{ created }}</span>
      </p>
      <p
        v-if="store.error"
        role="alert"
      >
        {{ resolveErrorMessage(store.error) }}
      </p>
      <AppSkeleton
        v-if="store.loading && !store.loaded"
        variant="card"
        :lines="5"
      />
      <AppCard v-else-if="store.loaded && store.items.length === 0">
        <h3>{{ $t('corePrivacy.empty') }}</h3>
        <p>{{ $t('corePrivacy.emptyHint') }}</p>
      </AppCard>
      <div
        v-if="store.items.length"
        class="core-privacy__list"
      >
        <AppCard
          v-for="row in store.items"
          :key="row.id"
        >
          <div class="core-privacy__head">
            <h3>
              <RouterLink :to="{ name: 'admin-profile-privacy-request', params: { id: row.id } }">
                {{ label('type', row.requestType) }} · {{ row.id.slice(-8) }}
              </RouterLink>
            </h3>
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
          </div>
          <p>
            {{
              $t(
                row.subjectPersonId === auth.identity?.account.personId
                  ? 'corePrivacy.forMyself'
                  : 'corePrivacy.asRepresentative',
              )
            }}
          </p>
          <p>{{ $t('corePrivacy.submittedAt') }}: {{ formatDateTime(row.submittedAt, locale) }}</p>
          <p v-if="row.deadlines.some((d) => d.overdue)">{{ $t('corePrivacy.overdue') }}</p>
          <RouterLink :to="{ name: 'admin-profile-privacy-request', params: { id: row.id } }">
            {{ $t('corePrivacy.openRequest') }}
          </RouterLink>
        </AppCard>
      </div>
      <p
        v-if="store.moreError"
        role="alert"
      >
        {{ resolveErrorMessage(store.moreError) }}
      </p>
      <AppButton
        v-if="store.hasMore"
        variant="secondary"
        :loading="store.loadingMore"
        :disabled="store.loading || store.loadingMore"
        @click="store.load(true)"
      >
        {{ $t(store.moreError ? 'common.retry' : 'corePrivacy.loadMore') }}
      </AppButton>
    </template>
    <AppDialog
      v-model="createOpen"
      :title="$t('corePrivacy.newRequest')"
      :persistent="submitting"
    >
      <form
        class="core-privacy"
        @submit.prevent="submit"
      >
        <div class="core-privacy__field">
          <label for="core-privacy-subject">{{ $t('corePrivacy.subject') }}</label>
          <AppDropdown
            id="core-privacy-subject"
            v-model="subjectValue"
            :label="$t('corePrivacy.subject')"
            :options="subjects"
            :disabled="submitting"
            searchable
          />
        </div>
        <p
          v-if="discoveryLoading"
          role="status"
        >
          {{ $t('corePrivacy.loadingAuthorities') }}
        </p>
        <p
          v-if="discoveryError"
          role="alert"
        >
          {{ $t('corePrivacy.authoritiesUnavailable') }}
        </p>
        <AppButton
          v-if="discoveryError"
          variant="secondary"
          :disabled="submitting || discoveryLoading"
          @click="discover"
        >
          {{ $t('common.retry') }}
        </AppButton>
        <p v-if="authority">
          {{ $t('corePrivacy.authorityUntil') }}:
          {{
            authority.validUntil
              ? formatDateTime(authority.validUntil, locale)
              : $t('corePrivacy.noExpiry')
          }}
        </p>
        <div class="core-privacy__field">
          <label for="core-privacy-type">{{ $t('corePrivacy.requestType') }}</label>
          <AppDropdown
            id="core-privacy-type"
            v-model="typeValue"
            :label="$t('corePrivacy.requestType')"
            :options="types"
            :disabled="submitting"
          />
        </div>
        <p>{{ $t(`corePrivacy.hint_${type}`) }}</p>
        <p v-if="!allowed">{{ $t('corePrivacy.scopeUnavailable') }}</p>
        <AppCheckbox
          v-if="risky"
          id="core-privacy-ack"
          v-model="acknowledged"
          :label="$t('corePrivacy.riskyAcknowledgement')"
          :disabled="submitting"
        />
        <p
          v-if="actionError"
          role="alert"
        >
          {{
            uncertain
              ? $t('corePrivacy.uncertain')
              : actionError instanceof ApiError && actionError.status === 422
                ? $t('corePrivacy.invalidRequest')
                : resolveErrorMessage(actionError)
          }}
        </p>
        <AppButton
          v-if="uncertain"
          variant="secondary"
          :loading="store.loading"
          :disabled="store.loading"
          @click="checkBeforeRetry"
        >
          {{ $t('corePrivacy.checkList') }}
        </AppButton>
        <div class="core-privacy__actions">
          <AppButton
            type="submit"
            :variant="risky ? 'danger' : 'primary'"
            :loading="submitting"
            :disabled="submitting || uncertain || !allowed || (risky && !acknowledged)"
          >
            {{ $t('corePrivacy.submit') }}
          </AppButton>
          <AppButton
            variant="secondary"
            :disabled="submitting"
            @click="createOpen = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
        </div>
      </form>
    </AppDialog>
  </section>
</template>
