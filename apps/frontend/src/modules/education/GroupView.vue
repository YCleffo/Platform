<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import { educationApi, type Student } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationError, useEducationMutation, useEducationResource } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
const route = useRoute();
const workspace = useEducationWorkspace();
const { t, students } = useEducationI18n();
const issue = useEducationError();
const id = computed(() => String(route.params.id ?? ''));
const offset = ref(0);
const { data, loading, error, refresh } = useEducationResource(
  async (org, signal) => {
    const group = await educationApi.group(org, id.value, signal);
    const [roster, groups] = await Promise.all([
      educationApi.groupStudents(org, id.value, offset.value, signal),
      educationApi.groups(org, 0, signal),
    ]);
    return { group, roster, groups };
  },
  [id, offset],
);
const name = ref('');
watch(
  () => data.value?.group.name,
  (value) => {
    if (value) name.value = value;
  },
  { immediate: true },
);
const archived = computed(() => !!data.value?.group.archivedAt);
const transferTargets = computed(() =>
  (data.value?.groups.items ?? []).filter((item) => item.id !== id.value && !item.archivedAt),
);
const mutation = useEducationMutation();
const { busy, error: actionError, uncertain } = mutation;
const archiveOpen = ref(false);
const removeOpen = ref(false);
const transferOpen = ref(false);
const selected = ref<Student | null>(null);
const targetGroupId = ref('');
async function saveName(): Promise<void> {
  await mutation.run(
    () => educationApi.updateGroup(workspace.organizationId, id.value, name.value),
    async () => {
      await refresh();
      await workspace.refreshHeader();
    },
  );
}
async function archive(): Promise<void> {
  await mutation.run(
    () => educationApi.archiveGroup(workspace.organizationId, id.value),
    async () => {
      archiveOpen.value = false;
      await refresh();
    },
  );
}
function askRemove(student: Student): void {
  selected.value = student;
  removeOpen.value = true;
}
async function remove(): Promise<void> {
  const student = selected.value;
  if (!student) return;
  await mutation.run(
    () => educationApi.removeGroupStudent(workspace.organizationId, id.value, student.id),
    async () => {
      removeOpen.value = false;
      selected.value = null;
      await refresh();
    },
  );
}
function askTransfer(student: Student): void {
  selected.value = student;
  targetGroupId.value = transferTargets.value[0]?.id ?? '';
  transferOpen.value = true;
}
async function transfer(): Promise<void> {
  const student = selected.value;
  if (!student || !targetGroupId.value) return;
  await mutation.run(
    () =>
      educationApi.transferStudent(
        workspace.organizationId,
        id.value,
        student.id,
        targetGroupId.value,
      ),
    async () => {
      transferOpen.value = false;
      selected.value = null;
      await refresh();
    },
  );
}
function closeDialogs(): void {
  if (busy.value) return;
  removeOpen.value = false;
  transferOpen.value = false;
  selected.value = null;
  actionError.value = null;
}
async function reconcile(): Promise<void> {
  await refresh();
  if (!error.value) {
    uncertain.value = false;
    actionError.value = null;
  }
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ data?.group.name ?? t('education.group') }}</h1>
      <RouterLink
        to="/people?tab=groups"
        class="edu-link"
      >
        {{ t('education.groups') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data">
      <p
        v-if="archived"
        class="edu-state edu-state--archived"
      >
        {{ t('education.groupArchived') }}
      </p>
      <article class="edu-card">
        <p class="edu-subtle">
          {{ students(data.group.studentCount ?? data.roster.items.length) }}
        </p>
        <form
          v-if="!archived"
          class="edu-form"
          @submit.prevent="saveName"
        >
          <AppInput
            id="education-group-name"
            v-model="name"
            :label="t('education.groupName')"
            :maxlength="160"
            required
            :disabled="busy || uncertain"
          />
          <div class="edu-form-actions">
            <button
              type="submit"
              class="edu-button edu-button--primary"
              :disabled="busy || uncertain"
            >
              {{ t('education.save') }}
            </button>
            <button
              type="button"
              class="edu-button"
              :disabled="busy || uncertain"
              @click="archiveOpen = true"
            >
              {{ t('education.archive') }}
            </button>
          </div>
        </form>
      </article>
      <div class="edu-section-title">
        <h2>{{ t('education.groupStudents') }}</h2>
        <div class="edu-form-actions">
          <RouterLink
            :to="`/groups/${data.group.id}/journal`"
            class="edu-button"
          >
            {{ t('education.openJournal') }}
          </RouterLink>
          <RouterLink
            :to="`/groups/${data.group.id}/activities`"
            class="edu-button"
          >
            {{ t('education.activities') }}
          </RouterLink>
          <RouterLink
            v-if="!archived"
            :to="`/students/new?group=${data.group.id}`"
            class="edu-button"
          >
            {{ t('education.addStudentToGroup') }}
          </RouterLink>
        </div>
      </div>
      <EducationState
        v-if="!data.roster.items.length"
        :empty="t('education.noGroupStudents')"
      />
      <p
        v-if="actionError && !removeOpen && !transferOpen"
        class="edu-error"
        role="alert"
      >
        {{ uncertain ? t('education.uncertain') : issue(actionError) }}
      </p>
      <button
        v-if="uncertain && !removeOpen && !transferOpen"
        type="button"
        class="edu-button"
        @click="reconcile"
      >
        {{ t('education.checkList') }}
      </button>
      <ul class="edu-list">
        <li
          v-for="student in data.roster.items"
          :key="student.id"
          class="edu-list__item"
          :data-student-id="student.id"
        >
          <RouterLink
            :to="`/students/${student.id}`"
            class="edu-list__main"
          >
            <strong>{{ student.name }}</strong>
          </RouterLink>
          <div
            v-if="!archived"
            class="edu-form-actions"
          >
            <button
              type="button"
              class="edu-button"
              :disabled="busy || uncertain"
              @click="askTransfer(student)"
            >
              {{ t('education.transfer') }}
            </button>
            <button
              type="button"
              class="edu-button"
              :disabled="busy || uncertain"
              @click="askRemove(student)"
            >
              {{ t('education.removeFromGroup') }}
            </button>
          </div>
        </li>
      </ul>
      <div class="edu-form-actions">
        <button
          v-if="offset"
          type="button"
          class="edu-button"
          @click="offset = Math.max(0, offset - 50)"
        >
          {{ t('education.back') }}
        </button>
        <button
          v-if="data.roster.nextOffset !== null"
          type="button"
          class="edu-button"
          @click="offset = data.roster.nextOffset ?? 0"
        >
          {{ t('education.loadMore') }}
        </button>
      </div>
    </template>
    <AppConfirmDialog
      v-model="archiveOpen"
      :title="t('education.archive')"
      :description="t('education.archiveGroupConfirm')"
      :confirm-label="t('education.archive')"
      :cancel-label="t('education.cancel')"
      :busy="busy"
      danger
      @confirm="archive"
    />
    <AppConfirmDialog
      v-model="removeOpen"
      :title="t('education.removeFromGroup')"
      :description="t('education.removeStudentConfirm')"
      :confirm-label="t('education.removeFromGroup')"
      :cancel-label="t('education.cancel')"
      :busy="busy"
      danger
      @confirm="remove"
    >
      <p
        v-if="actionError"
        class="edu-error"
        role="alert"
      >
        {{ uncertain ? t('education.uncertain') : issue(actionError) }}
      </p>
    </AppConfirmDialog>
    <AppDialog
      v-model="transferOpen"
      :title="t('education.transferStudent')"
      :description="selected?.name"
      size="s"
      :persistent="busy"
      @update:model-value="
        (value) => {
          if (!value) closeDialogs();
        }
      "
    >
      <EducationState
        v-if="!transferTargets.length"
        :empty="t('education.noTransferTargets')"
      />
      <form
        v-else
        class="edu-form"
        @submit.prevent="transfer"
      >
        <AppDropdown
          id="education-transfer-target"
          v-model="targetGroupId"
          :label="t('education.transferTo')"
          :options="transferTargets.map((group) => ({ value: group.id, label: group.name }))"
          :disabled="busy || uncertain"
        />
        <p
          v-if="actionError"
          class="edu-error"
          role="alert"
        >
          {{ uncertain ? t('education.uncertain') : issue(actionError) }}
        </p>
      </form>
      <template #footer>
        <div class="edu-form-actions">
          <AppButton
            variant="secondary"
            :disabled="busy"
            @click="closeDialogs"
          >
            {{ t('education.cancel') }}
          </AppButton>
          <AppButton
            :loading="busy"
            :disabled="!transferTargets.length || !targetGroupId || uncertain"
            @click="transfer"
          >
            {{ t('education.transfer') }}
          </AppButton>
        </div>
      </template>
    </AppDialog>
  </section>
</template>
