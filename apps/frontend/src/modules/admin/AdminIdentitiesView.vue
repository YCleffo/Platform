<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import {
  coreCredentials,
  type CoreExternalIdentity,
  type CoreLinkProvider,
} from '@/shared/api/core/credentials';
import type { CoreExternalProvider } from '@/shared/api/core/adminAuth';
import { ApiError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { formatDateTime } from '@/shared/formatting/date';
import { linkExternalIdentity } from './auth/externalLink';
import { useAdminOperation } from './useAdminOperation';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import './credentials.css';
const { t, locale } = useI18n();
const auth = useAdminAuthStore();
const router = useRouter();
const reads = useAdminOperation();
const actions = useAdminOperation();
const identities = ref<CoreExternalIdentity[] | null>(null);
const providers = ref<CoreLinkProvider[] | null>(null);
const loading = ref(false);
const busy = ref(false);
const pendingProvider = ref<CoreExternalProvider | null>(null);
const readError = ref<unknown>(null);
const error = ref<unknown>(null);
const success = ref('');
const withdrawal = ref<CoreExternalProvider | null>(null);
let controller: AbortController | null = null;
const codes: CoreExternalProvider[] = ['google', 'telegram'];
const providerName = (code: CoreExternalProvider) => (code === 'google' ? 'Google' : 'Telegram');
const identity = (code: CoreExternalProvider) =>
  identities.value?.find((item) => item.provider === code);
const available = (code: CoreExternalProvider) =>
  providers.value?.some((item) => item.code === code) === true;
const recent = computed(
  () => error.value instanceof ApiError && error.value.code === 'auth.recent_required',
);
const message = computed(() => (error.value === null ? '' : resolveErrorMessage(error.value)));
const confirmOpen = computed({
  get: () => withdrawal.value !== null,
  set: (value) => {
    if (!value) withdrawal.value = null;
  },
});
async function load(): Promise<void> {
  const request = reads.begin();
  loading.value = true;
  readError.value = null;
  const [own, discovery] = await Promise.allSettled([
    coreCredentials.identities(),
    coreCredentials.providers(),
  ]);
  if (!reads.current(request)) return;
  if (own.status === 'fulfilled') identities.value = own.value;
  else readError.value = own.reason;
  if (discovery.status === 'fulfilled') providers.value = discovery.value;
  else {
    providers.value = null;
    readError.value = discovery.reason;
  }
  loading.value = false;
}
function cancel(): void {
  actions.begin();
  controller?.abort();
  controller = null;
  pendingProvider.value = null;
  busy.value = false;
}
async function link(code: CoreExternalProvider): Promise<void> {
  if (busy.value || !available(code) || identity(code)) return;
  const request = actions.begin();
  controller = new AbortController();
  const signal = controller.signal;
  busy.value = true;
  pendingProvider.value = code;
  error.value = null;
  success.value = '';
  try {
    await linkExternalIdentity(code, signal, t('coreCredentials.openingProvider'));
    if (!actions.current(request)) return;
    success.value = 'coreCredentials.linked';
    await load();
  } catch (caught) {
    if (actions.current(request)) {
      error.value = caught;
      if (caught instanceof ApiError && caught.code === 'auth.identity.conflict') await load();
    }
  } finally {
    if (actions.current(request)) {
      busy.value = false;
      pendingProvider.value = null;
      controller = null;
    }
  }
}
async function unlink(): Promise<void> {
  if (busy.value || !withdrawal.value) return;
  const request = actions.begin();
  busy.value = true;
  error.value = null;
  success.value = '';
  try {
    await coreCredentials.unlink(withdrawal.value);
    if (!actions.current(request)) return;
    withdrawal.value = null;
    success.value = 'coreCredentials.unlinked';
    await load();
  } catch (caught) {
    if (actions.current(request)) {
      error.value = caught;
      if (recent.value || (caught instanceof ApiError && caught.status === 404)) {
        withdrawal.value = null;
        if (!recent.value) await load();
      }
    }
  } finally {
    if (actions.current(request)) busy.value = false;
  }
}
async function signInAgain(): Promise<void> {
  cancel();
  withdrawal.value = null;
  const done = auth.logout();
  const epoch = coreAdminSession.epoch();
  await done;
  if (coreAdminSession.epoch() === epoch)
    await router.replace({
      name: 'admin-login',
      query: { redirect: '/admin/security/identities' },
    });
}
watch(coreAdminSessionEpoch, cancel, { flush: 'sync' });
onBeforeUnmount(() => controller?.abort());
onMounted(load);
</script>
<template>
  <section class="core-credentials">
    <header class="core-credentials__head">
      <div>
        <h2>{{ $t('coreCredentials.identitiesTitle') }}</h2>
        <p>{{ $t('coreCredentials.identitiesHint') }}</p>
      </div>
      <AppButton
        variant="secondary"
        :loading="loading"
        :disabled="busy || loading"
        @click="load"
      >
        {{ $t('coreCredentials.refresh') }}
      </AppButton>
    </header>
    <p
      v-if="success"
      role="status"
    >
      {{ $t(success) }}
    </p>
    <p
      v-if="readError"
      role="alert"
    >
      {{ resolveErrorMessage(readError) }}
    </p>
    <p
      v-if="message && !withdrawal"
      role="alert"
    >
      {{ message }}
    </p>
    <AppButton
      v-if="recent"
      variant="secondary"
      @click="signInAgain"
    >
      {{ $t('coreSecurity.signInAgain') }}
    </AppButton>
    <div
      v-if="pendingProvider"
      class="core-credentials__actions"
    >
      <p role="status">
        {{ $t('coreCredentials.waitingProvider', { provider: providerName(pendingProvider) }) }}
      </p>
      <AppButton
        variant="secondary"
        @click="cancel"
      >
        {{ $t('common.cancel') }}
      </AppButton>
    </div>
    <AppSkeleton
      v-if="identities === null && loading"
      variant="card"
      :lines="4"
    />
    <div
      v-else-if="identities"
      class="core-credentials__grid"
    >
      <AppCard
        v-for="code in codes"
        :key="code"
      >
        <h3>{{ providerName(code) }}</h3>
        <AppBadge :variant="identity(code) ? 'success' : 'neutral'">
          {{ $t(identity(code) ? 'coreCredentials.connected' : 'coreCredentials.notConnected') }}
        </AppBadge>
        <template v-if="identity(code)">
          <p v-if="identity(code)?.displayName">{{ identity(code)?.displayName }}</p>
          <p v-if="identity(code)?.email">{{ identity(code)?.email }}</p>
          <AppBadge v-if="identity(code)?.emailVerified">
            {{ $t('coreCredentials.emailVerified') }}
          </AppBadge>
          <dl>
            <div>
              <dt>{{ $t('coreCredentials.linkedAt') }}</dt>
              <dd>{{ formatDateTime(identity(code)?.linkedAt, locale) }}</dd>
            </div>
            <div>
              <dt>{{ $t('coreCredentials.lastUsed') }}</dt>
              <dd>
                {{
                  identity(code)?.lastLoginAt
                    ? formatDateTime(identity(code)?.lastLoginAt, locale)
                    : $t('coreCredentials.neverUsed')
                }}
              </dd>
            </div>
          </dl>
          <AppButton
            variant="danger"
            :disabled="busy"
            :aria-label="$t('coreCredentials.unlinkNamed', { provider: providerName(code) })"
            @click="
              withdrawal = code;
              error = null;
              success = '';
            "
          >
            {{ $t('coreCredentials.unlink') }}
          </AppButton>
        </template>
        <template v-else>
          <p>
            {{
              $t(
                available(code)
                  ? 'coreCredentials.connectHint'
                  : 'coreCredentials.providerUnavailable',
              )
            }}
          </p>
          <AppButton
            :disabled="busy || !available(code)"
            :aria-label="$t('coreCredentials.connectNamed', { provider: providerName(code) })"
            @click="link(code)"
          >
            {{ $t('coreCredentials.connect') }}
          </AppButton>
        </template>
      </AppCard>
    </div>
    <AppConfirmDialog
      v-model="confirmOpen"
      :title="$t('coreCredentials.unlink')"
      :description="
        $t('coreCredentials.unlinkHint', { provider: withdrawal ? providerName(withdrawal) : '' })
      "
      :confirm-label="$t('coreCredentials.unlink')"
      :busy="busy"
      danger
      @confirm="unlink"
    >
      <p
        v-if="message"
        role="alert"
      >
        {{ message }}
      </p>
    </AppConfirmDialog>
  </section>
</template>
