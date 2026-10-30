<script setup lang="ts">
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import AppIconButton from '@/shared/ui/actions/AppIconButton.vue';

interface Props {
  page: number;
  pageCount: number;
  hasPrev: boolean;
  hasNext: boolean;
  rangeLabel: string;
}

defineProps<Props>();

defineEmits<{
  prev: [];
  next: [];
}>();
</script>

<template>
  <nav class="account-pagination" :aria-label="$t('cabinet.page', { page, pages: pageCount })">
    <span class="account-pagination__range">{{ rangeLabel }}</span>
    <div class="account-pagination__controls">
      <AppIconButton :label="$t('cabinet.prev')" :disabled="!hasPrev" @click="$emit('prev')">
        <ChevronLeft :size="18" aria-hidden="true" />
      </AppIconButton>
      <span class="account-pagination__page">{{ $t('cabinet.page', { page, pages: pageCount }) }}</span>
      <AppIconButton :label="$t('cabinet.next')" :disabled="!hasNext" @click="$emit('next')">
        <ChevronRight :size="18" aria-hidden="true" />
      </AppIconButton>
    </div>
  </nav>
</template>

<style scoped>
.account-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-12);
  margin-top: var(--space-20);
  flex-wrap: wrap;
}

.account-pagination__range {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
}

.account-pagination__controls {
  display: flex;
  align-items: center;
  gap: var(--space-8);
}

.account-pagination__page {
  font-size: var(--type-body-medium-size);
  line-height: var(--type-body-medium-line);
  font-weight: var(--type-body-medium-weight);
  color: var(--text-2);
  white-space: nowrap;
}
</style>
