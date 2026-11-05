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
        <h3>{{ $t('operator.signIn') }}</h3>
        <p>{{ $t('operator.boundary') }}</p>
        <RouterLink :to="{ name: 'operator-console' }">
          {{ $t('operator.openWorkspace') }}
        </RouterLink>
      </div>
    </AppCard>
  </section>
</template>

<style scoped>
.admin-finance {
  display: grid;
  gap: var(--space-16);
}
.admin-finance__head h2 {
  font-size: var(--type-h2-size);
  letter-spacing: var(--type-h2-tracking);
  line-height: var(--type-h2-line);
  font-weight: var(--type-h2-weight);
  color: var(--text-1);
}
.admin-finance__head p {
  margin-top: var(--space-4);
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
}
.admin-finance__blocked {
  display: grid;
  gap: var(--space-12);
  max-width: 760px;
}
.admin-finance__blocked h3 {
  font-size: var(--type-h3-size);
  letter-spacing: var(--type-h3-tracking);
  line-height: var(--type-h3-line);
  font-weight: var(--type-h3-weight);
  color: var(--text-1);
}
.admin-finance__blocked p {
  font-size: var(--type-body-medium-size);
  font-weight: var(--type-body-medium-weight);
  letter-spacing: var(--type-body-medium-tracking);
  line-height: var(--type-body-medium-line);
  color: var(--text-2);
}
</style>
