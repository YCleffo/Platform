<script setup lang="ts">
import { useRouter } from 'vue-router';
import { useEducationI18n } from '../i18n';
import HomeworkForm from './HomeworkForm.vue';
const router = useRouter(), { t } = useEducationI18n();
</script>
<template><section><header class="edu-page-title"><h1>{{ t('education.createHomework') }}</h1></header><HomeworkForm @saved="detail => router.replace(`/homework/${detail.assignment.id}`)" @cancel="router.push('/homework')" /></section></template>
