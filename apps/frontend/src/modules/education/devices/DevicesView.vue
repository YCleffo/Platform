<script setup lang="ts">
import { computed, onScopeDispose, ref, watch } from 'vue';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import DeviceDetailsPanel from './DeviceDetailsPanel.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import {
  deviceApi,
  canDevice,
  type DeviceDetail,
  type DeviceAction,
  type Device,
  type DeviceClassroom,
  type DeviceEnrollment,
  type DeviceSession,
  type DeviceStatus,
} from './api';
import { educationApi } from '../api';
import { useEducationWorkspace } from '../workspace';
import { useEducationResource, useEducationError } from '../resources';
import { useEducationI18n } from '../i18n';
import EducationState from '../EducationState.vue';

const workspace = useEducationWorkspace();
const { t, locale } = useEducationI18n();
const issue = useEducationError();

const offset = ref(0);
const selectedClassroomId = ref('');
const selectedStatus = ref('');
const searchQuery = ref('');

const { data, loading, error, refresh } = useEducationResource(
  async (org, signal) => {
    const [devices, classrooms] = await Promise.all([
      deviceApi.devices(
        org,
        offset.value,
        selectedClassroomId.value || undefined,
        selectedStatus.value || undefined,
        searchQuery.value || undefined,
        signal,
      ),
      deviceApi.allClassrooms(org, signal),
    ]);
    return { devices, classrooms };
  },
  [offset, selectedClassroomId, selectedStatus],
);

const classroomOffset = ref(0),
  classroomSearch = ref(''),
  showArchived = ref(false);
const classroomResource = useEducationResource(
  (org, signal) =>
    deviceApi.classrooms(
      org,
      signal,
      classroomOffset.value,
      classroomSearch.value,
      showArchived.value,
    ),
  [classroomOffset, classroomSearch, showArchived],
);
watch([classroomSearch, showArchived], () => {
  classroomOffset.value = 0;
});
async function refreshClassrooms(): Promise<void> {
  await Promise.all([refresh(), classroomResource.refresh()]);
}
async function restoreClassroom(classroom: DeviceClassroom): Promise<void> {
  const ctx = context();
  classroomError.value = '';
  try {
    await deviceApi.restoreClassroom(ctx.org, classroom.id);
    if (ctx.current()) await refreshClassrooms();
  } catch (failure) {
    if (ctx.current()) classroomError.value = issue(failure);
  }
}
// Classroom management modal
const showClassroomModal = ref(false);
const newClassroomName = ref('');
const classroomError = ref('');
const editingClassroomId = ref<string | null>(null);
const editingClassroomName = ref('');

async function createClassroom(): Promise<void> {
  if (!newClassroomName.value.trim()) return;
  classroomError.value = '';
  const ctx = context();
  try {
    await deviceApi.createClassroom(workspace.organizationId, newClassroomName.value.trim());
    if (!ctx.current()) return;
    newClassroomName.value = '';
    await refreshClassrooms();
  } catch (err) {
    if (!ctx.current()) return;
    classroomError.value = issue(err instanceof Error ? err : new Error(String(err)));
  }
}

async function renameClassroom(classroom: DeviceClassroom): Promise<void> {
  if (!editingClassroomName.value.trim()) return;
  classroomError.value = '';
  const ctx = context();
  try {
    await deviceApi.updateClassroom(
      workspace.organizationId,
      classroom.id,
      editingClassroomName.value.trim(),
    );
    if (!ctx.current()) return;
    editingClassroomId.value = null;
    await refreshClassrooms();
  } catch (err) {
    if (!ctx.current()) return;
    classroomError.value = issue(err instanceof Error ? err : new Error(String(err)));
  }
}

async function archiveClassroom(classroom: DeviceClassroom): Promise<void> {
  if (!window.confirm(t('education.archiveClassroomConfirm'))) return;
  classroomError.value = '';
  const ctx = context();
  try {
    await deviceApi.archiveClassroom(workspace.organizationId, classroom.id);
    if (!ctx.current()) return;
    if (selectedClassroomId.value === classroom.id) {
      selectedClassroomId.value = '';
    }
    await refreshClassrooms();
  } catch (err) {
    if (!ctx.current()) return;
    classroomError.value = issue(err instanceof Error ? err : new Error(String(err)));
  }
}

// Device creation
const showCreate = ref(false);
const createName = ref('');
const createNumber = ref('');
const createClassroomId = ref('');
const createError = ref('');

async function createDevice(): Promise<void> {
  createError.value = '';
  const ctx = context();
  try {
    await deviceApi.createDevice(workspace.organizationId, {
      classroomId: createClassroomId.value || undefined,
      name: createName.value.trim(),
      number: createNumber.value.trim(),
    });
    if (!ctx.current()) return;
    showCreate.value = false;
    createName.value = '';
    createNumber.value = '';
    await refresh();
  } catch (failure) {
    if (!ctx.current()) return;
    createError.value = issue(failure instanceof Error ? failure : new Error(String(failure)));
  }
}

// Device editing / moving
const editingDevice = ref<Device | null>(null);
const editName = ref('');
const editNumber = ref('');
const editClassroomId = ref('');
const editError = ref('');

function openEditDevice(device: Device): void {
  editingDevice.value = device;
  editName.value = device.name;
  editNumber.value = device.number;
  editClassroomId.value = device.classroomId ?? '';
  editError.value = '';
}

async function saveEditDevice(): Promise<void> {
  if (!editingDevice.value || !canDevice(detail.value, 'edit')) return;
  editError.value = '';
  const ctx = context();
  try {
    await deviceApi.updateDevice(workspace.organizationId, editingDevice.value.id, {
      classroomId: editClassroomId.value || null,
      name: editName.value.trim(),
      number: editNumber.value.trim(),
    });
    if (!ctx.current()) return;
    editingDevice.value = null;
    await refresh();
    if (ctx.current() && detailId.value) await loadDetail();
  } catch (failure) {
    if (!ctx.current()) return;
    editError.value = issue(failure instanceof Error ? failure : new Error(String(failure)));
  }
}

// Enrollments
const enrollments = ref(new Map<string, DeviceEnrollment>());

// Student assignment with search & pagination
const assignDevice = ref<DeviceDetail | null>(null);
const assignStudentId = ref('');
const assignBusy = ref(false);
const assignError = ref('');
const rosterSearch = ref('');
const roster = ref<{ items: { id: string; name?: string }[] } | null>(null);

async function loadRoster(): Promise<void> {
  const ctx = context();
  const items: { id: string; name?: string }[] = [];
  let next: number | null = 0;
  while (next !== null) {
    const pageOffset: number = next;
    const page = await educationApi.students(ctx.org, pageOffset);
    items.push(...page.items);
    next = page.nextOffset;
    if (next !== null && next <= pageOffset) throw new Error('Invalid pagination');
  }
  if (ctx.current()) roster.value = { items };
}

const filteredRoster = computed(() => {
  if (!roster.value?.items) return [];
  if (!rosterSearch.value.trim()) return roster.value.items;
  const q = rosterSearch.value.toLowerCase();
  return roster.value.items.filter(
    (s) => (s.name ?? '').toLowerCase().includes(q) || s.id.toLowerCase().includes(q),
  );
});

async function openAssign(device: DeviceDetail): Promise<void> {
  if (!canDevice(device, 'assign')) return;
  assignDevice.value = device;
  assignStudentId.value = '';
  assignError.value = '';
  rosterSearch.value = '';
  try {
    await loadRoster();
  } catch (failure) {
    assignError.value = issue(failure);
  }
}

async function assign(): Promise<void> {
  if (
    assignBusy.value ||
    !assignDevice.value ||
    !assignStudentId.value ||
    !canDevice(assignDevice.value, 'assign')
  )
    return;
  assignBusy.value = true;
  assignError.value = '';
  const ctx = context();
  try {
    await deviceApi.startSession(
      workspace.organizationId,
      assignDevice.value.id,
      assignStudentId.value,
      assignDevice.value.stateVersion,
    );
    if (!ctx.current()) return;
    assignDevice.value = null;
    await refresh();
    if (ctx.current() && detailId.value) await loadDetail();
  } catch (failure) {
    if (!ctx.current()) return;
    assignError.value = issue(failure instanceof Error ? failure : new Error(String(failure)));
  } finally {
    if (ctx.current()) assignBusy.value = false;
  }
}

// Session history drawer/modal
const historyDevice = ref<Device | null>(null);
const deviceSessions = ref<DeviceSession[]>([]);
const historyLoading = ref(false);
let historyRequest = 0;
const historyOffset = ref(0),
  historyNextOffset = ref<number | null>(null),
  historyError = ref('');

async function openHistory(device: Device, pageOffset = 0): Promise<void> {
  const ctx = context();
  const request = ++historyRequest;
  historyOffset.value = pageOffset;
  historyError.value = '';
  deviceSessions.value = [];
  historyDevice.value = device;
  historyLoading.value = true;
  try {
    const res = await deviceApi.sessions(ctx.org, device.id, undefined, pageOffset);
    if (ctx.current() && request === historyRequest && historyDevice.value?.id === device.id) {
      deviceSessions.value = res.items;
      historyNextOffset.value = res.nextOffset;
    }
  } catch (failure) {
    if (ctx.current() && request === historyRequest) historyError.value = issue(failure);
  } finally {
    if (ctx.current() && request === historyRequest) historyLoading.value = false;
  }
}

const detail = ref<DeviceDetail | null>(null),
  detailId = ref(''),
  detailLoading = ref(false),
  detailError = ref(''),
  actionError = ref(''),
  actionBusy = ref(false);
let detailController: AbortController | null = null,
  active = true;
function context() {
  const org = workspace.organizationId,
    epoch = coreAdminSession.epoch(),
    revision = workspace.revision;
  return {
    org,
    current: () =>
      active &&
      org === workspace.organizationId &&
      epoch === coreAdminSession.epoch() &&
      revision === workspace.revision,
  };
}
async function loadDetail(): Promise<void> {
  if (!detailId.value) return;
  detailController?.abort();
  const controller = new AbortController();
  detailController = controller;
  const ctx = context(),
    id = detailId.value;
  detailLoading.value = true;
  detailError.value = '';
  try {
    const result = await deviceApi.device(ctx.org, id, controller.signal);
    if (ctx.current() && !controller.signal.aborted && detailId.value === id) detail.value = result;
  } catch (failure) {
    if (ctx.current() && !controller.signal.aborted) {
      detail.value = null;
      detailError.value = issue(failure);
    }
  } finally {
    if (ctx.current() && !controller.signal.aborted) detailLoading.value = false;
  }
}
async function openDetail(device: Device): Promise<void> {
  detail.value = null;
  detailId.value = device.id;
  actionError.value = '';
  await loadDetail();
}
function closeDetail(): void {
  detailController?.abort();
  detailId.value = '';
  detail.value = null;
  detailError.value = '';
}
async function deviceAction(action: DeviceAction): Promise<void> {
  const target = detail.value,
    ctx = context();
  if (!target || !canDevice(target, action) || actionBusy.value) return;
  if (action === 'assign') {
    await openAssign(target);
    return;
  }
  if (action === 'edit') {
    openEditDevice(target);
    return;
  }
  if (action !== 'enroll' && !window.confirm(t(`education.confirmDevice_${action}`))) return;
  actionBusy.value = true;
  actionError.value = '';
  try {
    const session = target.sessions.find((item) => item.status !== 'CLOSED');
    if (action === 'enroll') {
      const result = await deviceApi.enroll(ctx.org, target.id);
      if (ctx.current()) enrollments.value.set(target.id, result);
    } else if (action === 'revoke') await deviceApi.revoke(ctx.org, target.id);
    else if (action === 'retry_recovery')
      await deviceApi.retryRecovery(ctx.org, target.id, target.stateVersion);
    else if (session && action === 'cancel_assignment')
      await deviceApi.cancelAssignment(ctx.org, target.id, session.id, target.stateVersion);
    else if (session) await deviceApi.endSession(ctx.org, target.id, session.id);
    if (ctx.current()) {
      await refresh();
      if (detailId.value === target.id) await loadDetail();
    }
  } catch (failure) {
    if (ctx.current()) actionError.value = issue(failure);
  } finally {
    if (ctx.current()) actionBusy.value = false;
  }
}
watch(
  [() => workspace.organizationId, () => workspace.revision, coreAdminSessionEpoch],
  () => {
    offset.value = 0;
    classroomOffset.value = 0;
    selectedClassroomId.value = '';
    selectedStatus.value = '';
    searchQuery.value = '';
    classroomSearch.value = '';
    showArchived.value = false;
    newClassroomName.value = '';
    editingClassroomId.value = null;
    editingClassroomName.value = '';
    createName.value = '';
    createNumber.value = '';
    createClassroomId.value = '';
    editName.value = '';
    editNumber.value = '';
    editClassroomId.value = '';
    assignStudentId.value = '';
    rosterSearch.value = '';
    historyRequest++;
    closeDetail();
    historyDevice.value = null;
    deviceSessions.value = [];
    assignDevice.value = null;
    roster.value = null;
    editingDevice.value = null;
    enrollments.value.clear();
    showCreate.value = false;
    showClassroomModal.value = false;
    actionBusy.value = false;
    assignBusy.value = false;
    actionError.value = '';
    historyError.value = '';
    assignError.value = '';
    classroomError.value = '';
    createError.value = '';
    editError.value = '';
  },
  { flush: 'sync' },
);
const poll = setInterval(() => {
  if (!actionBusy.value && workspace.organizationId) {
    if (detailId.value) void loadDetail();
    else if (!loading.value) void refresh();
  }
}, 10000);
onScopeDispose(() => {
  active = false;
  clearInterval(poll);
  detailController?.abort();
  if (searchTimer) clearTimeout(searchTimer);
});

watch([selectedClassroomId, selectedStatus], () => {
  offset.value = 0;
});

// Search debounce
let searchTimer: ReturnType<typeof setTimeout> | null = null;
watch(searchQuery, () => {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    offset.value = 0;
    void refresh();
  }, 300);
});

function statusClass(status: DeviceStatus): string {
  switch (status) {
    case 'AVAILABLE':
      return 'edu-badge--available';
    case 'ACTIVE':
      return 'edu-badge--active';
    case 'SYNCING_IN':
    case 'SYNCING_OUT':
      return 'edu-badge--syncing';
    case 'PREPARING':
    case 'RESERVED':
      return 'edu-badge--preparing';
    case 'RECOVERY_REQUIRED':
    case 'ERROR':
      return 'edu-badge--error';
    case 'OFFLINE':
    default:
      return 'edu-badge--offline';
  }
}

function formatDate(iso: string | null): string {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleString(locale.value, {
      hour: '2-digit',
      minute: '2-digit',
      day: '2-digit',
      month: '2-digit',
    });
  } catch {
    return iso;
  }
}
</script>

<template>
  <section class="edu-classroom-view">
    <header class="edu-page-title">
      <div>
        <h1>{{ t('education.computers') }}</h1>
        <p class="edu-subtle">
          {{ t('education.computersHint') }}
        </p>
      </div>
      <div class="edu-header-actions">
        <button
          type="button"
          class="edu-button"
          @click="showClassroomModal = true"
        >
          {{ t('education.manageClassrooms') }}
        </button>
        <button
          type="button"
          class="edu-button edu-button--primary"
          @click="showCreate = !showCreate"
        >
          {{ t('education.addDevice') }}
        </button>
      </div>
    </header>

    <!-- Filters & Classrooms bar -->
    <div
      v-if="data"
      class="edu-toolbar"
    >
      <div class="edu-toolbar__filters">
        <AppDropdown
          id="classroom-filter"
          v-model="selectedClassroomId"
          :label="t('education.classroom')"
          :options="[
            { value: '', label: t('education.allClassrooms') },
            ...data.classrooms.items.map((c) => ({ value: c.id, label: c.name })),
          ]"
        />
        <AppDropdown
          id="status-filter"
          v-model="selectedStatus"
          :label="t('education.allStatuses')"
          :options="[
            { value: '', label: t('education.allStatuses') },
            { value: 'AVAILABLE', label: t('education.device_available') },
            { value: 'ACTIVE', label: t('education.device_active') },
            { value: 'PREPARING', label: t('education.device_preparing') },
            { value: 'SYNCING_IN', label: t('education.device_syncing_in') },
            { value: 'SYNCING_OUT', label: t('education.device_syncing_out') },
            { value: 'RECOVERY_REQUIRED', label: t('education.device_recovery_required') },
            { value: 'OFFLINE', label: t('education.device_offline') },
          ]"
        />
        <AppInput
          id="search-filter"
          v-model="searchQuery"
          :label="t('education.searchComputers')"
          maxlength="80"
        />
      </div>
      <button
        type="button"
        class="edu-button edu-button--subtle"
        :title="t('education.refresh')"
        @click="refresh"
      >
        ↻ {{ t('education.refresh') }}
      </button>
    </div>

    <EducationState
      :loading="loading"
      :error="error"
      @retry="refresh"
    />

    <!-- Create Device Form -->
    <form
      v-if="showCreate && data"
      class="edu-form edu-card"
      @submit.prevent="createDevice"
    >
      <h3>{{ t('education.addDevice') }}</h3>
      <div class="edu-form-actions">
        <AppInput
          id="education-device-number"
          v-model="createNumber"
          :label="t('education.deviceNumber')"
          maxlength="40"
          required
        />
        <AppInput
          id="education-device-name"
          v-model="createName"
          :label="t('education.deviceName')"
          maxlength="160"
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
        <button
          type="button"
          class="edu-button"
          @click="showCreate = false"
        >
          {{ t('education.cancel') }}
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

    <!-- Visual Seats / Workstations Grid -->
    <template v-if="data">
      <EducationState
        v-if="!data.devices.items.length"
        :empty="t('education.noDevices')"
      />
      <div
        v-else
        class="edu-seats-grid"
      >
        <article
          v-for="device in data.devices.items"
          :key="device.id"
          class="edu-seat-card"
          :class="{
            'edu-seat-card--alert':
              device.status === 'RECOVERY_REQUIRED' || device.status === 'ERROR',
          }"
        >
          <header class="edu-seat-card__header">
            <span class="edu-seat-number">
              {{ device.number }}
            </span>
            <span
              class="edu-badge"
              :class="statusClass(device.status)"
            >
              {{ t(`education.device_${device.status.toLowerCase()}`) }}
            </span>
          </header>

          <div class="edu-seat-card__body">
            <h4 class="edu-seat-name">
              {{ device.name || t('education.computerNumber', { number: device.number }) }}
            </h4>
            <p class="edu-subtle">
              {{ device.classroomName || t('education.noClassroom') }}
            </p>

            <div
              v-if="device.lastSeenAt"
              class="edu-seat-meta"
            >
              <span>{{ t('education.lastSeen') }}:</span>
              <strong>{{ formatDate(device.lastSeenAt) }}</strong>
            </div>

            <!-- Enrollment code badge -->
            <div
              v-if="enrollments.get(device.id)"
              class="edu-invite-pill"
            >
              <span>{{ t('education.generateEnrollment') }}:</span>
              <code>{{ enrollments.get(device.id)?.code }}</code>
            </div>

            <!-- Recovery required alert -->
            <div
              v-if="device.status === 'RECOVERY_REQUIRED' || device.status === 'ERROR'"
              class="edu-error-badge"
            >
              ⚠️ {{ t('education.syncProblem') }}
            </div>
          </div>

          <footer class="edu-seat-card__actions">
            <button
              type="button"
              class="edu-button edu-button--small edu-button--primary"
              @click="openDetail(device)"
            >
              {{ t('education.deviceDetails') }}
            </button>
            <button
              type="button"
              class="edu-button edu-button--small"
              @click="openHistory(device)"
            >
              {{ t('education.sessionHistory') }}
            </button>
          </footer>
        </article>
      </div>

      <!-- Pagination -->
      <div
        v-if="offset || data.devices.nextOffset !== null"
        class="edu-form-actions"
      >
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
      v-if="detailId"
      class="edu-modal-backdrop"
      @click.self="closeDetail"
    >
      <div
        class="edu-modal edu-card"
        role="dialog"
        aria-modal="true"
        :aria-label="t('education.deviceDetails')"
      >
        <p
          v-if="detailLoading && !detail"
          class="edu-subtle"
        >
          {{ t('education.loading') }}
        </p>
        <p
          v-if="detailError"
          class="edu-error"
          role="alert"
        >
          {{ detailError }}
        </p>
        <p
          v-if="actionError"
          class="edu-error"
          role="alert"
        >
          {{ actionError }}
        </p>
        <DeviceDetailsPanel
          v-if="detail"
          :device="detail"
          :busy="actionBusy || detailLoading"
          :enrollment="enrollments.get(detail.id)"
          @action="deviceAction"
        />
        <div class="edu-form-actions">
          <button
            type="button"
            class="edu-button"
            :disabled="detailLoading"
            @click="loadDetail"
          >
            {{ t('education.refresh') }}
          </button>
          <button
            type="button"
            class="edu-button"
            @click="closeDetail"
          >
            {{ t('education.back') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Student Assignment Modal Dialog -->
    <div
      v-if="assignDevice"
      class="edu-modal-backdrop"
      @click.self="assignDevice = null"
    >
      <div class="edu-modal edu-card">
        <h3>
          {{ t('education.assignStudent') }}: {{ assignDevice.number }} ({{ assignDevice.name }})
        </h3>
        <p class="edu-subtle">
          {{ t('education.assignHint') }}
        </p>

        <AppInput
          id="roster-search"
          v-model="rosterSearch"
          :label="t('education.searchStudents')"
          maxlength="80"
        />

        <div class="edu-roster-list">
          <button
            v-for="student in filteredRoster"
            type="button"
            :aria-pressed="assignStudentId === student.id"
            :key="student.id"
            class="edu-roster-item"
            :class="{ 'edu-roster-item--selected': assignStudentId === student.id }"
            @click="assignStudentId = student.id"
          >
            <strong>{{ student.name || student.id }}</strong>
            <span class="edu-subtle">{{ student.id.slice(0, 8) }}</span>
          </button>
          <p
            v-if="!filteredRoster.length"
            class="edu-subtle"
          >
            {{ t('education.noPeople') }}
          </p>
        </div>

        <div class="edu-form-actions">
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
    </div>

    <!-- Edit Device Modal Dialog -->
    <div
      v-if="editingDevice && data"
      class="edu-modal-backdrop"
      @click.self="editingDevice = null"
    >
      <div class="edu-modal edu-card">
        <h3>{{ t('education.editDevice') }}: {{ editingDevice.number }}</h3>
        <form @submit.prevent="saveEditDevice">
          <AppInput
            id="edit-device-number"
            v-model="editNumber"
            :label="t('education.deviceNumber')"
            maxlength="40"
            required
          />
          <AppInput
            id="edit-device-name"
            v-model="editName"
            :label="t('education.deviceName')"
            maxlength="160"
          />
          <AppDropdown
            id="edit-device-classroom"
            v-model="editClassroomId"
            :label="t('education.classroom')"
            :options="[
              { value: '', label: t('education.noClassroom') },
              ...data.classrooms.items.map((c) => ({ value: c.id, label: c.name })),
            ]"
          />
          <div class="edu-form-actions">
            <button
              type="submit"
              class="edu-button edu-button--primary"
            >
              {{ t('education.save') }}
            </button>
            <button
              type="button"
              class="edu-button"
              @click="editingDevice = null"
            >
              {{ t('education.cancel') }}
            </button>
          </div>
          <p
            v-if="editError"
            class="edu-error"
            role="alert"
          >
            {{ editError }}
          </p>
        </form>
      </div>
    </div>

    <!-- Manage Classrooms Modal Dialog -->
    <div
      v-if="showClassroomModal && data"
      class="edu-modal-backdrop"
      @click.self="showClassroomModal = false"
    >
      <div class="edu-modal edu-card">
        <h3>{{ t('education.manageClassrooms') }}</h3>

        <form
          class="edu-inline-form"
          @submit.prevent="createClassroom"
        >
          <AppInput
            id="new-classroom-name"
            v-model="newClassroomName"
            :label="t('education.classroomName')"
            maxlength="160"
            required
          />
          <button
            type="submit"
            class="edu-button edu-button--primary"
          >
            {{ t('education.createClassroom') }}
          </button>
        </form>

        <p
          v-if="classroomError"
          class="edu-error"
          role="alert"
        >
          {{ classroomError }}
        </p>

        <AppInput
          id="classroom-search"
          v-model="classroomSearch"
          :label="t('education.searchClassrooms')"
          maxlength="160"
        />
        <label>
          <input
            v-model="showArchived"
            type="checkbox"
          />
          {{ t('education.showArchived') }}
        </label>
        <EducationState
          :loading="classroomResource.loading.value"
          :error="classroomResource.error.value"
          @retry="classroomResource.refresh"
        />
        <ul class="edu-list">
          <li
            v-for="classroom in classroomResource.data.value?.items ?? []"
            :key="classroom.id"
            class="edu-list__item"
          >
            <div
              v-if="editingClassroomId === classroom.id"
              class="edu-inline-form"
            >
              <AppInput
                :id="`rename-${classroom.id}`"
                v-model="editingClassroomName"
                :label="t('education.classroomName')"
                maxlength="160"
                required
              />
              <button
                type="button"
                class="edu-button edu-button--primary"
                @click="renameClassroom(classroom)"
              >
                {{ t('education.save') }}
              </button>
              <button
                type="button"
                class="edu-button"
                @click="editingClassroomId = null"
              >
                {{ t('education.cancel') }}
              </button>
            </div>
            <div
              v-else
              class="edu-list__main"
            >
              <strong>{{ classroom.name }}</strong>
              <span class="edu-subtle">{{ formatDate(classroom.createdAt) }}</span>
            </div>
            <div
              v-if="editingClassroomId !== classroom.id"
              class="edu-form-actions"
            >
              <button
                type="button"
                class="edu-button edu-button--small"
                @click="
                  editingClassroomId = classroom.id;
                  editingClassroomName = classroom.name;
                "
              >
                {{ t('education.edit') }}
              </button>
              <button
                type="button"
                class="edu-button edu-button--small"
                @click="
                  classroom.archivedAt ? restoreClassroom(classroom) : archiveClassroom(classroom)
                "
              >
                {{ t('education.archive') }}
              </button>
            </div>
          </li>
        </ul>
        <div class="edu-form-actions">
          <button
            v-if="classroomOffset"
            type="button"
            class="edu-button"
            @click="classroomOffset = Math.max(0, classroomOffset - 50)"
          >
            {{ t('education.back') }}
          </button>
          <button
            v-if="classroomResource.data.value?.nextOffset != null"
            type="button"
            class="edu-button"
            @click="classroomOffset = classroomResource.data.value?.nextOffset ?? 0"
          >
            {{ t('education.loadMore') }}
          </button>
        </div>

        <div class="edu-form-actions">
          <button
            type="button"
            class="edu-button"
            @click="showClassroomModal = false"
          >
            {{ t('education.back') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Sessions History Modal Dialog -->
    <div
      v-if="historyDevice"
      class="edu-modal-backdrop"
      @click.self="historyDevice = null"
    >
      <div class="edu-modal edu-card">
        <h3>
          {{ t('education.sessionHistory') }}: {{ historyDevice.number }} {{ historyDevice.name }}
        </h3>

        <div
          v-if="historyLoading"
          class="edu-subtle"
        >
          {{ t('education.loading') }}
        </div>
        <ul
          v-else-if="deviceSessions.length"
          class="edu-list"
        >
          <li
            v-for="session in deviceSessions"
            :key="session.id"
            class="edu-list__item"
          >
            <div class="edu-list__main">
              <strong>{{ session.studentName || session.studentId }}</strong>
              <p class="edu-subtle">
                {{ t(`education.session_${session.status.toLowerCase()}`) }} ·
                {{ t('education.startedAt') }}: {{ formatDate(session.startedAt) }}
              </p>
            </div>
          </li>
        </ul>
        <p
          v-else
          class="edu-subtle"
        >
          {{ t('education.noSessions') }}
        </p>

        <p
          v-if="historyError"
          class="edu-error"
          role="alert"
        >
          {{ historyError }}
        </p>
        <div class="edu-form-actions">
          <button
            v-if="historyOffset"
            type="button"
            class="edu-button"
            :disabled="historyLoading"
            @click="openHistory(historyDevice, Math.max(0, historyOffset - 50))"
          >
            {{ t('education.back') }}
          </button>
          <button
            v-if="historyNextOffset !== null"
            type="button"
            class="edu-button"
            :disabled="historyLoading"
            @click="openHistory(historyDevice, historyNextOffset)"
          >
            {{ t('education.loadMore') }}
          </button>
        </div>
        <div class="edu-form-actions">
          <button
            type="button"
            class="edu-button"
            @click="historyDevice = null"
          >
            {{ t('education.back') }}
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.edu-classroom-view {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.edu-header-actions {
  display: flex;
  gap: 0.5rem;
}

.edu-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 1rem;
  flex-wrap: wrap;
}

.edu-toolbar__filters {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  align-items: flex-end;
}

.edu-seats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 1rem;
}

.edu-seat-card {
  background: var(--surface, #ffffff);
  border: 1px solid var(--border, #e2e8f0);
  border-radius: 8px;
  padding: 1rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 0.75rem;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
  transition:
    transform 0.15s ease,
    box-shadow 0.15s ease;
}

.edu-seat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.08);
}

.edu-seat-card--alert {
  border-color: #ef4444;
  background: var(--surface);
}

.edu-seat-card__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.edu-seat-number {
  font-size: 1.125rem;
  font-weight: 700;
  color: var(--primary, #2563eb);
}

.edu-seat-name {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
}

.edu-seat-meta {
  display: flex;
  gap: 0.5rem;
  font-size: 0.8125rem;
  margin-top: 0.25rem;
}

.edu-badge {
  padding: 0.25rem 0.5rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
}

.edu-badge--available {
  background: #dcfce7;
  color: #166534;
}

.edu-badge--active {
  background: #dbeafe;
  color: #1e40af;
}

.edu-badge--syncing {
  background: #e0f2fe;
  color: #0369a1;
}

.edu-badge--preparing {
  background: #fef9c3;
  color: #854d0e;
}

.edu-badge--error {
  background: #fee2e2;
  color: #991b1b;
}

.edu-badge--offline {
  background: #f1f5f9;
  color: #64748b;
}

.edu-invite-pill {
  margin-top: 0.5rem;
  padding: 0.25rem 0.5rem;
  background: var(--surface-hover, var(--surface));
  border-radius: 4px;
  font-size: 0.8125rem;
  display: flex;
  justify-content: space-between;
}

.edu-error-badge {
  margin-top: 0.5rem;
  color: #b91c1c;
  font-size: 0.8125rem;
  font-weight: 600;
}

.edu-seat-card__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem;
  border-top: 1px solid var(--border, #f1f5f9);
  padding-top: 0.75rem;
}

.edu-button--small {
  padding: 0.25rem 0.5rem;
  font-size: 0.8125rem;
}

.edu-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}

.edu-modal {
  width: 90%;
  max-width: 520px;
  max-height: 85vh;
  overflow-y: auto;
}

.edu-roster-list {
  max-height: 220px;
  overflow-y: auto;
  border: 1px solid var(--border, #e2e8f0);
  border-radius: 6px;
  margin: 0.75rem 0;
}

.edu-roster-item {
  padding: 0.5rem 0.75rem;
  display: flex;
  justify-content: space-between;
  cursor: pointer;
  border-bottom: 1px solid var(--border, #f1f5f9);
}

.edu-roster-item:hover {
  background: var(--surface-hover, var(--surface));
}

.edu-roster-item--selected {
  background: var(--surface-hover, var(--surface));
  border-color: #3b82f6;
}

.edu-inline-form {
  display: flex;
  gap: 0.5rem;
  align-items: flex-end;
}
@media (max-width: 600px) {
  .edu-header-actions,
  .edu-inline-form {
    flex-wrap: wrap;
  }
  .edu-modal {
    width: calc(100% - 1rem);
  }
}
</style>
