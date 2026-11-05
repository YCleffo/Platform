<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiError, ApiResponseError } from '@/shared/api/errors';
import { coreAdminSession } from '@/core/admin/session';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppCheckbox from '@/shared/ui/fields/AppCheckbox.vue';
import TypedFields from './TypedFields.vue';
import RecordView from './RecordView.vue';
import {
  initialData,
  serialize,
  operationPath,
  FormValidationError,
  type Operation,
  type FormData,
} from './form';
const props = defineProps<{
  operation: Operation | null;
  row: Record<string, unknown>;
  sessionKey?: number | string;
  run?: (
    op: Operation,
    path: string,
    body: Record<string, unknown>,
    signal: AbortSignal,
  ) => Promise<unknown>;
}>();
const emit = defineEmits<{ done: [] }>();
const open = defineModel<boolean>({ required: true });
const { t } = useI18n();
const data = ref<FormData>({});
const error = ref('');
const fieldError = ref('');
const busy = ref(false);
const preparing = ref(false);
const ack = ref(false);
const result = ref<unknown>(null);
const resultOpen = ref(false);
const unavailable = ref(false);
const stepUp = ref(false);
let generation = 0;
let controller: AbortController | undefined;
const uncertain = ref(false);
const reviewed = ref(false);
const mutation = computed(() => props.operation?.method !== 'GET');
function clear(): void {
  generation++;
  controller?.abort();
  data.value = {};
  result.value = null;
  resultOpen.value = false;
  error.value = '';
  busy.value = false;
  preparing.value = false;
  unavailable.value = false;
  stepUp.value = false;
}
async function prepare(): Promise<void> {
  const op = props.operation;
  if (!op || !open.value) return;
  clear();
  const version = generation;
  const epoch = coreAdminSession.epoch();
  data.value = initialData(op.fields, props.row);
  ack.value = false;
  fieldError.value = '';
  reviewed.value = false;
  if (!op.prepare) return;
  preparing.value = true;
  controller = new AbortController();
  const timer = setTimeout(() => controller?.abort(), 10000);
  try {
    const path = op.prepare.replace(/\{(\w+)\}/g, (_, key: string) =>
      encodeURIComponent(String(props.row[key] ?? '')),
    );
    const record = await coreAdminHttp.get<Record<string, unknown>>(path, controller.signal);
    if (version !== generation || epoch !== coreAdminSession.epoch()) return;
    if (!record || typeof record !== 'object' || Array.isArray(record))
      throw new ApiResponseError(path);
    data.value = initialData(op.fields, { ...props.row, ...record });
  } catch (e) {
    if (version === generation) {
      error.value = resolveErrorMessage(e);
      unavailable.value = true;
    }
  } finally {
    clearTimeout(timer);
    if (version === generation) preparing.value = false;
  }
}
async function submit(): Promise<void> {
  const op = props.operation;
  if (
    !op ||
    busy.value ||
    preparing.value ||
    unavailable.value ||
    (op.danger && !ack.value) ||
    (uncertain.value && !reviewed.value)
  )
    return;
  let body: Record<string, unknown>;
  let path: string;
  error.value = '';
  fieldError.value = '';
  try {
    body = serialize(op.fields, data.value);
    path = operationPath(op, props.row, data.value);
    if (op.key === 'account.create' && body.accountType === 'human' && !body.personId)
      throw new FormValidationError('personId', 'required');
    if (op.key === 'hold.create' && body.scopeType === 'subject' && !body.subjectPersonId)
      throw new FormValidationError('subjectPersonId', 'required');
    if (op.key === 'representation.create' && body.subjectPersonId === body.representativePersonId)
      throw new FormValidationError('representativePersonId', 'invalid');
  } catch (e) {
    if (e instanceof FormValidationError) {
      fieldError.value = e.field;
      error.value = t('workbench.invalidField');
    } else error.value = resolveErrorMessage(e);
    return;
  }
  busy.value = true;
  const version = ++generation;
  const epoch = coreAdminSession.epoch();
  controller = new AbortController();
  const timer = setTimeout(() => controller?.abort(), 20000);
  try {
    const value = props.run
      ? await props.run(op, path, body, controller.signal)
      : op.method === 'GET'
        ? await coreAdminHttp.get<unknown>(path, controller.signal)
        : op.method === 'DELETE'
          ? await coreAdminHttp.delete<unknown>(path, undefined, controller.signal)
          : op.method === 'PATCH'
            ? await coreAdminHttp.patch<unknown>(path, body, controller.signal)
            : op.method === 'PUT'
              ? await coreAdminHttp.put<unknown>(path, body, controller.signal)
              : await coreAdminHttp.post<unknown>(path, body, controller.signal);
    if (version !== generation || (!props.run && epoch !== coreAdminSession.epoch())) return;
    if (value !== undefined && (value === null || typeof value !== 'object'))
      throw new ApiResponseError(path);
    result.value = value ?? { status: 'completed' };
    open.value = false;
    resultOpen.value = true;
    uncertain.value = false;
    if (op.method !== 'GET') emit('done');
  } catch (e) {
    if (version !== generation) return;
    error.value = resolveErrorMessage(e);
    stepUp.value =
      !!props.run &&
      e instanceof ApiError &&
      ['recent_authentication_required', 'recent_mfa_required', 'mfa_enrollment_required'].includes(
        e.code,
      );
    if (e instanceof ApiError && [403, 404].includes(e.status) && !stepUp.value) {
      data.value = {};
      unavailable.value = true;
    }
    if (op.method !== 'GET' && (!(e instanceof ApiError) || e.status >= 500)) {
      uncertain.value = true;
      reviewed.value = false;
    }
  } finally {
    clearTimeout(timer);
    if (version === generation) busy.value = false;
  }
}
watch(
  () => [open.value, props.operation, props.row],
  () => {
    if (open.value) void prepare();
    else if (!busy.value) {
      data.value = {};
      controller?.abort();
    }
  },
);
watch(
  () => props.sessionKey,
  () => {
    clear();
    open.value = false;
    uncertain.value = false;
  },
);
watch(resultOpen, (value) => {
  if (!value) result.value = null;
});
onBeforeUnmount(clear);
</script>
<template>
  <AppDialog
    v-model="open"
    :title="operation ? t(operation.label) : t('admin.actions')"
    :persistent="busy"
  >
    <div class="wb-operation">
      <slot
        v-if="stepUp"
        name="authorization"
      />
      <form
        class="wb-operation"
        @submit.prevent="submit"
      >
        <p
          class="wb-operation__target"
          v-if="row.name || row.displayName || row.label || row.id"
        >
          {{ row.name || row.displayName || row.label || row.id }}
        </p>
        <p
          v-if="preparing"
          role="status"
        >
          {{ t('common.loading') }}
        </p>
        <TypedFields
          v-if="operation && !unavailable"
          v-model="data"
          :fields="operation.fields"
          :row="row"
          :disabled="busy || preparing"
          :error-field="fieldError"
        />
        <p
          v-if="error"
          role="alert"
          class="wb-operation__error"
        >
          {{ error }}
        </p>
        <p
          v-if="uncertain"
          role="alert"
        >
          {{ t('workbench.uncertain') }}
        </p>
        <AppCheckbox
          v-if="uncertain"
          id="wb-reviewed"
          v-model="reviewed"
          :label="t('workbench.reviewed')"
          :disabled="busy"
        />
        <AppButton
          v-if="unavailable && operation?.prepare"
          type="button"
          variant="secondary"
          @click="prepare"
        >
          {{ t('common.retry') }}
        </AppButton>
        <AppCheckbox
          v-if="operation?.danger"
          id="wb-ack"
          v-model="ack"
          :label="t('workbench.confirmAction')"
          :disabled="busy"
        />
        <div class="wb-operation__actions">
          <AppButton
            type="button"
            variant="secondary"
            :disabled="busy"
            @click="open = false"
          >
            {{ t('common.cancel') }}
          </AppButton>
          <AppButton
            type="submit"
            :variant="operation?.danger ? 'danger' : 'primary'"
            :loading="busy"
            :disabled="
              preparing || unavailable || (operation?.danger && !ack) || (uncertain && !reviewed)
            "
          >
            {{ t(mutation ? 'common.save' : 'workbench.open') }}
          </AppButton>
        </div>
      </form>
    </div>
  </AppDialog>
  <AppDialog
    v-model="resultOpen"
    :title="t('admin.response')"
    size="full"
  >
    <div class="wb-operation">
      <p
        v-if="mutation"
        role="status"
      >
        {{ t('workbench.completed') }}
      </p>
      <p
        v-if="result && typeof result === 'object' && 'secret' in result"
        role="alert"
      >
        {{ t('workbench.secretOnce') }}
      </p>
      <RecordView :data="result" />
    </div>
  </AppDialog>
</template>
<style scoped>
.wb-operation {
  display: grid;
  gap: var(--space-16);
  min-width: 0;
}
.wb-operation__target {
  overflow-wrap: anywhere;
  color: var(--text-2);
}
.wb-operation__actions {
  display: flex;
  justify-content: end;
  flex-wrap: wrap;
  gap: var(--space-8);
}
.wb-operation__error {
  color: var(--danger);
}
</style>
