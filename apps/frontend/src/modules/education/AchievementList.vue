<script setup lang="ts">
import { useEducationI18n } from './i18n';
import type { Achievement } from './activityTypes';
defineProps<{ items: Achievement[]; compact?: boolean }>();
const { t, locale } = useEducationI18n();
</script>
<template>
  <p
    v-if="!items.length"
    class="edu-subtle"
  >
    {{ t('education.parentNoAchievements') }}
  </p>
  <ul
    v-else
    class="edu-achievements"
    :class="{ 'edu-achievements--compact': compact }"
  >
    <li
      v-for="item in items"
      :key="`${item.code}:${item.ruleVersion}`"
      class="edu-card edu-achievement"
      :class="{ 'edu-achievement--unlocked': item.unlockedAt }"
    >
      <span
        class="edu-achievement__badge"
        aria-hidden="true"
      >
        {{ item.unlockedAt ? '★' : '☆' }}
      </span>
      <div>
        <strong>{{ item.name }}</strong>
        <p class="edu-subtle">{{ item.description }}</p>
        <p
          v-if="item.unlockedAt"
          class="edu-caption"
        >
          <span>{{ t('education.achievementUnlocked') }} ·</span>
          <time :datetime="item.unlockedAt">
            {{ new Date(item.unlockedAt).toLocaleDateString(locale) }}
          </time>
        </p>
        <template v-else>
          <p class="edu-caption">
            {{ t('education.achievementLocked') }} · {{ item.progress.current }} /
            {{ item.progress.target }}
          </p>
          <progress
            v-if="item.progress.target > 0"
            class="edu-activity-progress"
            :value="Math.min(item.progress.current, item.progress.target)"
            :max="item.progress.target"
            :aria-label="item.name"
          />
        </template>
      </div>
    </li>
  </ul>
</template>
