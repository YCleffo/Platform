<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import {
  activityApi,
  type ActivityItem,
  type ActivityPluginDescriptor,
} from './activityApi';
import { actT } from './activityI18n';
import AssessmentConstructor from './AssessmentConstructor.vue';

const props = defineProps<{ activityId?: string }>();
const router = useRouter();

const orgId = ref('');
const plugins = ref<ActivityPluginDescriptor[]>([]);
const selectedPlugin = ref('platform-assessment');
const title = ref('');
const description = ref('');
const instructions = ref('');
const timeLimitSeconds = ref('');
const passingScore = ref('60');
const statusText = ref('');

async function ensureOrgContext(): Promise<string> {
  if (orgId.value) return orgId.value;
  const ctx = await coreAdminHttp.request<{ items: Array<{ id: string }> }>('/chat/organizations', {
    method: 'GET',
  });
  if (ctx.items[0]) {
    orgId.value = ctx.items[0].id;
  }
  return orgId.value;
}

async function loadData(): Promise<void> {
  const oid = await ensureOrgContext();
  if (!oid) return;

  const plugRes = await activityApi.listPlugins(oid);
  plugins.value = plugRes.items;

  if (props.activityId) {
    const act: ActivityItem = await activityApi.getActivity(oid, props.activityId);
    selectedPlugin.value = act.pluginCode;
    title.value = act.title;
    description.value = act.description;
    instructions.value = act.instructions ?? '';
    timeLimitSeconds.value = act.timeLimitSeconds ? String(act.timeLimitSeconds) : '';
    passingScore.value = String(act.passingScorePercent ?? 60);
    if (!statusText.value) {
      statusText.value = `${act.title} (${act.status})`;
    }
  }
}

async function saveActivity(publish: boolean): Promise<void> {
  const oid = await ensureOrgContext();
  if (!oid || !title.value.trim()) return;
  const payload = {
    pluginCode: selectedPlugin.value,
    title: title.value.trim(),
    description: description.value.trim(),
    instructions: instructions.value.trim(),
    timeLimitSeconds: timeLimitSeconds.value ? Number(timeLimitSeconds.value) : null,
    passingScorePercent: Number(passingScore.value || 60),
    content: {},
    publish,
  };
  const saved = props.activityId
    ? await activityApi.updateActivity(oid, props.activityId, payload)
    : await activityApi.createActivity(oid, payload);

  statusText.value = `${saved.title} (${saved.status})`;
  if (!props.activityId) {
    void router.replace(`/activities/studio/${saved.id}`);
  }
}

function onAssessmentSaved(item: { id: string }): void {
  if (!props.activityId && item?.id) {
    void router.replace(`/activities/studio/${item.id}`);
  }
}

onMounted(() => void loadData());
</script>

<template>
  <section class="uact-studio">
    <header class="uact-studio__head">
      <h1>{{ actT('studioTitle') }}</h1>
      <div
        v-if="selectedPlugin !== 'platform-assessment'"
        class="uact-studio__actions"
      >
        <AppButton
          variant="secondary"
          @click="saveActivity(false)"
        >
          {{ actT('saveDraft') }}
        </AppButton>
        <AppButton
          variant="primary"
          @click="saveActivity(true)"
        >
          {{ actT('publish') }}
        </AppButton>
      </div>
    </header>

    <div class="uact-studio__plugin-bar">
      <AppDropdown
        v-model="selectedPlugin"
        :label="actT('pluginLabel')"
        :options="plugins.map((p) => ({ value: p.code, label: `${p.title} (${p.category})` }))"
      />
    </div>

    <AssessmentConstructor
      v-if="selectedPlugin === 'platform-assessment' && orgId"
      mode="activity"
      :assessment-id="props.activityId"
      :organization-id="orgId"
      :show-back-button="false"
      @saved="onAssessmentSaved"
      @published="onAssessmentSaved"
      @duplicated="(newId) => router.replace(`/activities/studio/${newId}`)"
    />

    <div
      v-else-if="selectedPlugin === 'platform-assessment' && !orgId"
      class="uact-studio__loading"
    >
      {{ actT('loadingText') }}
    </div>

    <template v-else>
      <p v-if="statusText">{{ statusText }}</p>

      <div class="uact-studio__form">
        <AppInput
          id="uact-title"
          v-model="title"
          :label="actT('titleLabel')"
        />
        <AppInput
          id="uact-desc"
          v-model="description"
          :label="actT('descriptionLabel')"
        />
        <AppInput
          id="uact-tl"
          v-model="timeLimitSeconds"
          :label="actT('timeLimitSec')"
        />
        <AppInput
          id="uact-pass"
          v-model="passingScore"
          :label="actT('passingScorePct')"
        />
      </div>
    </template>
  </section>
</template>

<style scoped>
.uact-studio {
  max-width: 1180px;
  margin: 0 auto;
  padding: 1.5rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.uact-studio__head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
}

.uact-studio__actions {
  display: flex;
  gap: 0.5rem;
}

.uact-studio__plugin-bar {
  max-width: 360px;
}

.uact-studio__form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 0.75rem;
}
</style>
