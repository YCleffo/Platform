<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import {
  privacyOperator,
  allowedTransitions,
  type ContactOption,
  type OperatorAction,
  type OperatorCommand,
} from '@/shared/api/core/privacyOperator';
import type { OwnPrivacyRequest } from '@/shared/api/core/privacy';
import { resolveErrorMessage } from '@/shared/errors/humanize';
import { useAdminOperation } from '../useAdminOperation';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppCheckbox from '@/shared/ui/fields/AppCheckbox.vue';
import AppSwitch from '@/shared/ui/fields/AppSwitch.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
const props = defineProps<{
  kind: OperatorAction;
  row: OwnPrivacyRequest;
  purposes: string[];
  deadlineCode?: string;
  blockId?: string;
  busy: boolean;
  uncertain: boolean;
  error: unknown;
}>();
const open = defineModel<boolean>({ required: true });
const emit = defineEmits<{ submit: [command: OperatorCommand] }>();
const { t } = useI18n();
const auth = useAdminAuthStore();
const guard = useAdminOperation();
const ack = ref(false);
const reason = ref('');
const toStatus = ref('');
const scope = ref<'subject' | 'purpose' | 'resource'>('subject');
const purpose = ref('');
const expires = ref('');
const target = ref('core.person');
const contact = ref('');
const contacts = ref<ContactOption[]>([]);
const contactError = ref<unknown>(null);
const contactsLoading = ref(false);
interface Edit {
  field: string;
  value: string;
  clear: boolean;
  boolean: boolean;
}
const edits = ref<Edit[]>([{ field: 'givenName', value: '', clear: false, boolean: false }]);
const personFields = ['givenName', 'familyName', 'middleName', 'displayName', 'birthDate'];
const contactFields = ['value', 'label', 'isPrimary'];
const fieldNames = computed(() => (target.value === 'core.person' ? personFields : contactFields));
const resourceId = computed(() =>
  target.value === 'core.person' ? props.row.subjectPersonId : contact.value,
);
const needsReason = computed(
  () =>
    ['extend', 'block', 'release'].includes(props.kind) ||
    (props.kind === 'transition' && toStatus.value === 'cancelled'),
);
const disabled = computed(() => props.busy || props.uncertain);
const nullable = (field: string) =>
  ['middleName', 'displayName', 'birthDate', 'label'].includes(field);
const contactOptions = computed(() =>
  contacts.value.map((c) => ({
    value: c.id,
    label: c.value,
    description: c.label ?? c.contactType,
  })),
);
const targetOptions = computed(() => [
  { value: 'core.person', label: t('privacyOperator.personRecord') },
  {
    value: 'core.person_contact',
    label: t('privacyOperator.contactRecord'),
    disabled: !auth.can('core.persons.read'),
  },
]);
const transitions = computed(() =>
  allowedTransitions(props.row.status).map((value) => ({
    value,
    label: t(`corePrivacy.status_${value}`),
  })),
);
const scopes = computed(() =>
  (['subject', 'purpose', 'resource'] as const).map((value) => ({
    value,
    label: t('privacyOperator.scope_' + value),
  })),
);
const purposeOptions = computed(() => props.purposes.map((value) => ({ value, label: value })));
async function loadContacts() {
  if (!auth.can('core.persons.read')) return;
  const request = guard.begin();
  contactsLoading.value = true;
  contactError.value = null;
  try {
    const result = await privacyOperator.contacts(props.row.subjectPersonId);
    if (guard.current(request)) contacts.value = result;
  } catch (caught) {
    if (guard.current(request)) contactError.value = caught;
  } finally {
    if (guard.current(request)) contactsLoading.value = false;
  }
}
watch(target, () => {
  contact.value = '';
  edits.value = [
    {
      field: target.value === 'core.person' ? 'givenName' : 'value',
      value: '',
      clear: false,
      boolean: false,
    },
  ];
  if (target.value === 'core.person_contact') void loadContacts();
});
function addField() {
  const field = fieldNames.value.find((f) => !edits.value.some((e) => e.field === f));
  if (field) edits.value.push({ field, value: '', clear: false, boolean: false });
}
function changedField(edit: Edit) {
  edit.value = '';
  edit.clear = false;
  edit.boolean = false;
}
const valid = computed(
  () =>
    ack.value &&
    !disabled.value &&
    (!needsReason.value || reason.value.trim().length > 0) &&
    (props.kind !== 'transition' || !!toStatus.value) &&
    (props.kind !== 'block' ||
      ((scope.value !== 'purpose' || !!purpose.value) &&
        (scope.value !== 'resource' || !!resourceId.value))) &&
    (props.kind !== 'correct' ||
      (!!resourceId.value &&
        edits.value.length > 0 &&
        edits.value.every(
          (e) => e.field === 'isPrimary' || (e.clear && nullable(e.field)) || !!e.value.trim(),
        ))),
);
function submit() {
  if (!valid.value) return;
  const reasonCode = reason.value.trim();
  switch (props.kind) {
    case 'transition':
      emit('submit', {
        kind: props.kind,
        toStatus: toStatus.value,
        ...(reasonCode ? { reasonCode } : {}),
      });
      break;
    case 'extend':
      if (props.deadlineCode)
        emit('submit', { kind: props.kind, deadlineCode: props.deadlineCode, reasonCode });
      break;
    case 'confirm':
      emit('submit', { kind: props.kind });
      break;
    case 'correct':
      emit('submit', {
        kind: props.kind,
        contributorCode: target.value,
        resourceId: resourceId.value,
        changes: Object.fromEntries(
          edits.value.map((e) => [
            e.field,
            e.field === 'isPrimary'
              ? e.boolean
              : e.clear && nullable(e.field)
                ? null
                : e.value.trim(),
          ]),
        ),
      });
      break;
    case 'block':
      emit('submit', {
        kind: props.kind,
        input: {
          scopeType: scope.value,
          reasonCode,
          ...(scope.value === 'purpose' ? { purposeCode: purpose.value } : {}),
          ...(scope.value === 'resource'
            ? { resourceType: target.value, resourceId: resourceId.value }
            : {}),
          ...(expires.value ? { expiresAt: new Date(expires.value).toISOString() } : {}),
        },
      });
      break;
    case 'release':
      if (props.blockId) emit('submit', { kind: props.kind, blockId: props.blockId, reasonCode });
      break;
    case 'terminate':
    case 'response':
      emit('submit', { kind: props.kind });
      break;
  }
}
</script>
<template>
  <AppDialog
    v-model="open"
    :title="$t(`privacyOperator.action_${kind}`)"
    :persistent="busy"
    size="l"
  >
    <form
      class="core-privacy"
      @submit.prevent="submit"
    >
      <p>{{ $t(`privacyOperator.hint_${kind}`) }}</p>
      <p>{{ $t('corePrivacy.reference') }}: {{ row.id }}</p>
      <div
        v-if="kind === 'transition'"
        class="core-privacy__field"
      >
        <label for="op-next-status">{{ $t('privacyOperator.nextStatus') }}</label>
        <AppDropdown
          id="op-next-status"
          v-model="toStatus"
          :label="$t('privacyOperator.nextStatus')"
          :options="transitions"
          :disabled="disabled"
        />
      </div>
      <p v-if="kind === 'extend'">{{ $t(`corePrivacy.deadline_${deadlineCode}`) }}</p>
      <div
        v-if="kind === 'block'"
        class="core-privacy__field"
      >
        <label for="op-scope">{{ $t('privacyOperator.scope') }}</label>
        <AppDropdown
          id="op-scope"
          v-model="scope"
          :label="$t('privacyOperator.scope')"
          :options="scopes"
          :disabled="disabled"
        />
      </div>
      <div
        v-if="kind === 'block' && scope === 'purpose'"
        class="core-privacy__field"
      >
        <label for="op-purpose">{{ $t('privacyOperator.purpose') }}</label>
        <AppDropdown
          id="op-purpose"
          v-model="purpose"
          :label="$t('privacyOperator.purpose')"
          :options="purposeOptions"
          searchable
          :disabled="disabled"
        />
        <p v-if="purposes.length === 0">{{ $t('privacyOperator.noPurposes') }}</p>
      </div>
      <template v-if="kind === 'correct' || (kind === 'block' && scope === 'resource')">
        <div class="core-privacy__field">
          <label for="op-target">{{ $t('privacyOperator.target') }}</label>
          <AppDropdown
            id="op-target"
            v-model="target"
            :label="$t('privacyOperator.target')"
            :options="targetOptions"
            :disabled="disabled"
          />
        </div>
        <p v-if="target === 'core.person'">{{ $t('privacyOperator.personBound') }}</p>
        <template v-else>
          <p
            v-if="contactsLoading"
            role="status"
          >
            {{ $t('common.loading') }}
          </p>
          <p
            v-if="contactError"
            role="alert"
          >
            {{ resolveErrorMessage(contactError) }}
          </p>
          <AppButton
            v-if="contactError"
            variant="secondary"
            :disabled="contactsLoading"
            @click="loadContacts"
          >
            {{ $t('common.retry') }}
          </AppButton>
          <div class="core-privacy__field">
            <label for="op-contact">{{ $t('privacyOperator.contact') }}</label>
            <AppDropdown
              id="op-contact"
              v-model="contact"
              :label="$t('privacyOperator.contact')"
              :options="contactOptions"
              searchable
              :disabled="disabled || contactsLoading"
            />
          </div>
          <p v-if="!contactsLoading && !contactError && contacts.length === 0">
            {{ $t('privacyOperator.noContacts') }}
          </p>
        </template>
      </template>
      <template v-if="kind === 'correct'">
        <div
          v-for="(edit, index) in edits"
          :key="index"
          class="privacy-operator__edit"
        >
          <div class="core-privacy__field">
            <label :for="`op-field-${index}`">{{ $t('privacyOperator.field') }}</label>
            <AppDropdown
              :id="`op-field-${index}`"
              v-model="edit.field"
              :label="$t('privacyOperator.field')"
              :options="
                fieldNames.map((value) => ({
                  value,
                  label: t('privacyOperator.field_' + value),
                  disabled: edits.some((e, i) => i !== index && e.field === value),
                }))
              "
              :disabled="disabled"
              @update:model-value="changedField(edit)"
            />
          </div>
          <AppSwitch
            v-if="edit.field === 'isPrimary'"
            :id="`op-primary-${index}`"
            v-model="edit.boolean"
            :label="$t('privacyOperator.field_isPrimary')"
            :disabled="disabled"
          />
          <template v-else>
            <AppCheckbox
              v-if="nullable(edit.field)"
              :id="`op-clear-${index}`"
              v-model="edit.clear"
              :label="$t('privacyOperator.clearField')"
              :disabled="disabled"
            />
            <AppInput
              v-if="!edit.clear"
              :id="`op-value-${index}`"
              v-model="edit.value"
              :label="$t('privacyOperator.newValue')"
              :type="edit.field === 'birthDate' ? 'date' : 'text'"
              :maxlength="
                edit.field === 'label'
                  ? 80
                  : target === 'core.person_contact'
                    ? 320
                    : edit.field === 'displayName'
                      ? 255
                      : 120
              "
              :disabled="disabled"
              required
            />
          </template>
          <AppButton
            v-if="edits.length > 1"
            variant="secondary"
            :disabled="disabled"
            @click="edits.splice(index, 1)"
          >
            {{ $t('privacyOperator.removeField') }}
          </AppButton>
        </div>
        <AppButton
          v-if="edits.length < fieldNames.length"
          variant="secondary"
          :disabled="disabled"
          @click="addField"
        >
          {{ $t('privacyOperator.addField') }}
        </AppButton>
      </template>
      <AppInput
        v-if="needsReason"
        id="op-reason"
        v-model="reason"
        :label="$t('privacyOperator.reason')"
        :hint="$t('privacyOperator.reasonHint')"
        pattern="[a-z0-9]+([._-][a-z0-9]+)*"
        maxlength="120"
        :disabled="disabled"
        required
      />
      <AppInput
        v-if="kind === 'block'"
        id="op-expires"
        v-model="expires"
        :label="$t('privacyOperator.expires')"
        type="datetime-local"
        :disabled="disabled"
      />
      <AppCheckbox
        id="op-action-ack"
        v-model="ack"
        :label="
          $t(kind === 'confirm' ? 'privacyOperator.evidenceAck' : 'privacyOperator.actionAck')
        "
        :disabled="disabled"
      />
      <p
        v-if="error"
        role="alert"
      >
        {{ resolveErrorMessage(error) }}
      </p>
      <p
        v-if="uncertain"
        role="alert"
      >
        {{ $t('privacyOperator.uncertain') }}
      </p>
      <div class="core-privacy__actions">
        <AppButton
          type="submit"
          :variant="['terminate', 'release', 'block'].includes(kind) ? 'danger' : 'primary'"
          :loading="busy"
          :disabled="!valid"
        >
          {{ $t(`privacyOperator.action_${kind}`) }}
        </AppButton>
        <AppButton
          variant="secondary"
          :disabled="busy"
          @click="open = false"
        >
          {{ $t('common.cancel') }}
        </AppButton>
      </div>
    </form>
  </AppDialog>
</template>
<style scoped>
.privacy-operator__edit {
  display: grid;
  gap: var(--space-12);
  border: 1px solid var(--border);
  padding: var(--space-16);
  border-radius: var(--radius-control-m);
}
</style>
