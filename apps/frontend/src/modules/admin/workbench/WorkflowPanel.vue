<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
import { coreAdminSession } from '@/core/admin/session';
import { coreOperations } from './catalog';
import type { Operation } from './form';
import OperationDialog from './OperationDialog.vue';
import AppCard from '@/shared/ui/surfaces/AppCard.vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
const { t } = useI18n();
const auth = useAdminAuthStore();
const selected = ref<Operation | null>(null);
const open = ref(false);
const groups = computed(() =>
  ['representation', 'erasure', 'incident', 'distribution']
    .map((key) => ({
      key,
      operations: coreOperations.filter(
        (op) => op.key.startsWith(key + '.') && (!op.permission || auth.can(op.permission)),
      ),
    }))
    .filter((group) => group.operations.length),
);
</script>
<template>
  <div class="wb-workflows">
    <AppCard
      v-for="group in groups"
      :key="group.key"
    >
      <h3>{{ t('workbench.groups.' + group.key) }}</h3>
      <p>{{ t('workbench.hints.' + group.key) }}</p>
      <div class="wb-workflows__actions">
        <AppButton
          v-for="op in group.operations"
          :key="op.key"
          :variant="op.danger ? 'danger' : 'secondary'"
          @click="
            selected = op;
            open = true;
          "
        >
          {{ t(op.label) }}
        </AppButton>
      </div>
    </AppCard>
    <OperationDialog
      v-model="open"
      :operation="selected"
      :row="{}"
      :session-key="coreAdminSession.epoch()"
    />
  </div>
</template>
<style scoped>
.wb-workflows {
  display: grid;
  gap: var(--space-16);
  min-width: 0;
}
.wb-workflows h3 {
  margin-bottom: var(--space-8);
}
.wb-workflows__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-8);
  margin-top: var(--space-16);
}
</style>
