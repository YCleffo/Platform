<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { coreConfiguration, type CoreModule } from '@/shared/api/core/configuration';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useAdminOperation } from './useAdminOperation';
import { useConfigCollection } from './configuration/useConfigCollection';
import ConfigSettingsPanel from './configuration/ConfigSettingsPanel.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppCheckbox from '@/shared/ui/fields/AppCheckbox.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppErrorState from '@/shared/ui/feedback/AppErrorState.vue';
import './configuration/configuration.css';
const { locale } = useI18n();
const auth = useAdminAuthStore();
const detailGuard = useAdminOperation();
const search = ref('');
const code = ref<string | null>(null);
const selected = ref<CoreModule | null>(null);
const detailBusy = ref(false);
const detailError = ref<unknown>(null);
const confirm = ref(false);
const ack = ref(false);
const collection = useConfigCollection(
  () => coreConfiguration.modules(),
  () => {
    code.value = null;
    selected.value = null;
    confirm.value = false;
    detailGuard.begin();
    detailBusy.value = false;
    detailError.value = null;
  },
);
const { items, loading, ready, error, busy, actionError, uncertain, saved } = collection;
const canRead = computed(() => auth.can('core.modules.read'));
const canManage = computed(() => auth.can('core.modules.manage'));
const filtered = computed(() =>
  items.value.filter((m) =>
    (m.code + ' ' + m.name).toLocaleLowerCase().includes(search.value.toLocaleLowerCase()),
  ),
);
async function detail(value: string) {
  const op = detailGuard.begin();
  code.value = value;
  selected.value = null;
  detailError.value = null;
  detailBusy.value = true;
  try {
    const row = await coreConfiguration.module(value);
    if (detailGuard.current(op)) selected.value = row;
  } catch (e) {
    if (detailGuard.current(op)) detailError.value = e;
  } finally {
    if (detailGuard.current(op)) detailBusy.value = false;
  }
}
function back() {
  detailGuard.begin();
  code.value = null;
  selected.value = null;
  detailBusy.value = false;
  detailError.value = null;
  confirm.value = false;
}
async function refresh() {
  await collection.load();
  if (code.value && ready.value) await detail(code.value);
}
async function toggle() {
  if (!canManage.value || !selected.value || !ack.value) return;
  const row = selected.value;
  await collection.mutate(
    () => coreConfiguration.setModule(row.code, !row.enabled),
    (changed) => {
      selected.value = changed;
      confirm.value = false;
    },
  );
}
watch(
  canRead,
  (value) => {
    if (value) void collection.load();
    else {
      back();
      items.value = [];
      ready.value = false;
    }
  },
  { immediate: true },
);
</script>
<template>
  <section class="config-page">
    <header class="config-head">
      <h2>{{ selected?.name || $t('admin.modules') }}</h2>
      <div class="config-actions">
        <AppButton
          v-if="code"
          variant="secondary"
          :disabled="busy"
          @click="back"
        >
          {{ $t('configuration.backModules') }}
        </AppButton>
        <AppButton
          v-if="canRead"
          variant="secondary"
          :loading="loading || detailBusy"
          :disabled="busy"
          @click="refresh"
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
      <template v-if="code">
        <p
          v-if="detailBusy"
          role="status"
        >
          {{ $t('common.loading') }}
        </p>
        <template v-if="selected">
          <article class="config-card">
            <p
              v-if="selected.description"
              class="config-note"
            >
              {{ selected.description }}
            </p>
            <dl class="config-meta">
              <div>
                <dt>{{ $t('configuration.code') }}</dt>
                <dd>{{ selected.code }}</dd>
              </div>
              <div>
                <dt>{{ $t('configuration.version') }}</dt>
                <dd>{{ selected.version }}</dd>
              </div>
              <div>
                <dt>{{ $t('configuration.schemaVersion') }}</dt>
                <dd>{{ selected.settingsSchemaVersion }}</dd>
              </div>
              <div>
                <dt>{{ $t('configuration.state') }}</dt>
                <dd>
                  {{ $t(selected.enabled ? 'configuration.enabled' : 'configuration.disabled') }}
                </dd>
              </div>
              <div>
                <dt>{{ $t('configuration.installedAt') }}</dt>
                <dd>{{ new Date(selected.installedAt).toLocaleString(locale) }}</dd>
              </div>
            </dl>
            <AppButton
              v-if="canManage"
              :variant="selected.enabled ? 'danger' : 'primary'"
              :disabled="!ready || busy || uncertain"
              @click="
                ack = false;
                actionError = null;
                confirm = true;
              "
            >
              {{
                $t(selected.enabled ? 'configuration.disableModule' : 'configuration.enableModule')
              }}
            </AppButton>
          </article>
          <ConfigSettingsPanel
            :key="selected.code"
            :module-code="selected.code"
          />
        </template>
      </template>
      <template v-else>
        <AppInput
          id="config-module-search"
          v-model="search"
          class="config-search"
          :label="$t('configuration.searchModules')"
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
          {{ $t('configuration.emptyModules') }}
        </p>
        <div class="config-grid">
          <article
            v-for="row in filtered"
            :key="row.code"
            class="config-card"
          >
            <h3>{{ row.name }}</h3>
            <p class="config-note">{{ row.code }} · {{ row.version }}</p>
            <p>{{ $t(row.enabled ? 'configuration.enabled' : 'configuration.disabled') }}</p>
            <AppButton
              variant="secondary"
              :disabled="!ready || busy"
              @click="detail(row.code)"
            >
              {{ $t('configuration.openModule') }}
            </AppButton>
          </article>
        </div>
      </template>
    </template>
    <AppDialog
      v-model="confirm"
      :title="$t(selected?.enabled ? 'configuration.disableModule' : 'configuration.enableModule')"
      :persistent="busy"
    >
      <form
        class="config-form"
        @submit.prevent="toggle"
      >
        <p>{{ $t('configuration.moduleChangeHint', { name: selected?.name }) }}</p>
        <AppCheckbox
          id="config-module-ack"
          v-model="ack"
          :label="$t('configuration.changeAck')"
          :disabled="busy || uncertain"
        />
        <p
          v-if="actionError"
          role="alert"
          class="config-error"
        >
          {{ resolveErrorMessage(actionError) }}
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
            @click="confirm = false"
          >
            {{ $t('common.cancel') }}
          </AppButton>
          <AppButton
            type="submit"
            :variant="selected?.enabled ? 'danger' : 'primary'"
            :loading="busy"
            :disabled="!ack || !ready || uncertain"
          >
            {{ $t('configuration.confirm') }}
          </AppButton>
        </div>
      </form>
    </AppDialog>
  </section>
</template>
