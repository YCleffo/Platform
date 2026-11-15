<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppDropdown from '@/shared/ui/fields/AppDropdown.vue';
import AppConfirmDialog from '@/shared/ui/overlays/AppConfirmDialog.vue';
import { useEducationWorkspace } from '../workspace';
import { useEducationI18n } from '../i18n';
import { useEducationError } from '../resources';
import EducationState from '../EducationState.vue';
import HomeworkFiles from './HomeworkFiles.vue';
import HomeworkAnswerHistory from './HomeworkAnswerHistory.vue';
import { homeworkApi } from './api';
import { useHomeworkMutation, useHomeworkResource } from './resources';
const workspace = useEducationWorkspace(), route = useRoute(), { t } = useEducationI18n(), issue = useEducationError();
const id = computed(() => String(route.params.id ?? ''));
const { data, loading, error, refresh } = useHomeworkResource(() => workspace.organizationId, (org, signal) => homeworkApi.answer(org, id.value, signal), [id]);
const { busy, error: actionError, uncertain, run } = useHomeworkMutation(() => workspace.organizationId, () => id.value);
const comment = ref(''), resultStatus = ref(''), localError = ref(''), confirm = ref(false), decision = ref<'accepted' | 'returned'>('accepted');
const keys = new Map<string, string>();
const reviewable = computed(() => data.value && ['SUBMITTED', 'RESUBMITTED'].includes(data.value.submission.state));
watch(id, () => { comment.value = ''; resultStatus.value = ''; localError.value = ''; confirm.value = false; keys.clear(); });
function propose(value: 'accepted' | 'returned'): void { localError.value = ''; if (value === 'returned' && !comment.value.trim()) { localError.value = t('education.homeworkReturnComment'); return; } decision.value = value; confirm.value = true; }
async function review(): Promise<void> {
  if (!data.value || !reviewable.value) return;
  const version = data.value.submission.version, key = `${id.value}:${version}:${decision.value}`;
  if (!keys.has(key)) keys.set(key, crypto.randomUUID());
  await run(() => homeworkApi.review(workspace.organizationId, id.value, { version, idempotencyKey: keys.get(key)!, decision: decision.value, comment: comment.value.trim(), resultStatus: decision.value === 'accepted' ? resultStatus.value || null : null }), async () => { confirm.value = false; comment.value = ''; await refresh(); void workspace.refreshHeader(); });
}
async function reconcile(): Promise<void> { await refresh(); if (data.value && !error.value) { uncertain.value = false; actionError.value = null; } }
</script>
<template>
  <section><header class="edu-page-title"><h1>{{ t('education.homeworkReview') }}</h1><RouterLink v-if="data" :to="`/homework/${data.assignment.id}`" class="edu-link">{{ t('education.homeworkBack') }}</RouterLink></header><EducationState :loading="loading" :error="error" @retry="refresh" />
    <template v-if="data"><article class="edu-card"><h2>{{ data.assignment.title }}</h2><p>{{ data.recipient.name }} · {{ t(`education.homework${data.submission.state}`) }}</p><p class="edu-homework-prose">{{ data.assignment.instructions }}</p><h3>{{ t('education.homeworkAnswer') }}</h3><p class="edu-homework-prose">{{ data.submission.currentVersion?.text || t('education.homeworkNoAnswer') }}</p><p v-if="data.submission.currentVersion?.late" class="edu-error">{{ t('education.homeworkLate') }}</p><HomeworkFiles :organization-id="workspace.organizationId" :assignment-id="data.assignment.id" :files="data.submission.currentVersion?.files ?? []" :title="t('education.homeworkAttachments')" /><p v-if="data.submission.activityResult"><strong>{{ t('education.homeworkActivityResult') }}: {{ data.submission.activityResult.wpm }} {{ t('education.wpm') }}</strong> · {{ data.submission.activityResult.accuracy }}% · {{ data.submission.activityResult.errors }} {{ t('education.errors') }}</p></article>
      <form v-if="reviewable" class="edu-card edu-form" @submit.prevent="propose('accepted')"><label class="edu-homework-field"><span>{{ t('education.homeworkReviewComment') }}</span><textarea v-model="comment" class="edu-input" rows="5" maxlength="5000" :disabled="busy || uncertain" /></label><AppDropdown v-model="resultStatus" :label="t('education.results')" clearable :options="(['mastered', 'developing', 'needs_support'] as const).map(value => ({ value, label: t(`education.${value === 'needs_support' ? 'needsSupport' : value}`) }))" :disabled="busy || uncertain" /><p v-if="localError" class="edu-error" role="alert">{{ localError }}</p><div class="edu-form-actions"><AppButton type="submit" :disabled="busy || uncertain">{{ t('education.homeworkAccept') }}</AppButton><AppButton variant="secondary" :disabled="busy || uncertain" @click="propose('returned')">{{ t('education.homeworkReturn') }}</AppButton></div></form>
      <HomeworkAnswerHistory :submission="data.submission" :organization-id="workspace.organizationId" :assignment-id="data.assignment.id" />
    </template><p v-if="actionError" class="edu-error" role="alert">{{ issue(actionError) }}</p><p v-if="uncertain" role="alert">{{ t('education.homeworkUncertain') }}<AppButton variant="secondary" @click="reconcile">{{ t('education.homeworkReload') }}</AppButton></p><AppConfirmDialog v-model="confirm" :title="t(decision === 'accepted' ? 'education.homeworkAccept' : 'education.homeworkReturn')" :confirm-label="t(decision === 'accepted' ? 'education.homeworkAccept' : 'education.homeworkReturn')" :busy="busy" @confirm="review" />
  </section>
</template>
