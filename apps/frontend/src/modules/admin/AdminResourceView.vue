<script setup lang="ts">
import ResourceTable from './workbench/ResourceTable.vue';
import type { Resource } from './workbench/catalog';
defineProps<{ config: Resource }>();
</script>
<template><ResourceTable :config="config" /></template>
