<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = withDefaults(
  defineProps<{
    src?: string;
    name?: string;
    mime?: string;
    sizeBytes?: number;
  }>(),
  {
    src: undefined,
    name: 'document',
    mime: 'application/octet-stream',
    sizeBytes: undefined,
  },
);

const { t } = useI18n();

const textContent = ref<string | null>(null);
const textLoading = ref(false);
const textError = ref(false);

const normalizedMime = computed(() => (props.mime || '').toLowerCase().split(';')[0]!.trim());
const lowerName = computed(() => (props.name || '').toLowerCase());

const isSafeUrl = computed(() => {
  if (!props.src) return false;
  if (props.src.startsWith('/') || props.src.startsWith('blob:')) return true;
  try {
    const parsed = new URL(props.src, typeof window !== 'undefined' ? window.location.origin : 'http://localhost');
    return parsed.protocol === 'https:' || parsed.protocol === 'http:';
  } catch {
    return false;
  }
});

const isPdf = computed(() => normalizedMime.value === 'application/pdf' || lowerName.value.endsWith('.pdf'));

const isText = computed(
  () =>
    normalizedMime.value === 'text/plain' ||
    normalizedMime.value === 'application/json' ||
    lowerName.value.endsWith('.txt') ||
    lowerName.value.endsWith('.json'),
);

async function loadTextPreview(): Promise<void> {
  textContent.value = null;
  textError.value = false;
  if (!isText.value || !isSafeUrl.value || !props.src) return;
  if (props.sizeBytes && props.sizeBytes > 1024 * 1024) {
    return;
  }
  textLoading.value = true;
  try {
    const res = await fetch(props.src);
    if (!res.ok) {
      textError.value = true;
      return;
    }
    textContent.value = await res.text();
  } catch {
    textError.value = true;
  } finally {
    textLoading.value = false;
  }
}

watch(
  () => [props.src, props.mime, props.name] as const,
  () => {
    void loadTextPreview();
  },
  { immediate: true },
);
</script>

<template>
  <div class="app-document-preview">
    <div
      v-if="!isSafeUrl"
      class="app-document-preview__fallback"
      role="alert"
    >
      <p>{{ t('media.unavailable') }}</p>
    </div>

    <div
      v-else-if="isPdf"
      class="app-document-preview__pdf-wrap"
    >
      <iframe
        :src="src"
        :title="name"
        class="app-document-preview__pdf"
        referrerpolicy="no-referrer"
      />
      <div class="app-document-preview__actions">
        <a
          :href="src"
          target="_blank"
          rel="noopener noreferrer"
          class="app-document-preview__action"
        >
          {{ t('media.openInNewTab') }}
        </a>
        <a
          :href="src"
          :download="name"
          class="app-document-preview__action"
        >
          {{ t('media.download') }}
        </a>
      </div>
    </div>

    <div
      v-else-if="isText"
      class="app-document-preview__text-wrap"
    >
      <div
        v-if="textLoading"
        class="app-document-preview__fallback"
      >
        {{ t('media.loading') }}
      </div>
      <div
        v-else-if="textError || textContent === null"
        class="app-document-preview__fallback"
      >
        <p>{{ t('media.unsupportedPreview') }}</p>
        <a
          :href="src"
          :download="name"
          class="app-document-preview__action"
        >
          {{ t('media.download') }}
        </a>
      </div>
      <pre
        v-else
        class="app-document-preview__pre"
      >{{ textContent }}</pre>
    </div>

    <div
      v-else
      class="app-document-preview__fallback"
    >
      <p class="app-document-preview__name">{{ name }}</p>
      <p>{{ t('media.unsupportedPreview') }}</p>
      <a
        :href="src"
        :download="name"
        class="app-document-preview__action"
      >
        {{ t('media.download') }}
      </a>
    </div>
  </div>
</template>

<style scoped>
.app-document-preview {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.app-document-preview__pdf-wrap {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.app-document-preview__pdf {
  width: 100%;
  height: 64vh;
  min-height: 360px;
  border: 1px solid var(--border-default, rgba(255, 255, 255, 0.12));
  border-radius: var(--radius-card-m, 12px);
  background: #fff;
}

.app-document-preview__actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
}

.app-document-preview__action {
  display: inline-flex;
  align-items: center;
  padding: 6px 12px;
  border-radius: 8px;
  background: var(--surface-2, var(--surface-secondary, rgba(127, 127, 127, 0.14)));
  color: var(--text-1, var(--text-primary, inherit));
  border: 1px solid var(--border, var(--border-default, rgba(127, 127, 127, 0.25)));
  text-decoration: none;
  font-size: 13px;
}

.app-document-preview__pre {
  margin: 0;
  padding: 14px;
  max-height: 60vh;
  overflow: auto;
  border-radius: var(--radius-card-m, 12px);
  border: 1px solid var(--border-default, rgba(255, 255, 255, 0.12));
  background: var(--surface-secondary, #10141c);
  color: var(--text-primary, #fff);
  font-family: var(--font-mono, monospace);
  font-size: 12px;
  white-space: pre-wrap;
  word-break: break-word;
}

.app-document-preview__fallback {
  padding: 24px;
  text-align: center;
  border-radius: var(--radius-card-m, 12px);
  border: 1px solid var(--border-default, rgba(255, 255, 255, 0.12));
  background: var(--surface-secondary, rgba(255, 255, 255, 0.04));
  color: var(--text-secondary, #9ba3af);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

.app-document-preview__name {
  color: var(--text-primary, #fff);
  font-weight: 600;
  margin: 0;
}
</style>
