<script setup lang="ts">
import { computed, onScopeDispose, ref, watch } from 'vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import { coreAdminSession } from '@/core/admin/session';
import { useEducationResource, useEducationError } from '../resources';
import { useEducationWorkspace } from '../workspace';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
import { parentApi, type ParentInvitation, type Relationship } from './api';
import type { PersonChoice } from '../api';
import './parent.css';
const props = defineProps<{ studentId: string }>();
const workspace = useEducationWorkspace();
const { t, locale } = useEducationI18n();
const issue = useEducationError();
const historyOffset = ref(0),
  query = ref(''),
  person = ref(''),
  relationship = ref<Relationship>('guardian');
const candidates = ref<PersonChoice[]>([]),
  busy = ref(false),
  searching = ref(false),
  actionError = ref('');
const invitation = ref<ParentInvitation | null>(null),
  pendingRevoke = ref('');
const { data, loading, error, refresh } = useEducationResource(
  async (org, signal) => {
    const [family, history] = await Promise.all([
      parentApi.guardians(org, props.studentId, signal),
      parentApi.history(org, props.studentId, historyOffset.value, signal),
    ]);
    return { ...family, history };
  },
  [() => props.studentId, historyOffset],
);
const relations = computed(() =>
  (['mother', 'father', 'guardian'] as const).map((value) => ({
    value,
    label: t(`education.${value}`),
  })),
);
const people = computed(() =>
  candidates.value.map((item) => ({ value: item.id, label: item.name })),
);
const changes = ref<Record<string, Relationship>>({});
let controller: AbortController | null = null;
let searchController: AbortController | null = null;
let active = true;
function clear(): void {
  controller?.abort();
  searchController?.abort();
  invitation.value = null;
  candidates.value = [];
  person.value = '';
  actionError.value = '';
  pendingRevoke.value = '';
  changes.value = {};
  busy.value = false;
  searching.value = false;
}
watch([() => workspace.organizationId, () => workspace.revision, () => props.studentId], clear, {
  flush: 'sync',
});
async function search(): Promise<void> {
  searchController?.abort();
  const request = new AbortController();
  searchController = request;
  const org = workspace.organizationId;
  const epoch = coreAdminSession.epoch();
  const revision = workspace.revision;
  searching.value = true;
  candidates.value = [];
  person.value = '';
  actionError.value = '';
  try {
    const result = await parentApi.candidates(org, query.value.trim(), request.signal);
    if (
      active &&
      !request.signal.aborted &&
      epoch === coreAdminSession.epoch() &&
      revision === workspace.revision
    )
      candidates.value = result.items;
  } catch (failure) {
    if (active && !request.signal.aborted && epoch === coreAdminSession.epoch())
      actionError.value = issue(failure);
  } finally {
    if (active && !request.signal.aborted) searching.value = false;
  }
}
async function mutate(
  action: (org: string, student: string, signal: AbortSignal) => Promise<unknown>,
): Promise<void> {
  if (busy.value) return;
  controller?.abort();
  const request = new AbortController();
  controller = request;
  const org = workspace.organizationId;
  const student = props.studentId;
  const revision = workspace.revision;
  const epoch = coreAdminSession.epoch();
  busy.value = true;
  actionError.value = '';
  invitation.value = null;
  const current = () =>
    active &&
    !request.signal.aborted &&
    revision === workspace.revision &&
    epoch === coreAdminSession.epoch() &&
    student === props.studentId;
  try {
    const result = await action(org, student, request.signal);
    if (!current()) return;
    if (typeof result === 'object' && result !== null && 'code' in result)
      invitation.value = result as ParentInvitation;
    pendingRevoke.value = '';
    await refresh();
  } catch (failure) {
    if (current()) actionError.value = issue(failure);
  } finally {
    if (current()) busy.value = false;
  }
}
function add(): void {
  if (person.value)
    void mutate((org, student, signal) =>
      parentApi.addGuardian(org, student, person.value, relationship.value, signal),
    );
}
function action(
  personId: string,
  operation: 'confirm' | 'revoke' | 'invite' | 'invite/revoke',
): void {
  void mutate((org, student, signal) =>
    operation === 'invite'
      ? parentApi.invite(org, student, personId, signal)
      : parentApi.familyAction(org, student, personId, operation, signal),
  );
}
function update(personId: string, original: Relationship): void {
  void mutate((org, student, signal) =>
    parentApi.relationship(org, student, personId, changes.value[personId] ?? original, signal),
  );
}
const when = (value: string) =>
  new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: workspace.header?.timezone ?? 'UTC',
  }).format(new Date(value));
onScopeDispose(() => {
  active = false;
  clear();
});
</script>
<template>
  <article class="edu-card edu-family">
    <header class="edu-page-title">
      <h2>{{ t('education.guardians') }}</h2>
      <button
        type="button"
        class="edu-link"
        :disabled="loading || busy"
        @click="refresh"
      >
        {{ t('education.refresh') }}
      </button>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <p
      v-if="actionError"
      role="alert"
      class="edu-error"
    >
      {{ actionError }}
    </p>
    <template v-if="data">
      <EducationState
        v-if="!data.items.length"
        :empty="t('education.familyEmpty')"
      />
      <ul
        v-else
        class="edu-list"
      >
        <li
          v-for="guardian in data.items"
          :key="guardian.personId"
          class="edu-list__item"
        >
          <div class="edu-family-row">
            <div>
              <strong>{{ guardian.name }}</strong>
              <p>
                {{ t(`education.${guardian.relationship}`) }} ·
                {{ t(`education.family${guardian.status}`) }}
              </p>
              <p class="edu-subtle">
                {{ t(guardian.account ? 'education.accountActive' : 'education.accountPending') }}
              </p>
              <p
                v-if="guardian.invitation"
                class="edu-subtle"
              >
                {{
                  t(
                    guardian.invitation.revokedAt
                      ? 'education.familyInviteRevoked'
                      : guardian.invitation.usedAt
                        ? 'education.familyInviteUsed'
                        : 'education.familyInviteExpires',
                  )
                }}
                <span v-if="!guardian.invitation.usedAt && !guardian.invitation.revokedAt">
                  : {{ when(guardian.invitation.expiresAt) }}
                </span>
              </p>
            </div>
            <div
              v-if="data.canManage"
              class="edu-form-actions"
            >
              <AppDropdown
                :id="`family-relationship-${guardian.personId}`"
                :label="t('education.familyRelationship')"
                :model-value="changes[guardian.personId] ?? guardian.relationship"
                :options="relations"
                :disabled="busy"
                @update:model-value="
                  (value) => (changes[guardian.personId] = String(value) as Relationship)
                "
              />
              <button
                type="button"
                class="edu-button"
                :disabled="
                  busy ||
                  !changes[guardian.personId] ||
                  changes[guardian.personId] === guardian.relationship
                "
                @click="update(guardian.personId, guardian.relationship)"
              >
                {{ t('education.save') }}
              </button>
              <button
                v-if="guardian.status === 'confirmed'"
                type="button"
                class="edu-button"
                :disabled="busy"
                @click="action(guardian.personId, 'invite')"
              >
                {{ t(guardian.invitation ? 'education.familyReinvite' : 'education.familyInvite') }}
              </button>
              <button
                v-if="
                  guardian.invitation &&
                  !guardian.invitation.usedAt &&
                  !guardian.invitation.revokedAt
                "
                type="button"
                class="edu-button"
                :disabled="busy"
                @click="action(guardian.personId, 'invite/revoke')"
              >
                {{ t('education.familyRevokeInvite') }}
              </button>
              <button
                v-if="guardian.status !== 'revoked' && pendingRevoke !== guardian.personId"
                type="button"
                class="edu-button"
                :disabled="busy"
                @click="pendingRevoke = guardian.personId"
              >
                {{ t('education.familyRevoke') }}
              </button>
            </div>
            <div
              v-if="data.canConfirm && guardian.status !== 'confirmed'"
              class="edu-form-actions"
            >
              <p class="edu-subtle">{{ t('education.familyConfirmHint') }}</p>
              <button
                type="button"
                class="edu-button"
                :disabled="busy"
                @click="action(guardian.personId, 'confirm')"
              >
                {{ t('education.familyConfirm') }}
              </button>
            </div>
            <div
              v-if="pendingRevoke === guardian.personId"
              class="edu-form-actions"
              role="group"
              :aria-label="t('education.familyRevoke')"
            >
              <p>{{ t('education.familyRevokeHint') }}</p>
              <button
                type="button"
                class="edu-button"
                :disabled="busy"
                @click="action(guardian.personId, 'revoke')"
              >
                {{ t('education.confirm') }}
              </button>
              <button
                type="button"
                class="edu-link"
                :disabled="busy"
                @click="pendingRevoke = ''"
              >
                {{ t('education.cancel') }}
              </button>
            </div>
          </div>
        </li>
      </ul>
      <div
        v-if="invitation"
        class="edu-family-invite"
        role="status"
      >
        <strong>{{ t('education.familyInviteReady') }}</strong>
        <p>{{ t('education.familyInviteCodeHint') }}</p>
        <p>
          <code>{{ invitation.code }}</code>
        </p>
        <p>{{ t('education.familyInviteExpires') }}: {{ when(invitation.expiresAt) }}</p>
        <p class="edu-subtle">
          {{ t('education.familyActivationAddress') }}
          <RouterLink to="/parent/activate">/parent/activate</RouterLink>
        </p>
        <button
          type="button"
          class="edu-link"
          @click="invitation = null"
        >
          {{ t('education.familyHideCode') }}
        </button>
      </div>
      <template v-if="data.canManage">
        <h3>{{ t('education.familyAdd') }}</h3>
        <form
          class="edu-form"
          @submit.prevent="search"
        >
          <div class="edu-form-actions">
            <AppInput
              id="family-person-search"
              v-model="query"
              :label="t('education.familySearch')"
              :disabled="searching || busy"
              maxlength="120"
            />
            <button
              type="submit"
              class="edu-button"
              :disabled="searching || busy"
            >
              {{ t('education.search') }}
            </button>
          </div>
        </form>
        <form
          v-if="candidates.length"
          class="edu-form"
          @submit.prevent="add"
        >
          <AppDropdown
            id="family-person"
            v-model="person"
            :label="t('education.familyPerson')"
            :options="people"
            :disabled="busy"
          />
          <AppDropdown
            id="family-new-relationship"
            v-model="relationship"
            :label="t('education.familyRelationship')"
            :options="relations"
            :disabled="busy"
          />
          <p class="edu-subtle">{{ t('education.familyPendingHint') }}</p>
          <button
            type="submit"
            class="edu-button"
            :disabled="!person || busy"
          >
            {{ t('education.familyAdd') }}
          </button>
        </form>
        <p
          v-else-if="query && !searching"
          class="edu-subtle"
        >
          {{ t('education.familyNoCandidates') }}
        </p>
      </template>
      <details>
        <summary>{{ t('education.familyHistory') }}</summary>
        <EducationState
          v-if="!data.history.items.length"
          :empty="t('education.familyNoHistory')"
        />
        <ol
          v-else
          class="edu-list edu-family-history"
        >
          <li
            v-for="entry in data.history.items"
            :key="entry.id"
          >
            <strong>{{ t(`education.familyAction_${entry.action}`) }}</strong>
            <p class="edu-subtle">
              {{ when(entry.createdAt) }} ·
              {{
                data.items.find((item) => item.personId === entry.personId)?.name ??
                t('education.familyPerson')
              }}
              · {{ t(`education.family${entry.status}`) }}
            </p>
          </li>
        </ol>
        <div class="edu-form-actions">
          <button
            v-if="historyOffset"
            type="button"
            class="edu-link"
            @click="historyOffset = Math.max(0, historyOffset - 50)"
          >
            {{ t('education.back') }}
          </button>
          <button
            v-if="data.history.nextOffset !== null"
            type="button"
            class="edu-link"
            @click="historyOffset = data.history.nextOffset ?? 0"
          >
            {{ t('education.loadMore') }}
          </button>
        </div>
      </details>
    </template>
  </article>
</template>
