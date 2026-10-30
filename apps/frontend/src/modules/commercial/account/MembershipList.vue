<script setup lang="ts">
import MembershipCard from './MembershipCard.vue';
import type { CommercialMembership } from '@/shared/types';

defineProps<{
  memberships: CommercialMembership[];
  manageableIds: string[];
  removableIds: string[];
}>();

defineEmits<{
  'change-role': [membership: CommercialMembership];
  remove: [membership: CommercialMembership];
}>();
</script>

<template>
  <ul class="members" role="list">
    <MembershipCard
      v-for="membership in memberships"
      :key="membership.id"
      :membership="membership"
      :can-manage="manageableIds.includes(membership.id)"
      :can-remove="removableIds.includes(membership.id)"
      @change-role="$emit('change-role', membership)"
      @remove="$emit('remove', membership)"
    />
  </ul>
</template>

<style scoped>
.members {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--space-12);
}

@media (min-width: 768px) {
  .members {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--space-16);
  }
}
</style>
