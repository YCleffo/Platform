<script setup lang="ts">
import { onScopeDispose, ref } from 'vue';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import { homeworkApi, type HomeworkFile } from './api';
import { useHomeworkMutation } from './resources';
import { useEducationError } from '../resources';
import { useEducationI18n } from '../i18n';
const props = defineProps<{ organizationId: string; assignmentId: string; files: HomeworkFile[]; editable?: boolean; disabled?: boolean; student?: boolean; title: string }>();
const emit = defineEmits<{ changed: [] }>();
const { t } = useEducationI18n();
const issue = useEducationError();
const { busy, error, uncertain, run } = useHomeworkMutation(() => props.organizationId, () => props.assignmentId);
const validation = ref('');
const urls = new Set<string>();
async function upload(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement, file = input.files?.[0];
  validation.value = ''; if (!file) return;
  if (!file.size || file.size > 10 * 1024 * 1024 || !/\.(pdf|png|jpe?g|webp|txt)$/i.test(file.name)) { validation.value = t('education.homeworkFileHint'); input.value = ''; return; }
  await run(() => homeworkApi.upload(props.organizationId, props.assignmentId, file, props.student), () => { emit('changed'); input.value = ''; });
}
async function download(file: HomeworkFile): Promise<void> {
  await run(() => homeworkApi.download(props.organizationId, file.id), blob => {
    const url = URL.createObjectURL(blob); urls.add(url);
    const link = document.createElement('a'); link.href = url; link.download = file.name; link.click();
    window.setTimeout(() => { URL.revokeObjectURL(url); urls.delete(url); }, 1000);
  });
}
async function remove(file: HomeworkFile): Promise<void> { await run(() => homeworkApi.removeFile(props.organizationId, props.assignmentId, file.id), () => emit('changed')); }
onScopeDispose(() => { urls.forEach(url => URL.revokeObjectURL(url)); urls.clear(); });
</script>
<template>
  <section class="edu-homework-files">
    <h3>{{ title }}</h3>
    <ul v-if="files.length" class="edu-list">
      <li v-for="file in files" :key="file.id" class="edu-list__item">
        <button type="button" class="edu-link" :disabled="busy" @click="download(file)">{{ file.name }} · {{ Math.ceil(file.sizeBytes / 1024) }} KB</button>
        <AppButton v-if="editable && student" variant="secondary" :disabled="busy || uncertain || disabled" @click="remove(file)">{{ t('education.homeworkDeleteFile') }}</AppButton>
      </li>
    </ul>
    <label v-if="editable" class="edu-homework-upload">
      <span>{{ t('education.homeworkUpload') }}</span>
      <input type="file" :disabled="busy || uncertain || disabled" accept=".pdf,.png,.jpg,.jpeg,.webp,.txt" @change="upload" />
      <small class="edu-subtle">{{ t('education.homeworkFileHint') }}</small>
    </label>
    <p v-if="validation || error" class="edu-error" role="alert">{{ validation || issue(error) }}</p>
    <p v-if="uncertain" role="alert">{{ t('education.homeworkUncertain') }} <button type="button" class="edu-link" @click="emit('changed'); uncertain = false">{{ t('education.homeworkReload') }}</button></p>
  </section>
</template>
