<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import { validDate } from '@/shared/formatting/temporal';
import { zonedDateTimeToIso } from '@/shared/formatting/calendar';
import { educationApi, type Page, type Group, type PersonChoice } from './api';
import { useEducationWorkspace } from './workspace';
import { useEducationMutation, useEducationResource, useEducationError } from './resources';
import { useEducationI18n } from './i18n';
import EducationState from './EducationState.vue';
const props = defineProps<{
  kind: 'lesson' | 'event' | 'group' | 'student' | 'material' | 'message';
}>();
const route = useRoute();
const router = useRouter();
const workspace = useEducationWorkspace();
const { t } = useEducationI18n();
const issue = useEducationError();
const titles = {
  lesson: 'createLesson',
  event: 'createEvent',
  group: 'createGroup',
  student: 'addStudent',
  material: 'uploadMaterials',
  message: 'writeMessage',
};
const title = computed(() => t(`education.${titles[props.kind]}`));
const name = ref('');
const initialGroupId = typeof route.query.group === 'string' ? route.query.group : '';
const groupId = ref(initialGroupId);
const personId = ref('');
const studentMode = ref('new'); const givenName = ref(''); const familyName = ref(''); const middleName = ref(''); const birthDate = ref(''); const curriculumLessonId = ref('');
const recipientId = ref('');
const body = ref('');
const location = ref('');
const eventKind = ref('meeting');
const file = ref<File | null>(null);
const selectedDate =
  typeof route.query.date === 'string' && validDate(route.query.date)
    ? route.query.date
    : workspace.header!.date;
const startsAt = ref(`${selectedDate}T10:00`);
const endsAt = ref(`${selectedDate}T11:00`);
const localError = ref('');
const choices = useEducationResource(async (org, signal) => {
  const [groups, selectedGroup, candidates, colleagues] = await Promise.all([
    ['lesson', 'student'].includes(props.kind) ? educationApi.groups(org, 0, signal) : null,
    ['lesson', 'student'].includes(props.kind) && initialGroupId ? educationApi.group(org, initialGroupId, signal).catch(() => null) : null,
    props.kind === 'student' ? educationApi.candidates(org, 0, signal) : null,
    props.kind === 'message' ? educationApi.colleagues(org, 0, signal) : null,
  ]);
  return { groups, selectedGroup, candidates, colleagues };
});
const curriculum = useEducationResource((org, signal) => props.kind === 'lesson' && groupId.value ? educationApi.groupPrograms(org, groupId.value, signal) : Promise.resolve({ items: [] }), [groupId]);
const curriculumOptions = computed(() => [{ value: '', label: t('education.noCurriculumLesson') }, ...(curriculum.data.value?.items ?? []).filter(p => !p.endedAt).flatMap(p => p.modules.flatMap(m => m.lessons.map(l => ({ value: l.id, label: `${p.name} / ${m.name} / ${l.title}` }))))]);
watch(groupId, () => { curriculumLessonId.value = ''; });
const { data, loading, error, refresh } = choices;
const groupOptions = computed(() => {
  const items = (data.value?.groups?.items ?? []).filter((item) => !item.archivedAt);
  const selected = data.value?.selectedGroup;
  return selected && !selected.archivedAt && !items.some((item) => item.id === selected.id)
    ? [selected, ...items]
    : items;
});
watch(
  () => data.value?.groups,
  (page) => {
    if (!page || initialGroupId === '' || data.value?.selectedGroup !== null) return;
    if (!page.items.some((item) => item.id === initialGroupId && !item.archivedAt))
      groupId.value = '';
  },
  { immediate: true },
);
const mutation = useEducationMutation();
const { busy, error: actionError, uncertain } = mutation;
const listPath = computed(() =>
  props.kind === 'group'
    ? '/people?tab=groups'
    : props.kind === 'student'
      ? '/people'
      : props.kind === 'message'
        ? '/messages'
        : props.kind === 'material'
          ? '/materials'
          : `/calendar?date=${selectedDate}`,
);
const moreBusy = ref(false);
const moreError = ref<unknown>(null);
async function more(kind: 'groups' | 'candidates' | 'colleagues'): Promise<void> {
  const previous = data.value?.[kind];
  if (!previous || previous.nextOffset === null || moreBusy.value) return;
  const version = workspace.revision;
  moreBusy.value = true;
  moreError.value = null;
  try {
    const next: Page<Group> | Page<PersonChoice> =
      kind === 'groups'
        ? await educationApi.groups(workspace.organizationId, previous.nextOffset)
        : kind === 'candidates'
          ? await educationApi.candidates(workspace.organizationId, previous.nextOffset)
          : await educationApi.colleagues(workspace.organizationId, previous.nextOffset);
    if (version === workspace.revision && data.value)
      data.value = {
        ...data.value,
        [kind]: { ...next, items: [...previous.items, ...next.items] },
      };
  } catch (caught) {
    if (version === workspace.revision) moreError.value = caught;
  } finally {
    if (version === workspace.revision) moreBusy.value = false;
  }
}
async function submit(): Promise<void> {
  localError.value = '';
  const org = workspace.organizationId;
  let start = '';
  let end = '';
  if (props.kind === 'lesson' || props.kind === 'event') {
    try {
      start = zonedDateTimeToIso(startsAt.value, workspace.header!.timezone);
      end = zonedDateTimeToIso(endsAt.value, workspace.header!.timezone);
      if (Date.parse(end) <= Date.parse(start)) throw new RangeError();
    } catch {
      localError.value = t('education.invalidTime');
      return;
    }
  }
  if (props.kind === 'material' && (!file.value || file.value.size > 10485760)) {
    localError.value = t('education.fileHint');
    return;
  }
  await mutation.run(
    async () => {
      switch (props.kind) {
        case 'group':
          return educationApi.createGroup(org, name.value);
        case 'student':
          return studentMode.value === 'new' ? educationApi.createStudent(org, { givenName: givenName.value, familyName: familyName.value, ...(middleName.value ? { middleName: middleName.value } : {}), ...(birthDate.value ? { birthDate: birthDate.value } : {}), groupId: groupId.value }) : educationApi.enroll(org, personId.value, groupId.value);
        case 'lesson':
          return educationApi.createLesson(org, {
            title: name.value,
            groupId: groupId.value,
            startsAt: start,
            endsAt: end,
            location: location.value,
            curriculumLessonId: curriculumLessonId.value || null,
          });
        case 'event':
          return educationApi.createEvent(org, {
            title: name.value,
            kind: eventKind.value,
            startsAt: start,
            endsAt: end,
            location: location.value,
          });
        case 'message':
          return educationApi.startThread(org, recipientId.value, name.value, body.value);
        case 'material':
          return educationApi.upload(
            org,
            file.value!,
            typeof route.query.lesson === 'string' ? route.query.lesson : undefined,
          );
      }
    },
    async (result) => {
      if (props.kind === 'group') await router.push(`/groups/${result.id}`);
      else if (props.kind === 'student') await router.push(groupId.value ? `/groups/${groupId.value}` : `/students/${result.id}`);
      else if (props.kind === 'lesson') await router.push(`/lessons/${result.id}`);
      else if (props.kind === 'message') await router.push(`/messages/${result.id}`);
      else if (props.kind === 'material' && typeof route.query.lesson === 'string')
        await router.push(`/lessons/${route.query.lesson}/materials`);
      else await router.push(listPath.value);
      await workspace.refreshHeader();
    },
  );
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ title }}</h1>
      <RouterLink
        :to="listPath"
        class="edu-link"
      >
        {{ t('education.back') }}
      </RouterLink>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <template v-if="data && !loading && !error">
      <div
        v-if="data.groups && !data.groups.items.length"
        class="edu-state"
      >
        <p>{{ t('education.noGroups') }}</p>
        <RouterLink
          to="/groups/new"
          class="edu-button"
        >
          {{ t('education.createGroup') }}
        </RouterLink>
      </div>
      <EducationState
        v-else-if="data.colleagues && !data.colleagues.items.length"
        :empty="t('education.noRecipients')"
      />
      <form
        v-else
        class="edu-form edu-card"
        @submit.prevent="submit"
      >
        <AppInput
          v-if="kind !== 'student' && kind !== 'material'"
          id="education-create-title"
          v-model="name"
          :label="t(kind === 'message' ? 'education.subject' : 'education.name')"
          :maxlength="160"
          required
          :disabled="busy || uncertain"
        />
        <template v-if="kind === 'student'">
          <div class="edu-pills" role="group" :aria-label="t('education.addStudent')"><button type="button" :aria-pressed="studentMode === 'new'" @click="studentMode = 'new'">{{ t('education.newStudent') }}</button><button type="button" :aria-pressed="studentMode === 'existing'" @click="studentMode = 'existing'">{{ t('education.existingStudent') }}</button></div>
          <template v-if="studentMode === 'new'"><AppInput id="student-family-name" v-model="familyName" :label="t('education.familyName')" :maxlength="160" required :disabled="busy || uncertain" /><AppInput id="student-given-name" v-model="givenName" :label="t('education.givenName')" :maxlength="160" required :disabled="busy || uncertain" /><AppInput id="student-middle-name" v-model="middleName" :label="t('education.middleName')" :maxlength="160" :disabled="busy || uncertain" /><AppInput id="student-birth-date" v-model="birthDate" type="date" :label="t('education.birthDate')" :disabled="busy || uncertain" /></template>
        </template>
        <div v-if="data.groups">
          <AppDropdown
            id="education-create-group"
            v-model="groupId"
            :label="t('education.group')"
            :placeholder="t('education.chooseGroup')"
            :options="groupOptions.map((item) => ({ value: item.id, label: item.name }))"
            :disabled="busy || uncertain"
          />
          <button
            v-if="data.groups.nextOffset !== null"
            type="button"
            class="edu-link"
            :disabled="moreBusy"
            @click="more('groups')"
          >
            {{ t('education.loadMore') }}
          </button>
        </div>
        <div v-if="data.candidates && studentMode === 'existing'">
          <EducationState v-if="!data.candidates.items.length" :empty="t('education.noCandidates')" />
          <AppDropdown
            id="education-create-person"
            v-model="personId"
            :label="t('education.choosePerson')"
            :options="data.candidates.items.map((item) => ({ value: item.id, label: item.name }))"
            :disabled="busy || uncertain"
          />
          <button
            v-if="data.candidates.nextOffset !== null"
            type="button"
            class="edu-link"
            :disabled="moreBusy"
            @click="more('candidates')"
          >
            {{ t('education.loadMore') }}
          </button>
        </div>
        <div v-if="data.colleagues">
          <AppDropdown
            id="education-create-recipient"
            v-model="recipientId"
            :label="t('education.recipient')"
            :options="data.colleagues.items.map((item) => ({ value: item.id, label: item.name }))"
            :disabled="busy || uncertain"
          />
          <button
            v-if="data.colleagues.nextOffset !== null"
            type="button"
            class="edu-link"
            :disabled="moreBusy"
            @click="more('colleagues')"
          >
            {{ t('education.loadMore') }}
          </button>
        </div>
        <AppDropdown v-if="kind === 'lesson' && groupId" id="education-curriculum-lesson" v-model="curriculumLessonId" :label="t('education.curriculumLesson')" :options="curriculumOptions" :disabled="busy || uncertain || curriculum.loading.value" />
        <EducationState v-if="kind === 'lesson' && curriculum.error.value" :error="curriculum.error.value" @retry="curriculum.refresh" />
        <template v-if="kind === 'lesson' || kind === 'event'">
          <p class="edu-subtle">{{ t('education.timezone') }}: {{ workspace.header!.timezone }}</p>
          <AppInput
            id="education-starts-at"
            v-model="startsAt"
            type="datetime-local"
            :label="t('education.startsAt')"
            required
            :disabled="busy || uncertain"
          />
          <AppInput
            id="education-ends-at"
            v-model="endsAt"
            type="datetime-local"
            :label="t('education.endsAt')"
            required
            :disabled="busy || uncertain"
          />
          <AppInput
            id="education-location"
            v-model="location"
            :label="t('education.location')"
            :maxlength="240"
            :disabled="busy || uncertain"
          />
        </template>
        <AppDropdown
          v-if="kind === 'event'"
          id="education-event-kind"
          v-model="eventKind"
          :label="t('education.kind')"
          :options="
            ['meeting', 'birthday', 'other'].map((value) => ({
              value,
              label: t(`education.${value}`),
            }))
          "
          :disabled="busy || uncertain"
        />
        <label
          v-if="kind === 'message'"
          for="education-message-body"
        >
          {{ t('education.message') }}
          <textarea
            id="education-message-body"
            v-model="body"
            maxlength="5000"
            required
            :disabled="busy || uncertain"
          ></textarea>
        </label>
        <label
          v-if="kind === 'material'"
          for="education-material-file"
        >
          {{ t('education.file') }}
          <input
            id="education-material-file"
            type="file"
            class="edu-file-input"
            accept="application/pdf,text/plain,image/png,image/jpeg,image/webp"
            required
            :disabled="busy || uncertain"
            @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null"
          />
          <small>{{ t('education.fileHint') }}</small>
        </label>
        <p
          v-if="moreError"
          role="alert"
          class="edu-error"
        >
          {{ issue(moreError) }}
        </p>
        <p
          v-if="localError || actionError"
          role="alert"
          class="edu-error"
        >
          {{ localError || (uncertain ? t('education.uncertain') : issue(actionError)) }}
        </p>
        <div class="edu-form-actions">
          <button
            type="submit"
            class="edu-button edu-button--primary"
            :disabled="busy || uncertain"
          >
            {{
              t(
                busy
                  ? 'education.loading'
                  : kind === 'message'
                    ? 'education.send'
                    : 'education.save',
              )
            }}
          </button>
          <RouterLink
            v-if="uncertain"
            :to="listPath"
            class="edu-button"
          >
            {{ t('education.checkList') }}
          </RouterLink>
        </div>
      </form>
    </template>
  </section>
</template>
