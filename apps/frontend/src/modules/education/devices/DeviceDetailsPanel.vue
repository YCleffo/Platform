<script setup lang="ts">
import { computed } from 'vue';
import {
  canDevice,
  deviceActions,
  type DeviceAction,
  type DeviceDetail,
  type DeviceEnrollment,
} from './api';
import { useEducationI18n } from '../i18n';
const props = defineProps<{ device: DeviceDetail; busy: boolean; enrollment?: DeviceEnrollment }>();
const emit = defineEmits<{ action: [action: DeviceAction] }>();
const { t, locale } = useEducationI18n();
const session = computed(() => props.device.sessions.find((item) => item.status !== 'CLOSED'));
const lastSession = computed(() => session.value ?? props.device.sessions[0]);
const lastConfirmed = computed(() =>
  props.device.commands.find((command) => command.acknowledgedAt),
);
const date = (value: string | null) => (value ? new Date(value).toLocaleString(locale.value) : '—');
const labels: Record<DeviceAction, string> = {
  assign: 'assignStudent',
  enroll: 'generateEnrollment',
  revoke: 'revokeDevice',
  edit: 'editDevice',
  end_session: 'endSession',
  cancel_assignment: 'cancelAssignment',
  retry_recovery: 'retryRecovery',
};
</script>

<template>
  <section class="device-details">
    <h3>{{ t('education.computerNumber', { number: device.number }) }} · {{ device.name }}</h3>
    <p
      v-if="device.testAdapter"
      role="status"
      class="edu-subtle"
    >
      {{ t('education.testSessionMode') }}
    </p>
    <dl>
      <dt>{{ t('education.classroom') }}</dt>
      <dd>{{ device.classroomName || t('education.noClassroom') }}</dd>
      <dt>{{ t('education.deviceState') }}</dt>
      <dd data-device-status>{{ t(`education.device_${device.status.toLowerCase()}`) }}</dd>
      <dt>{{ t('education.connection') }}</dt>
      <dd>{{ t(device.online ? 'education.online' : 'education.device_offline') }}</dd>
      <dt>{{ t('education.lastHeartbeat') }}</dt>
      <dd>{{ date(device.lastSeenAt) }}</dd>
      <dt>{{ t('education.enrollment') }}</dt>
      <dd>{{ t(`education.enrollment_${device.enrollmentState}`) }}</dd>
      <dt>{{ t('education.agentVersion') }}</dt>
      <dd>{{ device.agentVersion || '—' }}</dd>
      <dt>{{ t('education.sessionState') }}</dt>
      <dd data-device-session-status>
        {{
          lastSession
            ? t(`education.session_${lastSession.status.toLowerCase()}`)
            : t('education.noSessions')
        }}
      </dd>
      <template v-if="session?.desiredStatus && session.desiredStatus !== session.status">
        <dt>{{ t('education.requestedSessionState') }}</dt>
        <dd>{{ t(`education.session_${session.desiredStatus.toLowerCase()}`) }}</dd>
      </template>
      <dt>{{ t('education.students') }}</dt>
      <dd>{{ session?.studentName || '—' }}</dd>
      <dt>{{ t('education.lastConfirmedAction') }}</dt>
      <dd>
        {{ lastConfirmed ? `${lastConfirmed.kind} · ${date(lastConfirmed.acknowledgedAt)}` : '—' }}
      </dd>
    </dl>
    <div v-if="device.progress">
      <p>
        {{ t(`education.stage_${device.progress.stage.toLowerCase()}`, device.progress.stage) }}
        <template v-if="device.progress.total > 0">
          · {{ device.progress.completed }} / {{ device.progress.total }}
        </template>
      </p>
      <progress
        v-if="device.progress.total > 0"
        :value="device.progress.completed"
        :max="device.progress.total"
        :aria-label="t('education.syncProgress')"
      />
    </div>
    <p
      v-if="device.lastError"
      class="edu-error"
      role="alert"
    >
      {{ device.lastError.message }}
      <code>{{ device.lastError.code }}</code>
      · {{ date(device.lastError.at) }}
    </p>
    <p
      v-if="device.recovery?.required"
      class="edu-error"
    >
      {{ t('education.syncProblem') }}
      <code v-if="device.recovery.reason">{{ device.recovery.reason }}</code>
    </p>
    <p
      v-if="enrollment"
      class="edu-invite-pill"
    >
      {{ t('education.generateEnrollment') }}:
      <code>{{ enrollment.code }}</code>
      · {{ date(enrollment.expiresAt) }}
    </p>
    <div class="edu-form-actions">
      <template
        v-for="action in deviceActions"
        :key="action"
      >
        <button
          v-if="canDevice(device, action)"
          type="button"
          class="edu-button edu-button--small"
          :disabled="busy"
          :data-action="action"
          @click="emit('action', action)"
        >
          {{ t(`education.${labels[action]}`) }}
        </button>
      </template>
    </div>
    <h4>{{ t('education.commandHistory') }}</h4>
    <ol
      v-if="device.commands.length"
      class="edu-list"
    >
      <li
        v-for="command in device.commands"
        :key="command.id"
        class="edu-list__item"
      >
        <div>
          <strong>{{ command.kind }}</strong>
          <p class="edu-subtle">
            {{ t(`education.command_${command.status.toLowerCase()}`) }} ·
            {{ date(command.createdAt) }}
          </p>
        </div>
      </li>
    </ol>
    <p
      v-else
      class="edu-subtle"
    >
      {{ t('education.noCommands') }}
    </p>
  </section>
</template>

<style scoped>
.device-details {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
dl {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 0.5rem 1rem;
  margin: 0;
}
dt {
  color: var(--text-2);
}
dd {
  margin: 0;
  overflow-wrap: anywhere;
}
progress {
  width: 100%;
  accent-color: var(--accent);
}
@media (max-width: 420px) {
  dl {
    grid-template-columns: 1fr;
  }
  dd {
    margin-bottom: 0.5rem;
  }
}
</style>
