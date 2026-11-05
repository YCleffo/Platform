<script setup lang="ts">
import { isObject, type ConfigValue } from '@/shared/api/core/configuration';
defineProps<{ value: ConfigValue }>();
</script>
<template>
  <ul
    v-if="Array.isArray(value) && value.length"
    class="config-value-list"
  >
    <li
      v-for="(item, index) in value"
      :key="index"
    >
      <ConfigValueView :value="item" />
    </li>
  </ul>
  <dl
    v-else-if="isObject(value) && Object.keys(value).length"
    class="config-value-list"
  >
    <div
      v-for="(item, key) in value"
      :key="key"
    >
      <dt>{{ key }}</dt>
      <dd><ConfigValueView :value="item as ConfigValue" /></dd>
    </div>
  </dl>
  <span
    v-else
    class="config-value-text"
  >
    {{
      value === null
        ? $t('configuration.type_null')
        : value === true
          ? $t('configuration.yes')
          : value === false
            ? $t('configuration.no')
            : Array.isArray(value)
              ? $t('configuration.emptyArray')
              : isObject(value)
                ? $t('configuration.emptyObject')
                : value === ''
                  ? $t('configuration.emptyText')
                  : value
    }}
  </span>
</template>
<style scoped>
.config-value-list {
  display: grid;
  gap: var(--space-8);
  padding-inline-start: var(--space-16);
  margin: 0;
  min-width: 0;
}
.config-value-list dt {
  font-weight: var(--type-body-medium-emphasis-weight);
  overflow-wrap: anywhere;
}
.config-value-list dd {
  margin: var(--space-4) 0 0;
  min-width: 0;
}
.config-value-text {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
</style>
