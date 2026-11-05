<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import {
  coreConfiguration,
  machineCode,
  safeKey,
  type CoreSetting,
  type ConfigValue,
} from '@/shared/api/core/configuration';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useAdminOperation } from '../useAdminOperation';
import { useConfigCollection } from './useConfigCollection';
import { draftValue, readDraft } from './valueDraft';
import ConfigValueEditor from './ConfigValueEditor.vue';
import ConfigValueView from './ConfigValueView.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppSwitch from '@/shared/ui/fields/AppSwitch.vue';
import AppCheckbox from '@/shared/ui/fields/AppCheckbox.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import './configuration.css';
const props = defineProps<{ moduleCode?: string }>();
const auth = useAdminAuthStore();
const detailGuard = useAdminOperation();
const search = ref('');
const open = ref(false);
const deleting = ref(false);
const selected = ref<CoreSetting | null>(null);
const detailBusy = ref(false);
const detailError = ref<unknown>(null);
const key = ref('');
const description = ref('');
const isPublic = ref(false);
const ack = ref(false);
const draft = ref(draftValue());
const validation = ref('');
const collection = useConfigCollection(
  () => coreConfiguration.settings(props.moduleCode),
  () => {
    open.value = false;
    selected.value = null;
    draft.value = draftValue();
    key.value = '';
    description.value = '';
    detailGuard.begin();
    detailBusy.value = false;
    detailError.value = null;
  },
);
const { items, loading, ready, error, busy, actionError, uncertain, saved } = collection;
const localValidation = computed(() => actionError.value instanceof TypeError);
const canRead = computed(() => auth.can('core.settings.read'));
const canManage = computed(() => auth.can('core.settings.manage'));
const filtered = computed(() =>
  items.value.filter((row) =>
    (row.key + ' ' + (row.description ?? ''))
      .toLocaleLowerCase()
      .includes(search.value.toLocaleLowerCase()),
  ),
);
const invalidKey = computed(() => !machineCode(key.value) || !safeKey(key.value));
function create() {
  selected.value = null;
  key.value = '';
  description.value = '';
  isPublic.value = false;
  draft.value = draftValue();
  deleting.value = false;
  ack.value = false;
  validation.value = '';
  actionError.value = null;
  open.value = true;
}
async function edit(row: CoreSetting, remove = false) {
  if (detailBusy.value || busy.value) return;
  const op = detailGuard.begin();
  detailBusy.value = true;
  detailError.value = null;
  selected.value = null;
  open.value = false;
  try {
    const fresh = await coreConfiguration.setting(row.key, props.moduleCode);
    if (!detailGuard.current(op)) return;
    selected.value = fresh;
    key.value = fresh.key;
    description.value = fresh.description ?? '';
    isPublic.value = fresh.isPublic === true;
    draft.value = draftValue(fresh.value);
    deleting.value = remove;
    ack.value = false;
    validation.value = '';
    actionError.value = null;
    open.value = true;
  } catch (e) {
    if (detailGuard.current(op)) detailError.value = e;
  } finally {
    if (detailGuard.current(op)) detailBusy.value = false;
  }
}
async function submit() {
  validation.value = '';
  if (
    !canManage.value ||
    !ready.value ||
    uncertain.value ||
    invalidKey.value ||
    ((deleting.value || isPublic.value) && !ack.value)
  )
    return;
  let value: ConfigValue;
  try {
    value = readDraft(draft.value);
    if (new TextEncoder().encode(JSON.stringify(value)).length > 16384) throw new TypeError();
  } catch {
    validation.value = 'valueInvalid';
    return;
  }
  await collection.mutate<CoreSetting | void>(
    () =>
      deleting.value
        ? coreConfiguration.removeSetting(key.value, props.moduleCode)
        : coreConfiguration.saveSetting(
            key.value,
            {
              value,
              description: description.value.trim() || null,
              ...(props.moduleCode === undefined ? { isPublic: isPublic.value } : {}),
            },
            props.moduleCode,
          ),
    () => {
      open.value = false;
      selected.value = null;
      draft.value = draftValue();
    },
  );
}
watch(
  () => props.moduleCode,
  () => {
    detailGuard.begin();
    open.value = false;
    items.value = [];
    void collection.load();
  },
);
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
      <h2>
        {{ moduleCode ? $t('configuration.moduleSettings') : $t('configuration.systemSettings') }}
      </h2>
      <div class="config-actions">
        <AppButton
          v-if="canManage && canRead"
          :disabled="!ready || busy || uncertain || detailBusy"
          @click="create"
        >
          {{ $t('configuration.addSetting') }}
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
      <p class="config-note">{{ $t('configuration.settingHint') }}</p>
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
        @retry="collection.load"
      />
      <AppInput
        id="config-setting-search"
        v-model="search"
        class="config-search"
        :label="$t('configuration.searchSettings')"
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
        {{ $t('configuration.emptySettings') }}
      </p>
      <div class="config-grid">
        <article
          v-for="row in filtered"
          :key="row.id"
          class="config-card"
        >
          <h3>{{ row.key }}</h3>
          <p
            v-if="row.description"
            class="config-note"
          >
            {{ row.description }}
          </p>
          <ConfigValueView :value="row.value" />
          <p
            v-if="moduleCode === undefined"
            class="config-note"
          >
            {{ row.isPublic ? $t('configuration.public') : $t('configuration.private') }}
          </p>
          <div class="config-actions">
            <AppButton
              variant="secondary"
              :loading="detailBusy && key === row.key"
              :disabled="busy || detailBusy || !ready || uncertain"
              @click="edit(row)"
            >
              {{ canManage ? $t('configuration.edit') : $t('configuration.details') }}
            </AppButton>
            <AppButton
              v-if="canManage"
              variant="danger"
              :disabled="busy || detailBusy || !ready || uncertain"
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
          ? $t('configuration.deleteSetting')
          : selected
            ? $t('configuration.editSetting')
            : $t('configuration.addSetting')
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
          {{ $t('configuration.deleteHint', { key }) }}
        </p>
        <AppInput
          id="config-key"
          v-model="key"
          :label="$t('configuration.key')"
          :disabled="busy || !!selected || !canManage || uncertain"
          :error="key && invalidKey ? $t('configuration.keyInvalid') : undefined"
          :maxlength="160"
          required
        />
        <template v-if="!deleting">
          <ConfigValueEditor
            v-model="draft"
            :disabled="busy || !canManage || uncertain"
          />
          <AppInput
            id="config-description"
            v-model="description"
            :label="$t('configuration.description')"
            :maxlength="1000"
            :disabled="busy || !canManage || uncertain"
          />
          <AppSwitch
            v-if="moduleCode === undefined"
            id="config-public"
            v-model="isPublic"
            :label="$t('configuration.publish')"
            :description="$t('configuration.publishHint')"
            :disabled="busy || !canManage || uncertain"
          />
        </template>
        <AppCheckbox
          v-if="canManage && (deleting || isPublic)"
          id="config-ack"
          v-model="ack"
          :label="$t(deleting ? 'configuration.deleteAck' : 'configuration.publishAck')"
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
            :disabled="!ready || uncertain || invalidKey || ((deleting || isPublic) && !ack)"
          >
            {{ deleting ? $t('configuration.delete') : $t('configuration.save') }}
          </AppButton>
        </div>
      </form>
    </AppDialog>
  </section>
</template>
