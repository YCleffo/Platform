<script setup lang="ts">
import { ref } from 'vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';

const props = defineProps<{ codes: string[] }>();

const copied = ref(false);
let resetTimer: ReturnType<typeof setTimeout> | null = null;

async function copy(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.codes.join('\n'));
    copied.value = true;
    if (resetTimer !== null) {
      clearTimeout(resetTimer);
    }
    resetTimer = setTimeout(() => {
      copied.value = false;
    }, 3000);
  } catch {
    copied.value = false;
  }
}
</script>

<template>
  <div class="recovery">
    <p class="recovery__intro">{{ $t('security.recoveryIntro') }}</p>
    <ul class="recovery__codes">
      <li
        v-for="code in codes"
        :key="code"
        class="recovery__code"
      >
        {{ code }}
      </li>
    </ul>
    <AppButton
      type="button"
      variant="secondary"
      @click="copy"
    >
      {{ copied ? $t('security.copied') : $t('security.copyCodes') }}
    </AppButton>
    <slot />
  </div>
</template>

<style scoped>
.recovery {
  display: flex;
  flex-direction: column;
  gap: var(--space-12);
}

.recovery__intro {
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--warning-text);
  background: var(--warning-soft);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  padding: var(--space-12);
  transition: var(--theme-transition);
}

.recovery__codes {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-8);
  padding: var(--space-12);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
  background: var(--surface-2);
  transition: var(--theme-transition);
}

.recovery__code {
  font-family: var(--font-mono);
  font-size: var(--type-body-small-size);
  font-weight: var(--type-body-small-weight);
  letter-spacing: var(--type-body-small-tracking);
  line-height: var(--type-body-small-line);
  color: var(--text-1);
  overflow-wrap: anywhere;
  transition: var(--theme-transition);
}

@media (max-width: 360px) {
  .recovery__codes {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
