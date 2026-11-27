<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import { activityApi, type ActivityItem } from './activityApi';
import { actT } from './activityI18n';

const router = useRouter();
const orgId = ref('');
const items = ref<ActivityItem[]>([]);
const analytics = ref<{
  summary: Record<string, unknown>;
  recentAttempts: Array<Record<string, unknown>>;
} | null>(null);

async function loadAll(): Promise<void> {
  const ctx = await coreAdminHttp.request<{ items: Array<{ id: string }> }>('/chat/organizations', {
    method: 'GET',
  });
  if (!ctx.items[0]) return;
  orgId.value = ctx.items[0].id;
  const [actRes, anaRes] = await Promise.all([
    activityApi.listActivities(orgId.value),
    activityApi.analytics(orgId.value),
  ]);
  items.value = actRes.items;
  analytics.value = anaRes;
}

async function launchActivity(id: string): Promise<void> {
  void router.push(`/activities/${id}/run`);
}

async function duplicate(id: string): Promise<void> {
  await activityApi.duplicateActivity(orgId.value, id);
  await loadAll();
}

onMounted(() => void loadAll());
</script>

<template>
  <section class="uact-cat">
    <header class="uact-cat__head">
      <h1>{{ actT('catalogTitle') }}</h1>
      <AppButton
        variant="primary"
        @click="router.push('/activities/studio')"
      >
        {{ actT('newActivity') }}
      </AppButton>
    </header>

    <div
      v-if="analytics"
      class="uact-cat__stats"
    >
      <div class="uact-stat">
        <small>{{ actT('totalAttempts') }}</small>
        <strong>{{ analytics.summary.totalAttempts ?? 0 }}</strong>
      </div>
      <div class="uact-stat">
        <small>{{ actT('completedAttempts') }}</small>
        <strong>{{ analytics.summary.completedAttempts ?? 0 }}</strong>
      </div>
      <div class="uact-stat">
        <small>{{ actT('averagePct') }}</small>
        <strong>{{ analytics.summary.averagePercentage ?? 0 }}%</strong>
      </div>
      <div class="uact-stat">
        <small>{{ actT('bestPct') }}</small>
        <strong>{{ analytics.summary.bestPercentage ?? 0 }}%</strong>
      </div>
    </div>

    <div class="uact-cat__grid">
      <article
        v-for="item in items"
        :key="item.id"
        class="uact-cat__card"
      >
        <div class="uact-cat__top">
          <strong>{{ item.title }}</strong>
          <AppBadge variant="primary">{{ item.pluginTitle }}</AppBadge>
        </div>
        <p>{{ item.description }}</p>
        <div class="uact-cat__actions">
          <AppButton
            v-if="item.status === 'PUBLISHED'"
            size="s"
            variant="primary"
            @click="launchActivity(item.id)"
          >
            {{ actT('runNow') }}
          </AppButton>
          <AppButton
            size="s"
            variant="secondary"
            @click="router.push(`/activities/studio/${item.id}`)"
          >
            {{ actT('openStudio') }}
          </AppButton>
          <AppButton
            size="s"
            variant="ghost"
            @click="duplicate(item.id)"
          >
            {{ actT('duplicate') }}
          </AppButton>
        </div>
      </article>
    </div>
  </section>
</template>

<style scoped>
.uact-cat {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 16px;
}
.uact-cat__head,
.uact-cat__top,
.uact-cat__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
}
.uact-cat__stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 10px;
}
.uact-stat,
.uact-cat__card {
  padding: 14px;
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--card);
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.uact-cat__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 12px;
}
</style>
