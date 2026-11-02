<script setup lang="ts">
import { RouterLink } from 'vue-router';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
</script>

<template>
  <section class="admin-finance">
    <header class="admin-finance__head">
      <h2>{{ $t('admin.finance') }}</h2>
      <p>{{ $t('admin.financeSubtitle') }}</p>
    </header>
    <AppCard>
      <div class="admin-finance__blocked">
        <h3>{{ $t('admin.financeBlockedTitle') }}</h3>
        <p>{{ $t('admin.financeBlockedText') }}</p>
        <RouterLink :to="{ name: 'catalog' }">{{ $t('admin.customerSite') }}</RouterLink>
      </div>
    </AppCard>
  </section>
</template>

<style scoped>
.admin-finance { display: grid; gap: var(--space-16); }
.admin-finance__head h2 { font-size: var(--type-h2-size); line-height: var(--type-h2-line); font-weight: var(--type-h2-weight); color: var(--text-1); }
.admin-finance__head p { margin-top: var(--space-4); font-size: var(--type-body-medium-size); line-height: var(--type-body-medium-line); color: var(--text-2); }
.admin-finance__blocked { display: grid; gap: var(--space-12); max-width: 760px; }
.admin-finance__blocked h3 { font-size: var(--type-h3-size); line-height: var(--type-h3-line); font-weight: var(--type-h3-weight); color: var(--text-1); }
.admin-finance__blocked p { font-size: var(--type-body-medium-size); line-height: var(--type-body-medium-line); color: var(--text-2); }
</style>
