<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import {
  coreConfiguration,
  machineCode,
  safeKey,
  type CoreFlag,
  type CoreModule,
} from '@/shared/api/core/configuration';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useAdminOperation } from '../useAdminOperation';
import { useConfigCollection } from './useConfigCollection';
import { draftValue, readDraft } from './valueDraft';
import ConfigValueEditor from './ConfigValueEditor.vue';
import ConfigValueView from './ConfigValueView.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppRange from '@/shared/ui/fields/AppRange.vue';
import AppSwitch from '@/shared/ui/fields/AppSwitch.vue';
import AppCheckbox from '@/shared/ui/fields/AppCheckbox.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
const { t } = useI18n();
const auth = useAdminAuthStore();
const detailGuard = useAdminOperation();
const moduleGuard = useAdminOperation();
const search = ref('');
const modules = ref<CoreModule[]>([]);
const moduleError = ref<unknown>(null);
const detailError = ref<unknown>(null);
const detailBusy = ref(false);
const open = ref(false);
const deleting = ref(false);
const selected = ref<CoreFlag | null>(null);
const code = ref('');
const moduleCode = ref('__unbound__');
const enabled = ref(false);
const rollout = ref(0);
const description = ref('');
const draft = ref(draftValue({}));
const ack = ref(false);
const validation = ref('');
const collection = useConfigCollection(
  () => coreConfiguration.flags(),
  () => {
    open.value = false;
    selected.value = null;
    modules.value = [];
    draft.value = draftValue({});
    description.value = '';
    detailGuard.begin();
    moduleGuard.begin();
    detailBusy.value = false;
    detailError.value = null;
    moduleError.value = null;
  },
);
const { items, loading, ready, error, busy, actionError, uncertain, saved } = collection;
const localValidation = computed(() => actionError.value instanceof TypeError);
const canRead = computed(() => auth.can('core.feature_flags.read'));
const canManage = computed(() => auth.can('core.feature_flags.manage'));
const filtered = computed(() =>
  items.value.filter((row) =>
    (row.code + ' ' + (row.description ?? ''))
      .toLocaleLowerCase()
      .includes(search.value.toLocaleLowerCase()),
  ),
);
const moduleOptions = computed(() => [
  { value: '__unbound__', label: t('configuration.allModules') },
  ...modules.value.map((m) => ({ value: m.code, label: m.name, description: m.code })),
  ...(selected.value?.moduleCode &&
  !modules.value.some((m) => m.code === selected.value?.moduleCode)
    ? [{ value: selected.value.moduleCode, label: selected.value.moduleCode }]
    : []),
]);
const invalidCode = computed(() => !machineCode(code.value) || !safeKey(code.value));
async function loadModules() {
  moduleError.value = null;
  if (!auth.can('core.modules.read')) return;
  const op = moduleGuard.begin();
  try {
    const data = await coreConfiguration.modules();
    if (moduleGuard.current(op)) modules.value = data;
  } catch (e) {
    if (moduleGuard.current(op)) moduleError.value = e;
  }
}
function create() {
  selected.value = null;
  code.value = '';
  moduleCode.value = '__unbound__';
  enabled.value = false;
  rollout.value = 0;
  description.value = '';
  draft.value = draftValue({});
  ack.value = false;
  validation.value = '';
  deleting.value = false;
  actionError.value = null;
  open.value = true;
  void loadModules();
}
async function edit(row: CoreFlag, remove = false) {
  if (detailBusy.value || busy.value) return;
  const op = detailGuard.begin();
  detailBusy.value = true;
  detailError.value = null;
  open.value = false;
  try {
    const fresh = await coreConfiguration.flag(row.code);
    if (!detailGuard.current(op)) return;
    selected.value = fresh;
    code.value = fresh.code;
    moduleCode.value = fresh.moduleCode ?? '__unbound__';
    enabled.value = fresh.enabled;
    rollout.value = fresh.rolloutPercent;
    description.value = fresh.description ?? '';
    draft.value = draftValue(fresh.config);
    ack.value = false;
    validation.value = '';
    deleting.value = remove;
    actionError.value = null;
    open.value = true;
  } catch (e) {
    if (detailGuard.current(op)) detailError.value = e;
  } finally {
    if (detailGuard.current(op)) detailBusy.value = false;
  }
  if (open.value && !remove) void loadModules();
}
async function submit() {
  validation.value = '';
  if (!canManage.value || !ready.value || !ack.value || invalidCode.value || uncertain.value)
    return;
  let config: CoreFlag['config'];
  try {
    config = readDraft(draft.value) as CoreFlag['config'];
    if (new TextEncoder().encode(JSON.stringify(config)).length > 16384) throw new TypeError();
  } catch {
    validation.value = 'valueInvalid';
    return;
  }
  await collection.mutate<CoreFlag | void>(
    () =>
      deleting.value
        ? coreConfiguration.removeFlag(code.value)
        : coreConfiguration.saveFlag(code.value, {
            moduleCode: moduleCode.value === '__unbound__' ? null : moduleCode.value,
            enabled: enabled.value,
            rolloutPercent: rollout.value,
            config,
            description: description.value.trim() || null,
          }),
    () => {
      open.value = false;
      selected.value = null;
      draft.value = draftValue({});
    },
  );
}
watch(
  canRead,
  (value) => {
    if (value) void collection.load();
    else {
      items.value = [];
      ready.value = false;
      open.value = false;
    }
  },
  { immediate: true },
);
</script>
<template>
  <section class="config-page">
    <header class="config-head">
      <h2>{{ $t('admin.featureFlags') }}</h2>
      <div class="config-actions">
        <AppButton
          v-if="canManage && canRead"
          :disabled="!ready || busy || uncertain || detailBusy"
          @click="create"
        >
          {{ $t('configuration.addFlag') }}
        </AppButton>
        <AppButton
          v-if="canRead"
          variant="secondary"
          :loading="loading"
          :disabled="busy || detailBusy"
          @click="
            detailError = null;
            collection.load();
          "
        >
          {{ $t('configuration.refresh') }}
        </AppButton>
      </div>
    </header>
    <p
      v-if="!canRead"
      class="config-note"
    >
      {{ $t('configuration.noRead') }}
    </p>
    <template v-else>
      <p class="config-note">{{ $t('configuration.flagHint') }}</p>
      <p
        v-if="saved"
        role="status"
        class="config-success"
      >
        {{ $t('configuration.saved') }}
      </p>
      <p
        v-if="uncertain"
        role="alert"
        class="config-error"
      >
        {{ $t('configuration.uncertain') }}
      </p>
      <AppErrorState
        v-if="error || detailError"
        :message="resolveErrorMessage(error || detailError)"
      />
      <AppInput
        id="config-flag-search"
        v-model="search"
        class="config-search"
        :label="$t('configuration.searchFlags')"
        type="search"
      />
      <p
        v-if="loading && !items.length"
        role="status"
      >
        {{ $t('common.loading') }}
      </p>
      <p
        v-else-if="!filtered.length && ready"
        class="config-note"
      >
        {{ $t('configuration.emptyFlags') }}
      </p>
      <div class="config-grid">
        <article
          v-for="row in filtered"
          :key="row.code"
          class="config-card"
        >
          <h3>{{ row.code }}</h3>
          <p
            v-if="row.description"
            class="config-note"
          >
            {{ row.description }}
          </p>
          <p>
            {{ $t(row.enabled ? 'configuration.enabled' : 'configuration.disabled') }} ·
            {{ row.rolloutPercent }}% · {{ row.moduleCode || $t('configuration.allModules') }}
          </p>
          <ConfigValueView :value="row.config" />
          <div class="config-actions">
            <AppButton
              variant="secondary"
              :disabled="!ready || busy || uncertain || detailBusy"
              @click="edit(row)"
            >
              {{ $t(canManage ? 'configuration.edit' : 'configuration.details') }}
            </AppButton>
            <AppButton
              v-if="canManage"
              variant="danger"
              :disabled="!ready || busy || uncertain || detailBusy"
              @click="edit(row, true)"
            >
              {{ $t('configuration.delete') }}
            </AppButton>
          </div>
        </article>
      </div>
    </template>
    <AppDialog
      v-model="open"
      :title="
        deleting
          ? $t('configuration.deleteFlag')
          : selected
            ? $t('configuration.editFlag')
            : $t('configuration.addFlag')
      "
      :persistent="busy"
      size="l"
    >
      <form
        class="config-form"
        @submit.prevent="submit"
      >
        <p
          v-if="deleting"
          class="config-note"
        >
          {{ $t('configuration.deleteHint', { key: code }) }}
        </p>
        <AppInput
          id="config-flag-code"
          v-model="code"
          :label="$t('configuration.code')"
          :maxlength="160"
          required
          :disabled="busy || !!selected || !canManage || uncertain"
          :error="code && invalidCode ? $t('configuration.keyInvalid') : undefined"
        />
        <template v-if="!deleting">
          <div>
            <p class="config-note">{{ $t('configuration.relatedModule') }}</p>
            <AppDropdown
              id="config-flag-module"
              v-model="moduleCode"
              :label="$t('configuration.relatedModule')"
              :options="moduleOptions"
              searchable
              :disabled="busy || !canManage || uncertain"
            />
          </div>
          <p
            v-if="moduleError"
            role="alert"
            class="config-error"
          >
            {{ resolveErrorMessage(moduleError) }}
          </p>
          <p
            v-if="!auth.can('core.modules.read')"
            class="config-note"
          >
            {{ $t('configuration.moduleLookupPermission') }}
          </p>
          <AppSwitch
            id="config-flag-enabled"
            v-model="enabled"
            :label="$t('configuration.enabled')"
            :disabled="busy || !canManage || uncertain"
          />
          <AppRange
            id="config-flag-rollout"
            v-model="rollout"
            :label="$t('configuration.rollout')"
            :min="0"
            :max="100"
            :default-value="0"
            suffix="%"
            :disabled="busy || !canManage || uncertain"
            :description="$t('configuration.rolloutHint')"
          />
          <ConfigValueEditor
            v-model="draft"
            object-only
            :disabled="busy || !canManage || uncertain"
          />
          <AppInput
            id="config-flag-description"
            v-model="description"
            :label="$t('configuration.description')"
            :maxlength="1000"
            :disabled="busy || !canManage || uncertain"
          />
        </template>
        <AppCheckbox
          v-if="canManage"
          id="config-flag-ack"
          v-model="ack"
          :label="$t(deleting ? 'configuration.deleteAck' : 'configuration.changeAck')"
          :disabled="busy || uncertain"
        />
        <p
          v-if="validation"
          role="alert"
          class="config-error"
        >
          {{ $t('configuration.' + validation) }}
        </p>
        <p
          v-if="actionError"
          role="alert"
          class="config-error"
        >
          {{
            localValidation ? $t('configuration.valueInvalid') : resolveErrorMessage(actionError)
          }}
        </p>
        <p
          v-if="uncertain"
          role="alert"
          class="config-error"
        >
          {{ $t('configuration.uncertain') }}
        </p>
        <div class="config-actions">
          <AppButton
            variant="secondary"
            :disabled="busy"
            @click="open = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton
            v-if="canManage"
            type="submit"
            :variant="deleting ? 'danger' : 'primary'"
            :loading="busy"
            :disabled="!ready || !ack || invalidCode || uncertain"
          >
            {{ $t(deleting ? 'configuration.delete' : 'configuration.save') }}
          </AppButton>
        </div>
      </form>
    </AppDialog>
  </section>
</template>
