<script setup lang="ts">
import { useI18n } from 'vue-i18n';
defineProps<{ data: unknown; depth?: number }>();
const { t, te } = useI18n();
function canonical(key: string): string {
  return key.replace(/_([a-z])/g, (_, letter: string) => letter.toUpperCase());
}
function label(key: string): string {
  const name = canonical(key);
  return te('workbench.fields.' + name)
    ? t('workbench.fields.' + name)
    : name.replace(/([a-z])([A-Z])/g, '$1 $2');
}
function display(key: string, value: unknown): unknown {
  if (value === null) return '—';
  if (typeof value === 'boolean') return t(value ? 'configuration.yes' : 'configuration.no');
  if (typeof value === 'string' && /status|state|Type|kind/i.test(canonical(key))) {
    if (te('operator.states.' + value)) return t('operator.states.' + value);
    if (te('workbench.options.' + value)) return t('workbench.options.' + value);
  }
  return value;
}
</script>
<template>
  <div class="wb-record">
    <template v-if="Array.isArray(data)">
      <p v-if="!data.length">{{ t('workbench.noRecords') }}</p>
      <RecordView
        v-for="(item, index) in data"
        :key="index"
        :data="item"
        :depth="(depth ?? 0) + 1"
      />
    </template>
    <dl v-else-if="data !== null && typeof data === 'object' && (depth ?? 0) < 8">
      <div
        v-for="(value, key) in data"
        :key="String(key)"
      >
        <dt>{{ label(String(key)) }}</dt>
        <dd>
          <RecordView
            v-if="value !== null && typeof value === 'object'"
            :data="value"
            :depth="(depth ?? 0) + 1"
          />
          <span v-else>
            {{ display(String(key), value) }}
          </span>
        </dd>
      </div>
    </dl>
    <span v-else>{{ data === null ? '—' : data }}</span>
  </div>
</template>
<style scoped>
.wb-record {
  min-width: 0;
  overflow-wrap: anywhere;
}
.wb-record dl {
  display: grid;
  gap: var(--space-12);
}
.wb-record dt {
  color: var(--text-2);
  font-size: var(--type-body-small-size);
  line-height: var(--type-body-small-line);
  font-weight: var(--type-body-small-weight);
}
.wb-record dd {
  margin-top: var(--space-4);
}
.wb-record > .wb-record {
  padding: var(--space-12);
  margin-bottom: var(--space-8);
  border: 1px solid var(--border);
  border-radius: var(--radius-control-m);
}
</style>
