<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import { coreCredentials, type CorePasskey } from '@/shared/api/core/credentials';
import { ApiError } from '@/shared/api/errors';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { createPasskey, passkeyAvailability } from '@/shared/webauthn';
import { formatDateTime } from '@/shared/formatting/date';
import { useAdminOperation } from './useAdminOperation';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AppSkeleton from '@/shared/ui/feedback/AppSkeleton.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import './credentials.css';
const { t, locale } = useI18n();
const router = useRouter();
const auth = useAdminAuthStore();
const reads = useAdminOperation();
const actions = useAdminOperation();
const items = ref<CorePasskey[] | null>(null);
const loading = ref(false);
const busy = ref(false);
const readError = ref<unknown>(null);
const error = ref<unknown>(null);
const success = ref('');
const edit = ref<'create' | 'rename' | null>(null);
const selected = ref<CorePasskey | null>(null);
const revoke = ref<CorePasskey | null>(null);
const label = ref('');
let controller: AbortController | null = null;
const support = passkeyAvailability();
const message = computed(() => (error.value === null ? '' : resolveErrorMessage(error.value)));
const recent = computed(
  () => error.value instanceof ApiError && error.value.code === 'auth.recent_required',
);
const editOpen = computed({
  get: () => edit.value !== null,
  set: (value) => {
    if (!value) cancel();
  },
});
const revokeOpen = computed({
  get: () => revoke.value !== null,
  set: (value) => {
    if (!value) revoke.value = null;
  },
});
function name(key: CorePasskey): string {
  return key.label || t('coreCredentials.unnamed');
}
async function load(): Promise<void> {
  const request = reads.begin();
  loading.value = true;
  readError.value = null;
  try {
    const result = await coreCredentials.passkeys();
    if (reads.current(request)) items.value = result;
  } catch (caught) {
    if (reads.current(request)) readError.value = caught;
  } finally {
    if (reads.current(request)) loading.value = false;
  }
}
function cancel(): void {
  actions.begin();
  controller?.abort();
  controller = null;
  busy.value = false;
  edit.value = null;
  label.value = '';
  selected.value = null;
}
function open(mode: 'create' | 'rename', key: CorePasskey | null = null): void {
  cancel();
  error.value = null;
  success.value = '';
  edit.value = mode;
  selected.value = key;
  label.value = key?.label ?? '';
}
async function save(): Promise<void> {
  if (busy.value || edit.value === null) return;
  const mode = edit.value;
  const key = selected.value;
  const title = label.value.trim() || null;
  const request = actions.begin();
  controller = new AbortController();
  const signal = controller.signal;
  busy.value = true;
  error.value = null;
  success.value = '';
  try {
    if (mode === 'create') {
      const options = await coreCredentials.registrationOptions();
      if (!actions.current(request) || signal.aborted) return;
      const credential = await createPasskey(options, signal);
      if (!actions.current(request) || signal.aborted) return;
      await coreCredentials.register(credential, title);
    } else if (key) await coreCredentials.rename(key.id, title);
    if (!actions.current(request)) return;
    edit.value = null;
    selected.value = null;
    label.value = '';
    success.value = mode === 'create' ? 'coreCredentials.created' : 'coreCredentials.renamed';
    await load();
  } catch (caught) {
    if (actions.current(request)) {
      error.value = caught;
      if (recent.value) edit.value = null;
    }
  } finally {
    if (actions.current(request)) {
      busy.value = false;
      controller = null;
    }
  }
}
async function remove(): Promise<void> {
  if (busy.value || !revoke.value) return;
  const request = actions.begin();
  busy.value = true;
  error.value = null;
  success.value = '';
  try {
    await coreCredentials.revoke(revoke.value.id);
    if (!actions.current(request)) return;
    revoke.value = null;
    success.value = 'coreCredentials.revoked';
    await load();
  } catch (caught) {
    if (actions.current(request)) {
      error.value = caught;
      if (recent.value || (caught instanceof ApiError && caught.status === 404)) {
        revoke.value = null;
        if (!recent.value) await load();
      }
    }
  } finally {
    if (actions.current(request)) busy.value = false;
  }
}
async function signInAgain(): Promise<void> {
  cancel();
  revoke.value = null;
  const done = auth.logout();
  const epoch = coreAdminSession.epoch();
  await done;
  if (coreAdminSession.epoch() === epoch)
    await router.replace({ name: 'admin-login', query: { redirect: '/admin/security/passkeys' } });
}
watch(coreAdminSessionEpoch, cancel, { flush: 'sync' });
onBeforeUnmount(() => controller?.abort());
onMounted(load);
</script>
<template>
  <section class="core-credentials">
    <header class="core-credentials__head">
      <div>
        <h2>{{ $t('coreCredentials.passkeysTitle') }}</h2>
        <p>{{ $t('coreCredentials.passkeysHint') }}</p>
      </div>
      <div class="core-credentials__actions">
        <AppButton
          :disabled="busy || support !== 'available'"
          @click="open('create')"
        >
          {{ $t('coreCredentials.add') }}
        </AppButton>
        <AppButton
          variant="secondary"
          :loading="loading"
          :disabled="busy || loading"
          @click="load"
        >
          {{ $t('coreCredentials.refresh') }}
        </AppButton>
      </div>
    </header>
    <p
      v-if="support !== 'available'"
      role="status"
    >
      {{ $t(`coreCredentials.${support}`) }}
    </p>
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
      v-if="message && !edit && !revoke"
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
    <AppSkeleton
      v-if="items === null && loading"
      variant="card"
      :lines="4"
    />
    <AppCard v-else-if="items?.length === 0">
      <h3>{{ $t('coreCredentials.emptyTitle') }}</h3>
      <p>{{ $t('coreCredentials.emptyHint') }}</p>
    </AppCard>
    <div
      v-else-if="items"
      class="core-credentials__grid"
    >
      <AppCard
        v-for="key in items"
        :key="key.id"
      >
        <h3>{{ name(key) }}</h3>
        <AppBadge>
          {{ $t(key.backupState ? 'coreCredentials.synced' : 'coreCredentials.device') }}
        </AppBadge>
        <dl>
          <div>
            <dt>{{ $t('coreCredentials.createdAt') }}</dt>
            <dd>{{ formatDateTime(key.createdAt, locale) }}</dd>
          </div>
          <div>
            <dt>{{ $t('coreCredentials.lastUsed') }}</dt>
            <dd>
              {{
                key.lastUsedAt
                  ? formatDateTime(key.lastUsedAt, locale)
                  : $t('coreCredentials.neverUsed')
              }}
            </dd>
          </div>
        </dl>
        <div class="core-credentials__actions">
          <AppButton
            variant="secondary"
            :disabled="busy"
            :aria-label="$t('coreCredentials.renameNamed', { name: name(key) })"
            @click="open('rename', key)"
          >
            {{ $t('coreCredentials.rename') }}
          </AppButton>
          <AppButton
            variant="danger"
            :disabled="busy"
            :aria-label="$t('coreCredentials.revokeNamed', { name: name(key) })"
            @click="
              revoke = key;
              error = null;
              success = '';
            "
          >
            {{ $t('coreCredentials.revoke') }}
          </AppButton>
        </div>
      </AppCard>
    </div>
    <AppDialog
      v-model="editOpen"
      :title="$t(edit === 'create' ? 'coreCredentials.add' : 'coreCredentials.rename')"
      :persistent="busy"
    >
      <form
        class="core-credentials"
        @submit.prevent="save"
      >
        <AppInput
          id="core-passkey-label"
          v-model="label"
          :label="$t('coreCredentials.label')"
          :hint="$t('coreCredentials.labelHint')"
          :maxlength="160"
          :disabled="busy"
          autocomplete="off"
        />
        <p
          v-if="busy && edit === 'create'"
          role="status"
        >
          {{ $t('coreCredentials.browserPrompt') }}
        </p>
        <p
          v-if="message"
          role="alert"
        >
          {{ message }}
        </p>
        <div class="core-credentials__actions">
          <AppButton
            type="submit"
            :loading="busy"
            :disabled="busy"
          >
            {{ $t(edit === 'create' ? 'coreCredentials.continue' : 'common.save') }}
          </AppButton>
          <AppButton
            variant="secondary"
            :disabled="busy && edit !== 'create'"
            @click="cancel"
          >
            {{ $t('common.cancel') }}
          </AppButton>
        </div>
      </form>
    </AppDialog>
    <AppConfirmDialog
      v-model="revokeOpen"
      :title="$t('coreCredentials.revoke')"
      :description="$t('coreCredentials.revokeHint', { name: revoke ? name(revoke) : '' })"
      :confirm-label="$t('coreCredentials.revoke')"
      :busy="busy"
      danger
      @confirm="remove"
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
