<script setup lang="ts">
import { onScopeDispose, ref, shallowRef, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppDialog from '@/shared/ui/overlays/AppDialog.vue';
import { formatDateTime } from '@/shared/formatting/date';
import EducationState from '../EducationState.vue';
import { deviceApi, type Device } from './api';

const props = defineProps<{ device: Device; organizationId: string }>();
const emit = defineEmits<{ close: [] }>();
const { t, te, locale } = useI18n();
const tabs = ['sessionHistory', 'eventHistory', 'commandHistory'] as const;
type HistoryTab = (typeof tabs)[number];
const activeTab = ref<HistoryTab>('sessionHistory');
const offset = ref(0);
const nextOffset = ref<number | null>(null);
const loading = ref(false);
const error = shallowRef<unknown>(null);
const rows = ref<
  { id: string; title: string; date: string; status?: string; endedAt?: string | null }[]
>([]);
let controller: AbortController | undefined;

function label(key: string, fallback: string): string {
  return te(`education.${key}`) ? t(`education.${key}`) : fallback;
}

async function load(): Promise<void> {
  controller?.abort();
  const request = new AbortController();
  controller = request;
  const { organizationId, device } = props;
  const { signal } = request;
  loading.value = true;
  error.value = null;
  rows.value = [];
  nextOffset.value = null;
  try {
    if (activeTab.value === 'sessionHistory') {
      const page = await deviceApi.sessions(organizationId, device.id, signal, offset.value);
      if (signal.aborted) return;
      rows.value = page.items.map((session) => ({
        id: session.id,
        title: session.studentName || session.studentId,
        date: session.startedAt,
        status: label(`session_${session.status.toLowerCase()}`, session.status),
        endedAt: session.endedAt,
      }));
      nextOffset.value = page.nextOffset;
    } else if (activeTab.value === 'eventHistory') {
      const page = await deviceApi.events(organizationId, device.id, offset.value, signal);
      if (signal.aborted) return;
      rows.value = page.items.map((event) => ({
        id: event.id,
        title: label(`deviceEvent_${event.kind}`, event.kind),
        date: event.createdAt,
        status: transitionLabel(event),
      }));
      nextOffset.value = page.nextOffset;
    } else {
      const page = await deviceApi.commands(organizationId, device.id, offset.value, signal);
      if (signal.aborted) return;
      rows.value = page.items.map((command) => ({
        id: command.id,
        title: label(`deviceCommand_${command.kind}`, command.kind),
        date: command.createdAt,
        status: label(`command_${command.status.toLowerCase()}`, command.status),
      }));
      nextOffset.value = page.nextOffset;
    }
  } catch (caught) {
    if (!signal.aborted) error.value = caught;
  } finally {
    if (!signal.aborted) loading.value = false;
  }
}

function transitionLabel(
  event: Awaited<ReturnType<typeof deviceApi.events>>['items'][number],
): string | undefined {
  if (event.kind !== 'session_state' || Array.isArray(event.payload)) return;
  const { from, status } = event.payload;
  if (typeof from !== 'string' || typeof status !== 'string') return;
  const previous = `session_${from.toLowerCase()}`;
  const next = `session_${status.toLowerCase()}`;
  if (te(`education.${previous}`) && te(`education.${next}`)) {
    return `${t(`education.${previous}`)} → ${t(`education.${next}`)}`;
  }
}

function selectTab(tab: HistoryTab): void {
  activeTab.value = tab;
  offset.value = 0;
}

watch([activeTab, offset, locale, () => props.organizationId, () => props.device.id], load, {
  immediate: true,
});
onScopeDispose(() => controller?.abort());
</script>

<template>
  <AppDialog
    :model-value="true"
    :title="t('education.deviceHistory')"
    :description="`${t('education.computerNumber', { number: device.number })} · ${device.name}`"
    @update:model-value="emit('close')"
  >
    <template #toolbar>
      <div class="edu-form-actions">
        <button
          v-for="tab in tabs"
          :key="tab"
          type="button"
          class="edu-button"
          :class="{ 'edu-button--primary': activeTab === tab }"
          :aria-pressed="activeTab === tab"
          @click="selectTab(tab)"
        >
          {{ t(`education.${tab}`) }}
        </button>
      </div>
    </template>
    <EducationState
      :loading="loading"
      :error="error"
      :empty="rows.length === 0 ? t(`education.empty_${activeTab}`) : undefined"
      @retry="load"
    />
    <ul
      v-if="rows.length"
      class="edu-list"
    >
      <li
        v-for="row in rows"
        :key="row.id"
        class="edu-list__item"
      >
        <div class="edu-list__main device-history__entry">
          <strong>{{ row.title }}</strong>
          <p class="edu-subtle">
            {{ row.status ? `${row.status} · ` : '' }}{{ formatDateTime(row.date, locale) }}
          </p>
          <p
            v-if="row.endedAt"
            class="edu-subtle"
          >
            {{ t('education.endedAt') }} {{ formatDateTime(row.endedAt, locale) }}
          </p>
        </div>
      </li>
    </ul>
    <template #footer>
      <div class="edu-form-actions">
        <button
          v-if="offset > 0"
          type="button"
          class="edu-button"
          :disabled="loading"
          @click="offset = Math.max(0, offset - 50)"
        >
          {{ t('education.back') }}
        </button>
        <button
          v-if="nextOffset !== null"
          type="button"
          class="edu-button"
          @click="offset = nextOffset ?? offset"
        >
          {{ t('education.loadMore') }}
        </button>
        <button
          type="button"
          class="edu-button"
          :disabled="loading"
          @click="load"
        >
          {{ t('education.refresh') }}
        </button>
      </div>
    </template>
  </AppDialog>
</template>

<style scoped>
.device-history__entry {
  min-width: 0;
  overflow-wrap: anywhere;
}
</style>
