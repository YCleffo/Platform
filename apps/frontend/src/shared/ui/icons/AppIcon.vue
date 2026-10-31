<script setup lang="ts">
import spriteUrl from './sprite.svg?url';

withDefaults(defineProps<{ name: string; size?: number | string }>(), { size: 24 });
</script>

<template>
  <svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    fill="none"
    :width="size"
    :height="size"
    aria-hidden="true"
    focusable="false"
  >
    <use :href="`${spriteUrl}#${name}`" />
  </svg>
</template>
