<script setup lang="ts">
import { ref } from 'vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import { deviceApi, type Device, type DeviceEnrollment } from './api';
import { educationApi } from '../api';
import { useEducationWorkspace } from '../workspace';
import { useEducationResource, useEducationError } from '../resources';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';
const workspace = useEducationWorkspace();
const { t } = useEducationI18n();
const issue = useEducationError();
const offset = ref(0);
const { data, loading, error, refresh } = useEducationResource(
  async (org, signal) => {
    const [devices, classrooms] = await Promise.all([
      deviceApi.devices(org, offset.value, signal),
      deviceApi.classrooms(org, signal),
    ]);
    return { devices, classrooms };
  },
  [offset],
);
const showCreate = ref(false);
const createName = ref('');
const createNumber = ref('');
const createClassroomId = ref('');
const createError = ref('');
const enrollments = ref(new Map<string, DeviceEnrollment>());
const assignDevice = ref<Device | null>(null);
const assignStudentId = ref('');
const assignBusy = ref(false);
const assignError = ref('');
const roster = ref<{ items: { id: string; name?: string }[] } | null>(null);
async function loadRoster(): Promise<void> {
  roster.value = await educationApi.students(workspace.organizationId, 0);
}
async function create(): Promise<void> {
  createError.value = '';
  try {
    await deviceApi.createDevice(workspace.organizationId, {
      classroomId: createClassroomId.value || undefined,
      name: createName.value,
      number: createNumber.value,
    });
    showCreate.value = false;
    createName.value = '';
    createNumber.value = '';
    await refresh();
  } catch (failure) {
    createError.value = issue(failure instanceof Error ? failure : new Error(String(failure)));
  }
}
async function enroll(id: string): Promise<void> {
  const enrollment = await deviceApi.enroll(workspace.organizationId, id);
  enrollments.value.set(id, enrollment);
}
async function openAssign(device: Device): Promise<void> {
  assignDevice.value = device;
  assignStudentId.value = '';
  assignError.value = '';
  await loadRoster();
}
async function assign(): Promise<void> {
  if (!assignDevice.value || !assignStudentId.value) return;
  assignBusy.value = true;
  assignError.value = '';
  try {
    await deviceApi.startSession(
      workspace.organizationId,
      assignDevice.value.id,
      assignStudentId.value,
    );
    assignDevice.value = null;
    await refresh();
  } catch (failure) {
    assignError.value = issue(failure instanceof Error ? failure : new Error(String(failure)));
  } finally {
    assignBusy.value = false;
  }
}
async function endSession(device: Device): Promise<void> {
  const detail = await deviceApi.device(workspace.organizationId, device.id);
  const open = detail.sessions.find((session) => !['CLOSED', 'FAILED'].includes(session.status));
  if (open) {
    await deviceApi.endSession(workspace.organizationId, device.id, open.id);
    await refresh();
  }
}
</script>
<template>
  <section>
    <header class="edu-page-title">
      <h1>{{ t('education.computers') }}</h1>
      <button
        type="button"
        class="edu-button"
        @click="showCreate = !showCreate"
      >
        {{ t('education.addDevice') }}
      </button>
    </header>
    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />
    <form
      v-if="showCreate && data"
      class="edu-form edu-card"
      @submit.prevent="create"
    >
      <div class="edu-form-actions">
        <AppInput
          id="education-device-name"
          v-model="createName"
          :label="t('education.deviceName')"
          maxlength="160"
          required
        />
        <AppInput
          id="education-device-number"
          v-model="createNumber"
          :label="t('education.deviceNumber')"
          maxlength="40"
        />
        <AppDropdown
          id="education-device-classroom"
          v-model="createClassroomId"
          :label="t('education.classroom')"
          :options="[
            { value: '', label: t('education.noClassroom') },
            ...data.classrooms.items.map((classroom) => ({
              value: classroom.id,
              label: classroom.name,
            })),
          ]"
        />
        <button
          type="submit"
          class="edu-button edu-button--primary"
        >
          {{ t('education.save') }}
        </button>
      </div>
      <p
        v-if="createError"
        class="edu-error"
        role="alert"
      >
        {{ createError }}
      </p>
    </form>
    <template v-if="data">
      <EducationState
        v-if="!data.devices.items.length"
        :empty="t('education.noDevices')"
      />
      <ul
        v-else
        class="edu-list"
      >
        <li
          v-for="device in data.devices.items"
          :key="device.id"
          class="edu-list__item"
        >
          <div class="edu-list__main">
            <strong>
              {{ t('education.computerNumber', { number: device.number }) }} ·
              {{ device.name || device.number }}
            </strong>
            <p class="edu-subtle">
              {{ device.status }} · {{ t(`education.device_${device.status.toLowerCase()}`) }}
              <template v-if="device.classroomName">· {{ device.classroomName }}</template>
            </p>
            <p
              v-if="device.lastSeenAt"
              class="edu-subtle"
            >
              {{ t('education.lastSeen') }}: {{ device.lastSeenAt }}
            </p>
            <p
              v-if="enrollments.get(device.id)"
              class="edu-subtle"
            >
              {{ t('education.generatedInvite') }}:
              <strong>{{ enrollments.get(device.id)?.code }}</strong>
            </p>
            <p
              v-if="device.status === 'RECOVERY_REQUIRED' || device.status === 'ERROR'"
              class="edu-error"
            >
              {{ t('education.syncProblem') }}
            </p>
          </div>
          <div class="edu-form-actions">
            <button
              v-if="device.enrollmentState === 'pending'"
              type="button"
              class="edu-button"
              @click="enroll(device.id)"
            >
              {{ t('education.generateEnrollment') }}
            </button>
            <button
              v-if="!['OFFLINE', 'AVAILABLE', 'RECOVERY_REQUIRED', 'ERROR'].includes(device.status)"
              type="button"
              class="edu-button"
              @click="endSession(device)"
            >
              {{ t('education.endSession') }}
            </button>
            <button
              type="button"
              class="edu-button edu-button--primary"
              :disabled="device.status === 'RECOVERY_REQUIRED' || device.status === 'ERROR'"
              @click="openAssign(device)"
            >
              {{ t('education.assignStudent') }}
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
          v-if="data.devices.nextOffset !== null"
          type="button"
          class="edu-button"
          @click="offset = data.devices.nextOffset ?? 0"
        >
          {{ t('education.loadMore') }}
        </button>
      </div>
    </template>
    <div
      v-if="assignDevice"
      class="edu-card"
    >
      <h2>{{ t('education.assignStudent') }}: {{ assignDevice.number }} {{ assignDevice.name }}</h2>
      <div class="edu-form-actions">
        <AppDropdown
          id="education-assign-student"
          v-model="assignStudentId"
          :label="t('education.students')"
          :options="
            (roster?.items ?? []).map((student) => ({
              value: student.id,
              label: student.name ?? student.id,
            }))
          "
        />
        <button
          type="button"
          class="edu-button edu-button--primary"
          :disabled="assignBusy || !assignStudentId"
          @click="assign"
        >
          {{ t('education.startPreparation') }}
        </button>
        <button
          type="button"
          class="edu-button"
          @click="assignDevice = null"
        >
          {{ t('education.cancel') }}
        </button>
      </div>
      <p
        v-if="assignError"
        class="edu-error"
        role="alert"
      >
        {{ assignError }}
      </p>
    </div>
  </section>
</template>
