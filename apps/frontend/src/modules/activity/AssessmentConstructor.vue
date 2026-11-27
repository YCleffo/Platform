<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import { useEducationI18n } from '@/modules/education/i18n';
import { assessmentApi } from '@/modules/education/assessment/assessmentApi';
import QuestionBankDrawer from '@/modules/education/assessment/QuestionBankDrawer.vue';
import AssessmentRunnerPlugin from '@/modules/activity/plugins/AssessmentRunnerPlugin.vue';
import { activityApi, type ActivityItem } from './activityApi';
import type {
  AssessmentItem,
  AssessmentQuestion,
  AssessmentVersion,
  QuestionType,
} from '@/modules/education/activityTypes';

const props = withDefaults(
  defineProps<{
    mode?: 'education' | 'activity';
    assessmentId?: string;
    organizationId?: string;
    showBackButton?: boolean;
  }>(),
  {
    mode: 'education',
    assessmentId: undefined,
    organizationId: '',
    showBackButton: true,
  },
);

const emit = defineEmits<{
  saved: [item: AssessmentItem | ActivityItem];
  published: [item: (AssessmentItem & AssessmentVersion) | ActivityItem];
  duplicated: [id: string];
  cancel: [];
}>();

const { t } = useEducationI18n();

const currentId = ref<string | undefined>(props.assessmentId);
const title = ref('');
const description = ref('');
const instructions = ref('');
const status = ref<'DRAFT' | 'PUBLISHED' | 'CLOSED' | 'ARCHIVED'>('DRAFT');
const versionNumber = ref<number>(1);
const openPractice = ref(false);
const passingScorePercent = ref(60);
const timeLimitMinutes = ref<number | null>(30);
const maxAttempts = ref(1);
const shuffleQuestions = ref(false);
const shuffleOptions = ref(false);
const revealAnswersPolicy = ref<'never' | 'immediate' | 'after_grading' | 'deadline'>(
  'after_grading',
);
const gradingPolicy = ref<'highest' | 'latest' | 'average'>('highest');
const questions = ref<AssessmentQuestion[]>([]);

const loading = ref(false);
const saving = ref(false);
const publishing = ref(false);
const previewMode = ref(false);
const editingNewVersion = ref(false);
const showBankDrawer = ref(false);
const showVersions = ref(false);
const versions = ref<Array<{ id: string; versionNumber: number; questionsCount?: number; attemptsCount?: number; createdAt: string }>>([]);
const toastMessage = ref<string | null>(null);
const errorMessage = ref<string | null>(null);
const initialSnapshot = ref('');

let uidCounter = 0;
function nextId(prefix: string): string {
  uidCounter += 1;
  return `${prefix}_${Date.now()}_${uidCounter}_${Math.random().toString(36).slice(2, 6)}`;
}

const QUESTION_TYPES: QuestionType[] = [
  'single_choice',
  'multi_choice',
  'true_false',
  'short_text',
  'long_text',
  'numeric',
  'matching',
  'ordering',
];

const isPublishedLocked = computed(
  () => status.value === 'PUBLISHED' && !editingNewVersion.value,
);

const isReadOnly = computed(() => isPublishedLocked.value || status.value === 'CLOSED' || status.value === 'ARCHIVED');

const totalPoints = computed(() =>
  questions.value.reduce((acc, q) => acc + Math.max(1, Number(q.points) || 1), 0),
);

function buildSnapshot(): string {
  return JSON.stringify({
    title: title.value,
    description: description.value,
    instructions: instructions.value,
    openPractice: openPractice.value,
    passingScorePercent: passingScorePercent.value,
    timeLimitMinutes: timeLimitMinutes.value,
    maxAttempts: maxAttempts.value,
    shuffleQuestions: shuffleQuestions.value,
    shuffleOptions: shuffleOptions.value,
    revealAnswersPolicy: revealAnswersPolicy.value,
    gradingPolicy: gradingPolicy.value,
    questions: questions.value,
  });
}

const isDirty = computed(() => {
  if (loading.value) return false;
  if (!initialSnapshot.value) return false;
  return buildSnapshot() !== initialSnapshot.value;
});

function markClean(): void {
  initialSnapshot.value = buildSnapshot();
}

function showToast(msg: string): void {
  toastMessage.value = msg;
  setTimeout(() => {
    if (toastMessage.value === msg) {
      toastMessage.value = null;
    }
  }, 3500);
}

function mapServerError(err: unknown): string {
  const raw = err instanceof Error ? err.message : String(err ?? '');
  if (raw.includes('published_version_immutable')) return t('education.errorPublishedImmutable');
  if (raw.includes('access_denied') || raw.includes('forbidden')) return t('education.errorAccessDenied');
  if (raw.includes('title_required')) return t('education.validationTitleRequired');
  if (raw.includes('empty_assessment_questions')) return t('education.validationQuestionsRequired');
  if (raw.includes('insufficient_matching_pairs') || raw.includes('invalid_matching_pair')) {
    return t('education.validationPairsRequired', { num: '?' });
  }
  if (raw.includes('insufficient_ordering_items') || raw.includes('invalid_ordering_item')) {
    return t('education.validationOrderingRequired', { num: '?' });
  }
  return t('education.errorSaveFailed');
}

function normalizeLoadedQuestion(raw: Record<string, unknown>): AssessmentQuestion {
  const type = String(raw.type ?? raw.questionType ?? 'single_choice') as QuestionType;
  const content = (typeof raw.content === 'object' && raw.content !== null ? raw.content : {}) as Record<string, unknown>;
  const prompt = String(raw.prompt ?? content.prompt ?? '');
  const points = Number(raw.points ?? raw.defaultPoints ?? 1) || 1;
  const explanation = typeof raw.explanation === 'string' ? raw.explanation : '';
  const tolerance = typeof raw.tolerance === 'number'
    ? raw.tolerance
    : typeof content.tolerance === 'number'
      ? content.tolerance
      : undefined;

  const rawOptions = Array.isArray(raw.options)
    ? raw.options
    : Array.isArray(content.options)
      ? content.options
      : undefined;
  const options = rawOptions?.map((opt) => {
    if (typeof opt === 'string') {
      return { id: opt, label: opt };
    }
    const o = opt as Record<string, unknown>;
    return {
      id: String(o.id ?? o.value ?? nextId('opt')),
      label: String(o.label ?? o.value ?? o.id ?? ''),
    };
  });

  const rawPairs = Array.isArray(raw.pairs)
    ? raw.pairs
    : Array.isArray(content.pairs)
      ? content.pairs
      : undefined;
  const pairs = rawPairs?.map((p) => {
    const pair = p as Record<string, unknown>;
    return { left: String(pair.left ?? ''), right: String(pair.right ?? '') };
  });

  const rawItems = Array.isArray(raw.items)
    ? raw.items
    : Array.isArray(content.items)
      ? content.items
      : undefined;
  const items = rawItems?.map((it) => String(it));

  return {
    id: String(raw.id ?? nextId('q')),
    type,
    prompt,
    points,
    options,
    pairs,
    items,
    tolerance,
    answerKey: raw.answerKey ?? raw.answer_key,
    explanation,
  };
}

function addQuestion(type: QuestionType): void {
  if (isReadOnly.value) return;
  const newQ: AssessmentQuestion = {
    id: nextId('q'),
    type,
    prompt: '',
    points: 1,
    explanation: '',
  };

  if (type === 'single_choice' || type === 'multi_choice') {
    const opt1 = nextId('opt');
    const opt2 = nextId('opt');
    newQ.options = [
      { id: opt1, label: '' },
      { id: opt2, label: '' },
    ];
    newQ.answerKey = type === 'single_choice' ? opt1 : [opt1];
  } else if (type === 'true_false') {
    newQ.answerKey = true;
  } else if (type === 'numeric') {
    newQ.answerKey = 0;
    newQ.tolerance = 0;
  } else if (type === 'short_text') {
    newQ.answerKey = '';
  } else if (type === 'long_text') {
    newQ.answerKey = null;
  } else if (type === 'matching') {
    newQ.pairs = [
      { left: '', right: '' },
      { left: '', right: '' },
    ];
    newQ.answerKey = {};
  } else if (type === 'ordering') {
    newQ.items = ['', ''];
    newQ.answerKey = ['', ''];
  }

  questions.value.push(newQ);
}

function duplicateQuestion(index: number): void {
  if (isReadOnly.value) return;
  const src = questions.value[index];
  if (!src) return;
  const idMap = new Map<string, string>();
  const clonedOptions = src.options?.map((opt) => {
    const freshId = nextId('opt');
    idMap.set(opt.id, freshId);
    return { id: freshId, label: opt.label };
  });

  let clonedAnswerKey = src.answerKey;
  if (src.type === 'single_choice' && typeof src.answerKey === 'string') {
    clonedAnswerKey = idMap.get(src.answerKey) ?? clonedOptions?.[0]?.id;
  } else if (src.type === 'multi_choice' && Array.isArray(src.answerKey)) {
    clonedAnswerKey = src.answerKey.map((k) => idMap.get(String(k)) ?? String(k));
  } else if (src.type === 'matching' && src.answerKey && typeof src.answerKey === 'object') {
    clonedAnswerKey = { ...(src.answerKey as Record<string, string>) };
  } else if (src.type === 'ordering' && Array.isArray(src.answerKey)) {
    clonedAnswerKey = [...src.answerKey];
  }

  const clone: AssessmentQuestion = {
    ...src,
    id: nextId('q'),
    options: clonedOptions,
    pairs: src.pairs?.map((p) => ({ ...p })),
    items: src.items ? [...src.items] : undefined,
    answerKey: clonedAnswerKey,
  };
  questions.value.splice(index + 1, 0, clone);
}

function removeQuestion(index: number): void {
  if (isReadOnly.value) return;
  questions.value.splice(index, 1);
}

function moveQuestion(index: number, direction: -1 | 1): void {
  if (isReadOnly.value) return;
  const target = index + direction;
  if (target < 0 || target >= questions.value.length) return;
  const currentQ = questions.value[index];
  const targetQ = questions.value[target];
  if (!currentQ || !targetQ) return;
  questions.value[index] = targetQ;
  questions.value[target] = currentQ;
}

function addOption(question: AssessmentQuestion): void {
  if (isReadOnly.value) return;
  if (!question.options) question.options = [];
  question.options.push({ id: nextId('opt'), label: '' });
}

function removeOption(question: AssessmentQuestion, optIndex: number): void {
  if (isReadOnly.value) return;
  if (!question.options || question.options.length <= 2) return;
  const removed = question.options.splice(optIndex, 1)[0];
  if (!removed) return;
  if (question.type === 'single_choice' && question.answerKey === removed.id) {
    question.answerKey = question.options[0]?.id;
  } else if (question.type === 'multi_choice' && Array.isArray(question.answerKey)) {
    question.answerKey = question.answerKey.filter((id) => id !== removed.id);
  }
}

function toggleMultiOption(question: AssessmentQuestion, optId: string): void {
  if (isReadOnly.value) return;
  if (!Array.isArray(question.answerKey)) {
    question.answerKey = [optId];
    return;
  }
  const current = [...(question.answerKey as string[])];
  const idx = current.indexOf(optId);
  if (idx >= 0) {
    if (current.length > 1) {
      current.splice(idx, 1);
    }
  } else {
    current.push(optId);
  }
  question.answerKey = current;
}

function syncMatchingAnswerKey(question: AssessmentQuestion): void {
  const map: Record<string, string> = {};
  for (const pair of question.pairs ?? []) {
    const left = pair.left.trim();
    if (left !== '') {
      map[left] = pair.right.trim();
    }
  }
  question.answerKey = map;
}

function addPair(question: AssessmentQuestion): void {
  if (isReadOnly.value) return;
  if (!question.pairs) question.pairs = [];
  question.pairs.push({ left: '', right: '' });
  syncMatchingAnswerKey(question);
}

function removePair(question: AssessmentQuestion, idx: number): void {
  if (isReadOnly.value) return;
  if (!question.pairs || question.pairs.length <= 2) return;
  question.pairs.splice(idx, 1);
  syncMatchingAnswerKey(question);
}

function syncOrderingAnswerKey(question: AssessmentQuestion): void {
  question.answerKey = [...(question.items ?? [])];
}

function addOrderingItem(question: AssessmentQuestion): void {
  if (isReadOnly.value) return;
  if (!question.items) question.items = [];
  question.items.push('');
  syncOrderingAnswerKey(question);
}

function removeOrderingItem(question: AssessmentQuestion, idx: number): void {
  if (isReadOnly.value) return;
  if (!question.items || question.items.length <= 2) return;
  question.items.splice(idx, 1);
  syncOrderingAnswerKey(question);
}

function moveOrderingItem(question: AssessmentQuestion, idx: number, dir: -1 | 1): void {
  if (isReadOnly.value || !question.items) return;
  const target = idx + dir;
  if (target < 0 || target >= question.items.length) return;
  const cur = question.items[idx]!;
  question.items[idx] = question.items[target]!;
  question.items[target] = cur;
  syncOrderingAnswerKey(question);
}

function onImportFromBank(q: AssessmentQuestion): void {
  if (isReadOnly.value) return;
  questions.value.push(q);
  showBankDrawer.value = false;
  showToast(t('education.importedFromBankNotice'));
}

async function saveToBank(question: AssessmentQuestion): Promise<void> {
  errorMessage.value = null;
  const content: Record<string, unknown> = {
    prompt: question.prompt,
    options: question.options,
    pairs: question.pairs,
    items: question.items,
    tolerance: question.tolerance,
  };
  const payload = {
    questionType: question.type,
    content,
    answerKey: question.answerKey ?? (question.type === 'long_text' ? '' : null),
    explanation: question.explanation ?? '',
    defaultPoints: Math.max(1, Number(question.points) || 1),
    difficulty: 1,
    category: 'general',
  };

  try {
    if (props.mode === 'activity' && props.organizationId) {
      await activityApi.createQuestionBankItem(props.organizationId, payload);
    } else {
      await assessmentApi.createQuestionBankItem(payload);
    }
    showToast(t('education.savedToBankNotice'));
  } catch (err) {
    errorMessage.value = mapServerError(err);
  }
}

function validateBeforeSave(forPublish: boolean): string | null {
  if (!title.value.trim()) {
    return t('education.validationTitleRequired');
  }
  if (!forPublish) {
    return null;
  }
  if (questions.value.length === 0) {
    return t('education.validationQuestionsRequired');
  }
  for (let i = 0; i < questions.value.length; i += 1) {
    const q = questions.value[i]!;
    const num = i + 1;
    if (!q.prompt.trim()) {
      return t('education.validationPromptRequired', { num });
    }
    if (q.type === 'single_choice' || q.type === 'multi_choice') {
      const opts = q.options ?? [];
      if (opts.length < 2 || opts.some((o) => !o.label.trim())) {
        return t('education.validationOptionsRequired', { num });
      }
      if (q.type === 'single_choice') {
        if (!q.answerKey || !opts.some((o) => o.id === q.answerKey)) {
          return t('education.validationAnswerRequired', { num });
        }
      } else {
        if (!Array.isArray(q.answerKey) || q.answerKey.length === 0) {
          return t('education.validationAnswerRequired', { num });
        }
      }
    } else if (q.type === 'true_false') {
      if (typeof q.answerKey !== 'boolean') {
        return t('education.validationAnswerRequired', { num });
      }
    } else if (q.type === 'short_text') {
      if (typeof q.answerKey !== 'string' || !q.answerKey.trim()) {
        return t('education.validationAnswerRequired', { num });
      }
    } else if (q.type === 'numeric') {
      if (q.answerKey === '' || q.answerKey === null || Number.isNaN(Number(q.answerKey))) {
        return t('education.validationAnswerRequired', { num });
      }
    } else if (q.type === 'matching') {
      const pairs = q.pairs ?? [];
      const leftSet = new Set(pairs.map((p) => p.left.trim()).filter(Boolean));
      if (pairs.length < 2 || pairs.some((p) => !p.left.trim() || !p.right.trim()) || leftSet.size !== pairs.length) {
        return t('education.validationPairsRequired', { num });
      }
      syncMatchingAnswerKey(q);
    } else if (q.type === 'ordering') {
      const items = q.items ?? [];
      if (items.length < 2 || items.some((it) => !it.trim())) {
        return t('education.validationOrderingRequired', { num });
      }
      syncOrderingAnswerKey(q);
    }
  }
  return null;
}

function serializeQuestions(): Array<Record<string, unknown>> {
  return questions.value.map((q) => {
    if (q.type === 'matching') syncMatchingAnswerKey(q);
    if (q.type === 'ordering') syncOrderingAnswerKey(q);
    return {
      id: q.id,
      type: q.type,
      questionType: q.type,
      prompt: q.prompt.trim(),
      points: Math.max(1, Math.min(100, Number(q.points) || 1)),
      options: q.options,
      pairs: q.pairs,
      items: q.items,
      tolerance: q.tolerance,
      content: {
        prompt: q.prompt.trim(),
        ...(q.options ? { options: q.options } : {}),
        ...(q.pairs ? { pairs: q.pairs } : {}),
        ...(q.items ? { items: q.items } : {}),
        ...(q.tolerance !== undefined ? { tolerance: q.tolerance } : {}),
      },
      answerKey: q.answerKey,
      explanation: q.explanation ?? '',
    };
  });
}

function buildPayload(publish: boolean): Record<string, unknown> {
  const timeLimitSeconds =
    timeLimitMinutes.value !== null && timeLimitMinutes.value > 0
      ? Math.max(30, Math.min(14400, Math.round(timeLimitMinutes.value * 60)))
      : null;

  const serializedQuestions = serializeQuestions();

  if (props.mode === 'activity') {
    return {
      pluginCode: 'platform-assessment',
      title: title.value.trim(),
      description: description.value.trim(),
      instructions: instructions.value.trim(),
      visibility: openPractice.value ? 'public_catalog' : 'organization',
      timeLimitSeconds,
      passingScorePercent: Math.max(0, Math.min(100, Number(passingScorePercent.value) || 0)),
      maxAttempts: Math.max(0, Math.min(20, Number(maxAttempts.value) || 0)),
      gradingPolicy: gradingPolicy.value,
      config: {
        shuffleQuestions: shuffleQuestions.value,
        shuffleOptions: shuffleOptions.value,
        revealAnswersPolicy: revealAnswersPolicy.value,
        openPractice: openPractice.value,
        examOnly: !openPractice.value,
      },
      content: { questions: serializedQuestions },
      publish,
    };
  }

  return {
    title: title.value.trim(),
    description: description.value.trim(),
    instructions: instructions.value.trim(),
    openPractice: openPractice.value,
    passingScorePercent: Math.max(0, Math.min(100, Number(passingScorePercent.value) || 0)),
    timeLimitSeconds,
    maxAttempts: Math.max(1, Math.min(20, Number(maxAttempts.value) || 1)),
    shuffleQuestions: shuffleQuestions.value,
    shuffleOptions: shuffleOptions.value,
    revealAnswersPolicy: revealAnswersPolicy.value,
    gradingPolicy: gradingPolicy.value,
    questions: serializedQuestions,
  };
}

async function loadAssessment(): Promise<void> {
  if (!currentId.value) {
    markClean();
    return;
  }
  loading.value = true;
  errorMessage.value = null;
  try {
    if (props.mode === 'activity' && props.organizationId) {
      const act = await activityApi.getActivity(props.organizationId, currentId.value);
      const cfg = (act.config ?? {}) as Record<string, unknown>;
      title.value = act.title;
      description.value = act.description ?? '';
      instructions.value = act.instructions ?? '';
      status.value = act.status;
      versionNumber.value = act.versionNumber ?? 1;
      openPractice.value = Boolean(cfg.openPractice ?? act.visibility === 'public_catalog');
      passingScorePercent.value = act.passingScorePercent ?? 60;
      timeLimitMinutes.value = act.timeLimitSeconds ? Math.round(act.timeLimitSeconds / 60) : null;
      maxAttempts.value = act.maxAttempts ?? 1;
      shuffleQuestions.value = Boolean(cfg.shuffleQuestions);
      shuffleOptions.value = Boolean(cfg.shuffleOptions);
      revealAnswersPolicy.value = (act.revealAnswersPolicy ?? cfg.revealAnswersPolicy ?? 'after_grading') as typeof revealAnswersPolicy.value;
      gradingPolicy.value = (act.gradingPolicy ?? 'highest') as typeof gradingPolicy.value;
      const rawQs = Array.isArray(act.content?.questions) ? act.content.questions : [];
      questions.value = rawQs.map((q) => normalizeLoadedQuestion(q as Record<string, unknown>));
    } else {
      const data = await assessmentApi.getAssessment(currentId.value);
      title.value = data.title;
      description.value = data.description ?? '';
      status.value = data.status;
      versionNumber.value = data.versionNumber ?? 1;
      openPractice.value = Boolean((data as unknown as Record<string, unknown>).openPractice);
      instructions.value = data.instructions || '';
      passingScorePercent.value = data.passingScorePercent ?? 60;
      timeLimitMinutes.value = data.timeLimitSeconds ? Math.round(data.timeLimitSeconds / 60) : null;
      maxAttempts.value = data.maxAttempts ?? 1;
      shuffleQuestions.value = Boolean(data.shuffleQuestions);
      shuffleOptions.value = Boolean(data.shuffleOptions);
      revealAnswersPolicy.value = data.revealAnswersPolicy || 'after_grading';
      gradingPolicy.value = data.gradingPolicy || 'highest';
      questions.value = Array.isArray(data.questions)
        ? data.questions.map((q) => normalizeLoadedQuestion(q as unknown as Record<string, unknown>))
        : [];
    }
    editingNewVersion.value = false;
    markClean();
  } catch (err) {
    errorMessage.value = mapServerError(err);
  } finally {
    loading.value = false;
  }
}

async function saveDraft(): Promise<void> {
  if (saving.value || publishing.value || isReadOnly.value || status.value === 'PUBLISHED') return;
  const validationError = validateBeforeSave(false);
  if (validationError) {
    errorMessage.value = validationError;
    return;
  }
  errorMessage.value = null;
  saving.value = true;
  try {
    const payload = buildPayload(false);
    if (props.mode === 'activity' && props.organizationId) {
      const saved = currentId.value
        ? await activityApi.updateActivity(props.organizationId, currentId.value, payload)
        : await activityApi.createActivity(props.organizationId, payload);
      currentId.value = saved.id;
      status.value = saved.status;
      versionNumber.value = saved.versionNumber ?? versionNumber.value;
      markClean();
      showToast(t('education.draftNotice'));
      emit('saved', saved);
    } else {
      const saved = currentId.value
        ? await assessmentApi.updateAssessment(currentId.value, payload)
        : await assessmentApi.createAssessment(payload);
      currentId.value = saved.id;
      status.value = saved.status;
      versionNumber.value = saved.versionNumber ?? versionNumber.value;
      markClean();
      showToast(t('education.draftNotice'));
      emit('saved', saved);
    }
  } catch (err) {
    errorMessage.value = mapServerError(err);
  } finally {
    saving.value = false;
  }
}

async function publishTest(): Promise<void> {
  if (saving.value || publishing.value || isPublishedLocked.value) return;
  const validationError = validateBeforeSave(true);
  if (validationError) {
    errorMessage.value = validationError;
    return;
  }
  errorMessage.value = null;
  publishing.value = true;
  try {
    const payload = buildPayload(true);
    if (props.mode === 'activity' && props.organizationId) {
      const published = currentId.value
        ? await activityApi.updateActivity(props.organizationId, currentId.value, payload)
        : await activityApi.createActivity(props.organizationId, payload);
      currentId.value = published.id;
      status.value = 'PUBLISHED';
      versionNumber.value = published.versionNumber ?? versionNumber.value;
      editingNewVersion.value = false;
      markClean();
      showToast(t('education.publishedNotice'));
      emit('published', published);
    } else {
      let id = currentId.value;
      if (!id) {
        const created = await assessmentApi.createAssessment({ ...payload, status: 'DRAFT' });
        id = created.id;
        currentId.value = id;
      }
      const published = await assessmentApi.publishAssessment(id, payload);
      status.value = 'PUBLISHED';
      versionNumber.value = published.versionNumber ?? versionNumber.value;
      editingNewVersion.value = false;
      markClean();
      showToast(t('education.publishedNotice'));
      emit('published', published);
    }
  } catch (err) {
    errorMessage.value = mapServerError(err);
  } finally {
    publishing.value = false;
  }
}

async function duplicateAsDraft(): Promise<void> {
  if (saving.value || publishing.value) return;
  errorMessage.value = null;
  saving.value = true;
  try {
    if (props.mode === 'activity' && props.organizationId && currentId.value) {
      const copy = await activityApi.duplicateActivity(props.organizationId, currentId.value);
      currentId.value = copy.id;
      status.value = 'DRAFT';
      editingNewVersion.value = false;
      await loadAssessment();
      emit('duplicated', copy.id);
    } else {
      const payload = buildPayload(false);
      payload.title = `${title.value.trim()} (Copy)`;
      const copy = await assessmentApi.createAssessment(payload);
      currentId.value = copy.id;
      status.value = 'DRAFT';
      editingNewVersion.value = false;
      await loadAssessment();
      emit('duplicated', copy.id);
    }
    showToast(t('education.draftNotice'));
  } catch (err) {
    errorMessage.value = mapServerError(err);
  } finally {
    saving.value = false;
  }
}

async function toggleVersionHistory(): Promise<void> {
  if (showVersions.value) {
    showVersions.value = false;
    return;
  }
  if (!currentId.value) return;
  showVersions.value = true;
  try {
    if (props.mode === 'activity' && props.organizationId) {
      versions.value = await activityApi.listVersions(props.organizationId, currentId.value);
    } else {
      versions.value = await assessmentApi.listVersions(currentId.value);
    }
  } catch {
    versions.value = [];
  }
}

const previewExercise = computed(() => ({
  timeLimitSeconds: timeLimitMinutes.value ? timeLimitMinutes.value * 60 : null,
  questions: questions.value.map((q) => ({
    id: q.id,
    questionType: q.type,
    points: q.points,
    content: {
      prompt: q.prompt || t('education.quizPromptPlaceholder'),
      options: q.options?.map((o) => ({ value: o.id, label: o.label || o.id })),
      pairs: q.pairs,
      items: q.items,
    },
  })),
}));

function handleCancel(): void {
  if (isDirty.value && !window.confirm(t('education.unsavedChangesConfirm'))) {
    return;
  }
  emit('cancel');
}

function handleBeforeUnload(e: BeforeUnloadEvent): void {
  if (isDirty.value) {
    e.preventDefault();
    e.returnValue = '';
  }
}

watch(
  () => props.assessmentId,
  (newId) => {
    if (newId !== currentId.value) {
      currentId.value = newId;
      void loadAssessment();
    }
  },
);

onBeforeRouteLeave(() => {
  if (isDirty.value) {
    return window.confirm(t('education.unsavedChangesConfirm'));
  }
  return true;
});

onMounted(() => {
  window.addEventListener('beforeunload', handleBeforeUnload);
  void loadAssessment();
});

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', handleBeforeUnload);
});
</script>

<template>
  <div class="edu-assessment-editor">
    <!-- Top Sticky Bar -->
    <header class="edu-editor-bar">
      <div class="edu-editor-bar__left">
        <button
          v-if="showBackButton"
          type="button"
          class="edu-btn edu-btn--ghost edu-btn--sm"
          @click="handleCancel"
        >
          ← {{ t('education.backToList') }}
        </button>
        <div class="edu-editor-bar__title-wrap">
          <h1 class="edu-editor-bar__title">
            {{ currentId ? t('education.editAssessment') : t('education.createAssessment') }}
          </h1>
          <div class="edu-editor-bar__badges">
            <span
              class="edu-pill"
              :class="status === 'PUBLISHED' ? 'edu-pill--success' : 'edu-pill--neutral'"
            >
              {{ t(`education.status${status}`) }} · v{{ versionNumber }}
            </span>
            <span
              v-if="isDirty"
              class="edu-pill edu-pill--warn"
            >
              {{ t('education.unsavedChangesBadge') }}
            </span>
          </div>
        </div>
      </div>

      <div class="edu-editor-bar__actions">
        <button
          type="button"
          class="edu-btn edu-btn--ghost edu-btn--sm"
          @click="previewMode = !previewMode"
        >
          {{ previewMode ? t('education.exitPreview') : t('education.assessmentPreview') }}
        </button>
        <button
          v-if="currentId"
          type="button"
          class="edu-btn edu-btn--ghost edu-btn--sm"
          @click="toggleVersionHistory"
        >
          {{ t('education.versionHistory') }}
        </button>
        <button
          v-if="!isReadOnly && status !== 'PUBLISHED'"
          type="button"
          class="edu-btn edu-btn--secondary"
          :disabled="saving || publishing || loading"
          @click="saveDraft"
        >
          {{ saving ? t('education.savingLabel') : t('education.saveDraft') }}
        </button>
        <button
          v-if="!isPublishedLocked"
          type="button"
          class="edu-btn edu-btn--primary"
          :disabled="saving || publishing || loading"
          @click="publishTest"
        >
          {{
            publishing
              ? t('education.publishingLabel')
              : editingNewVersion
                ? t('education.publishNewVersion')
                : t('education.publishAssessment')
          }}
        </button>
      </div>
    </header>

    <!-- Notices & Errors -->
    <div
      v-if="toastMessage"
      class="edu-notice edu-notice--success"
      role="status"
    >
      {{ toastMessage }}
    </div>
    <div
      v-if="errorMessage"
      class="edu-notice edu-notice--error"
      role="alert"
    >
      {{ errorMessage }}
    </div>

    <!-- Published Immutability Banner -->
    <div
      v-if="status === 'PUBLISHED'"
      class="edu-immutable-banner"
    >
      <div class="edu-immutable-banner__text">
        <strong>{{ editingNewVersion ? t('education.editNewVersion') : t('education.publishedImmutableTitle') }}</strong>
        <p>{{ editingNewVersion ? t('education.newVersionDraftNotice') : t('education.publishedImmutableDesc') }}</p>
      </div>
      <div class="edu-immutable-banner__actions">
        <button
          type="button"
          class="edu-btn edu-btn--secondary edu-btn--sm"
          :disabled="saving || publishing"
          @click="duplicateAsDraft"
        >
          {{ t('education.duplicateAsDraft') }}
        </button>
        <button
          v-if="!editingNewVersion"
          type="button"
          class="edu-btn edu-btn--primary edu-btn--sm"
          @click="editingNewVersion = true"
        >
          {{ t('education.editNewVersion') }}
        </button>
      </div>
    </div>

    <!-- Version History Panel -->
    <section
      v-if="showVersions"
      class="edu-card edu-versions-panel"
    >
      <header class="edu-versions-panel__head">
        <h2>{{ t('education.versionHistory') }}</h2>
        <button
          type="button"
          class="edu-btn edu-btn--ghost edu-btn--sm"
          @click="showVersions = false"
        >
          ✕
        </button>
      </header>
      <ul class="edu-versions-list">
        <li
          v-for="ver in versions"
          :key="ver.id"
          class="edu-versions-list__item"
        >
          <span><strong>v{{ ver.versionNumber }}</strong> · {{ ver.questionsCount ?? 0 }} {{ t('education.questionsCount') }}</span>
          <span>{{ ver.attemptsCount ?? 0 }} {{ t('education.completedAttempts') }} · {{ ver.createdAt.slice(0, 10) }}</span>
        </li>
      </ul>
    </section>

    <div
      v-if="loading"
      class="edu-loading-state"
    >
      {{ t('education.loadingLabel') }}
    </div>

    <!-- Preview Mode -->
    <section
      v-else-if="previewMode"
      class="edu-preview-shell"
    >
      <div class="edu-notice edu-notice--info">
        {{ t('education.previewBanner') }}
      </div>
      <div class="edu-card">
        <h2>{{ title || t('education.createAssessment') }}</h2>
        <p v-if="description">{{ description }}</p>
        <p
          v-if="instructions"
          class="edu-preview-instructions"
        >
          {{ instructions }}
        </p>
        <AssessmentRunnerPlugin
          :exercise="previewExercise"
          :readonly="false"
        />
      </div>
    </section>

    <!-- Main Editor Grid -->
    <div
      v-else
      class="edu-editor-layout"
    >
      <!-- Left Column: Metadata & Questions -->
      <div class="edu-editor-main">
        <section class="edu-card edu-meta-card">
          <div class="edu-field">
            <label
              for="asm-title"
              class="edu-field__label"
            >
              {{ t('education.quizTitlePlaceholder') }} *
            </label>
            <input
              id="asm-title"
              v-model="title"
              type="text"
              class="edu-input"
              :disabled="isReadOnly"
              :placeholder="t('education.quizTitlePlaceholder')"
            />
          </div>

          <div class="edu-field">
            <label
              for="asm-desc"
              class="edu-field__label"
            >
              {{ t('education.description') }}
            </label>
            <textarea
              id="asm-desc"
              v-model="description"
              class="edu-input"
              rows="2"
              :disabled="isReadOnly"
            />
          </div>

          <div class="edu-field">
            <label
              for="asm-inst"
              class="edu-field__label"
            >
              {{ t('education.homeworkInstructions') }}
            </label>
            <textarea
              id="asm-inst"
              v-model="instructions"
              class="edu-input"
              rows="2"
              :disabled="isReadOnly"
            />
          </div>
        </section>

        <!-- Questions Header -->
        <div class="edu-questions-toolbar">
          <div class="edu-questions-toolbar__summary">
            <strong>{{ questions.length }}</strong> {{ t('education.questionsCount') }} ·
            <strong>{{ totalPoints }}</strong> {{ t('education.score') }}
          </div>
          <button
            v-if="!isReadOnly"
            type="button"
            class="edu-btn edu-btn--secondary edu-btn--sm"
            @click="showBankDrawer = true"
          >
            + {{ t('education.importFromBank') }}
          </button>
        </div>

        <!-- Empty State -->
        <div
          v-if="questions.length === 0"
          class="edu-card edu-empty-questions"
        >
          <p>{{ t('education.noQuestionsYet') }}</p>
        </div>

        <!-- Question Cards -->
        <article
          v-for="(q, idx) in questions"
          :key="q.id"
          class="edu-card edu-question-card"
        >
          <header class="edu-question-card__head">
            <div class="edu-question-card__meta">
              <span class="edu-question-num">#{{ idx + 1 }}</span>
              <span class="edu-badge edu-badge--neutral">
                {{ t(`education.questionType_${q.type}`) }}
              </span>
            </div>

            <div class="edu-question-card__controls">
              <label class="edu-points-input">
                <span>{{ t('education.questionPointsLabel') }}</span>
                <input
                  v-model.number="q.points"
                  type="number"
                  min="1"
                  max="100"
                  class="edu-input edu-input--xs"
                  :disabled="isReadOnly"
                />
              </label>
              <button
                v-if="!isReadOnly"
                type="button"
                class="edu-btn edu-btn--ghost edu-btn--xs"
                :title="t('education.moveUp')"
                :disabled="idx === 0"
                @click="moveQuestion(idx, -1)"
              >
                ↑
              </button>
              <button
                v-if="!isReadOnly"
                type="button"
                class="edu-btn edu-btn--ghost edu-btn--xs"
                :title="t('education.moveDown')"
                :disabled="idx === questions.length - 1"
                @click="moveQuestion(idx, 1)"
              >
                ↓
              </button>
              <button
                v-if="!isReadOnly"
                type="button"
                class="edu-btn edu-btn--ghost edu-btn--xs"
                :title="t('education.duplicateQuestion')"
                @click="duplicateQuestion(idx)"
              >
                ⧉
              </button>
              <button
                type="button"
                class="edu-btn edu-btn--ghost edu-btn--xs"
                :title="t('education.saveToBank')"
                @click="saveToBank(q)"
              >
                ★
              </button>
              <button
                v-if="!isReadOnly"
                type="button"
                class="edu-btn edu-btn--ghost edu-btn--xs edu-btn--danger"
                :title="t('education.deleteQuestion')"
                @click="removeQuestion(idx)"
              >
                ✕
              </button>
            </div>
          </header>

          <!-- Prompt -->
          <div class="edu-field">
            <label class="edu-field__label">{{ t('education.questionPromptLabel') }}</label>
            <textarea
              v-model="q.prompt"
              class="edu-input"
              rows="2"
              :disabled="isReadOnly"
              :placeholder="t('education.quizPromptPlaceholder')"
            />
          </div>

          <!-- Visual Editor: Single Choice & Multi Choice -->
          <div
            v-if="q.type === 'single_choice' || q.type === 'multi_choice'"
            class="edu-q-options"
          >
            <div
              v-for="(opt, oi) in q.options || []"
              :key="opt.id"
              class="edu-q-option-row"
            >
              <input
                v-if="q.type === 'single_choice'"
                v-model="q.answerKey"
                type="radio"
                :name="`ans-${q.id}`"
                :value="opt.id"
                :disabled="isReadOnly"
                :aria-label="t('education.correctOption')"
              />
              <input
                v-else
                type="checkbox"
                :checked="Array.isArray(q.answerKey) && (q.answerKey as string[]).includes(opt.id)"
                :disabled="isReadOnly"
                :aria-label="t('education.correctOption')"
                @change="toggleMultiOption(q, opt.id)"
              />
              <input
                v-model="opt.label"
                type="text"
                class="edu-input"
                :disabled="isReadOnly"
                :placeholder="`${t('education.quizOptionPlaceholder')} ${oi + 1}`"
              />
              <button
                v-if="!isReadOnly && (q.options?.length ?? 0) > 2"
                type="button"
                class="edu-btn edu-btn--ghost edu-btn--xs"
                @click="removeOption(q, oi)"
              >
                ✕
              </button>
            </div>
            <button
              v-if="!isReadOnly"
              type="button"
              class="edu-btn edu-btn--secondary edu-btn--sm"
              @click="addOption(q)"
            >
              + {{ t('education.addOption') }}
            </button>
          </div>

          <!-- Visual Editor: True / False -->
          <div
            v-else-if="q.type === 'true_false'"
            class="edu-q-tf"
          >
            <button
              type="button"
              class="edu-btn edu-btn--sm"
              :class="q.answerKey === true ? 'edu-btn--primary' : 'edu-btn--secondary'"
              :disabled="isReadOnly"
              @click="q.answerKey = true"
            >
              {{ t('education.trueLabel') }}
            </button>
            <button
              type="button"
              class="edu-btn edu-btn--sm"
              :class="q.answerKey === false ? 'edu-btn--primary' : 'edu-btn--secondary'"
              :disabled="isReadOnly"
              @click="q.answerKey = false"
            >
              {{ t('education.falseLabel') }}
            </button>
          </div>

          <!-- Visual Editor: Short Text -->
          <div
            v-else-if="q.type === 'short_text'"
            class="edu-field"
          >
            <label class="edu-field__label">{{ t('education.correctOption') }}</label>
            <input
              v-model="q.answerKey"
              type="text"
              class="edu-input"
              :disabled="isReadOnly"
            />
          </div>

          <!-- Visual Editor: Long Text -->
          <div
            v-else-if="q.type === 'long_text'"
            class="edu-q-hint"
          >
            {{ t('education.longTextManualHint') }}
          </div>

          <!-- Visual Editor: Numeric with tolerance -->
          <div
            v-else-if="q.type === 'numeric'"
            class="edu-q-numeric"
          >
            <div class="edu-field">
              <label class="edu-field__label">{{ t('education.correctOption') }}</label>
              <input
                v-model.number="q.answerKey"
                type="number"
                step="any"
                class="edu-input"
                :disabled="isReadOnly"
              />
            </div>
            <div class="edu-field">
              <label class="edu-field__label">{{ t('education.toleranceLabel') }} (±)</label>
              <input
                v-model.number="q.tolerance"
                type="number"
                min="0"
                step="any"
                class="edu-input"
                :disabled="isReadOnly"
              />
            </div>
          </div>

          <!-- Visual Editor: Matching -->
          <div
            v-else-if="q.type === 'matching'"
            class="edu-q-matching"
          >
            <div
              v-for="(pair, pi) in q.pairs || []"
              :key="pi"
              class="edu-q-pair-row"
            >
              <input
                v-model="pair.left"
                type="text"
                class="edu-input"
                :disabled="isReadOnly"
                :placeholder="t('education.pairLeftPlaceholder')"
                @input="syncMatchingAnswerKey(q)"
              />
              <span class="edu-q-pair-arrow">↔</span>
              <input
                v-model="pair.right"
                type="text"
                class="edu-input"
                :disabled="isReadOnly"
                :placeholder="t('education.pairRightPlaceholder')"
                @input="syncMatchingAnswerKey(q)"
              />
              <button
                v-if="!isReadOnly && (q.pairs?.length ?? 0) > 2"
                type="button"
                class="edu-btn edu-btn--ghost edu-btn--xs"
                @click="removePair(q, pi)"
              >
                ✕
              </button>
            </div>
            <button
              v-if="!isReadOnly"
              type="button"
              class="edu-btn edu-btn--secondary edu-btn--sm"
              @click="addPair(q)"
            >
              + {{ t('education.addPair') }}
            </button>
          </div>

          <!-- Visual Editor: Ordering -->
          <div
            v-else-if="q.type === 'ordering'"
            class="edu-q-ordering"
          >
            <p class="edu-q-hint">{{ t('education.orderingHint') }}</p>
            <div
              v-for="(item, oi) in q.items || []"
              :key="oi"
              class="edu-q-order-row"
            >
              <span class="edu-q-order-idx">{{ oi + 1 }}.</span>
              <input
                :value="item"
                type="text"
                class="edu-input"
                :disabled="isReadOnly"
                :placeholder="t('education.orderingItemPlaceholder')"
                @input="
                  q.items![oi] = ($event.target as HTMLInputElement).value;
                  syncOrderingAnswerKey(q);
                "
              />
              <button
                v-if="!isReadOnly"
                type="button"
                class="edu-btn edu-btn--ghost edu-btn--xs"
                :disabled="oi === 0"
                @click="moveOrderingItem(q, oi, -1)"
              >
                ↑
              </button>
              <button
                v-if="!isReadOnly"
                type="button"
                class="edu-btn edu-btn--ghost edu-btn--xs"
                :disabled="oi === (q.items?.length ?? 1) - 1"
                @click="moveOrderingItem(q, oi, 1)"
              >
                ↓
              </button>
              <button
                v-if="!isReadOnly && (q.items?.length ?? 0) > 2"
                type="button"
                class="edu-btn edu-btn--ghost edu-btn--xs"
                @click="removeOrderingItem(q, oi)"
              >
                ✕
              </button>
            </div>
            <button
              v-if="!isReadOnly"
              type="button"
              class="edu-btn edu-btn--secondary edu-btn--sm"
              @click="addOrderingItem(q)"
            >
              + {{ t('education.addOrderingItem') }}
            </button>
          </div>

          <!-- Explanation -->
          <div class="edu-field edu-field--compact">
            <label class="edu-field__label">{{ t('education.explanationLabel') }}</label>
            <input
              v-model="q.explanation"
              type="text"
              class="edu-input"
              :disabled="isReadOnly"
            />
          </div>
        </article>

        <!-- Add Question Palette -->
        <section
          v-if="!isReadOnly"
          class="edu-card edu-add-palette"
        >
          <h2 class="edu-add-palette__title">{{ t('education.addQuestion') }}</h2>
          <div class="edu-add-palette__grid">
            <button
              v-for="qt in QUESTION_TYPES"
              :key="qt"
              type="button"
              class="edu-btn edu-btn--secondary edu-btn--sm"
              @click="addQuestion(qt)"
            >
              + {{ t(`education.questionType_${qt}`) }}
            </button>
          </div>
        </section>
      </div>

      <!-- Right Column: Server-Supported Assessment Settings -->
      <aside class="edu-editor-sidebar">
        <section class="edu-card edu-settings-card">
          <h2 class="edu-settings-card__title">{{ t('education.exerciseSettings') }}</h2>

          <div class="edu-field">
            <label
              for="asm-pass"
              class="edu-field__label"
            >
              {{ t('education.assessmentPassingScore') }}
            </label>
            <input
              id="asm-pass"
              v-model.number="passingScorePercent"
              type="number"
              min="0"
              max="100"
              class="edu-input"
              :disabled="isReadOnly"
            />
          </div>

          <div class="edu-field">
            <label
              for="asm-time"
              class="edu-field__label"
            >
              {{ t('education.assessmentTimeLimit') }} ({{ t('education.minutes') }})
            </label>
            <input
              id="asm-time"
              v-model.number="timeLimitMinutes"
              type="number"
              min="1"
              max="240"
              class="edu-input"
              :disabled="isReadOnly"
              :placeholder="t('education.noTimeLimit')"
            />
          </div>

          <div class="edu-field">
            <label
              for="asm-attempts"
              class="edu-field__label"
            >
              {{ t('education.assessmentMaxAttempts') }}
            </label>
            <input
              id="asm-attempts"
              v-model.number="maxAttempts"
              type="number"
              :min="props.mode === 'activity' ? 0 : 1"
              max="20"
              class="edu-input"
              :disabled="isReadOnly"
            />
          </div>

          <div class="edu-field">
            <label
              for="asm-grading"
              class="edu-field__label"
            >
              {{ t('education.assessmentGradingPolicy') }}
            </label>
            <select
              id="asm-grading"
              v-model="gradingPolicy"
              class="edu-input"
              :disabled="isReadOnly"
            >
              <option value="highest">{{ t('education.gradingHighest') }}</option>
              <option value="latest">{{ t('education.gradingLatest') }}</option>
              <option value="average">{{ t('education.gradingAverage') }}</option>
            </select>
          </div>

          <div class="edu-field">
            <label
              for="asm-reveal"
              class="edu-field__label"
            >
              {{ t('education.assessmentRevealPolicy') }}
            </label>
            <select
              id="asm-reveal"
              v-model="revealAnswersPolicy"
              class="edu-input"
              :disabled="isReadOnly"
            >
              <option value="after_grading">{{ t('education.revealAfterGrading') }}</option>
              <option value="immediate">{{ t('education.revealImmediate') }}</option>
              <option value="deadline">{{ t('education.revealDeadline') }}</option>
              <option value="never">{{ t('education.revealNever') }}</option>
            </select>
          </div>

          <label class="edu-checkbox-row">
            <input
              v-model="shuffleQuestions"
              type="checkbox"
              :disabled="isReadOnly"
            />
            <span>{{ t('education.assessmentShuffleQuestions') }}</span>
          </label>

          <label class="edu-checkbox-row">
            <input
              v-model="shuffleOptions"
              type="checkbox"
              :disabled="isReadOnly"
            />
            <span>{{ t('education.assessmentShuffleOptions') }}</span>
          </label>

          <label class="edu-checkbox-row">
            <input
              v-model="openPractice"
              type="checkbox"
              :disabled="isReadOnly"
            />
            <span>{{ t('education.assessmentOpenPractice') }}</span>
          </label>
        </section>
      </aside>
    </div>

    <QuestionBankDrawer
      :open="showBankDrawer"
      :mode="props.mode"
      :organization-id="props.organizationId"
      @close="showBankDrawer = false"
      @select="onImportFromBank"
    />
  </div>
</template>

<style scoped>
.edu-assessment-editor {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  color: var(--text-primary, var(--text-1, #0f172a));
}

.edu-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  padding: 0.5rem 0.9rem;
  border-radius: var(--radius-md, 8px);
  font-size: 0.875rem;
  font-weight: 500;
  cursor: pointer;
  border: 1px solid var(--border-subtle, var(--border, #cbd5e1));
  background: var(--bg-surface, var(--surface, #ffffff));
  color: var(--text-primary, var(--text-1, #0f172a));
  transition: background-color 0.15s ease, border-color 0.15s ease, opacity 0.15s ease;
}

.edu-btn:hover:not(:disabled) {
  border-color: var(--accent-primary, var(--accent, #2563eb));
}

.edu-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.edu-btn--primary {
  background: var(--accent-primary, var(--accent, #2563eb));
  border-color: var(--accent-primary, var(--accent, #2563eb));
  color: #ffffff;
}

.edu-btn--secondary {
  background: var(--bg-subtle, var(--surface-2, #f1f5f9));
  color: var(--text-primary, var(--text-1, #0f172a));
}

.edu-btn--ghost {
  background: transparent;
  border-color: transparent;
}

.edu-btn--sm {
  padding: 0.35rem 0.7rem;
  font-size: 0.82rem;
}

.edu-btn--xs {
  padding: 0.2rem 0.45rem;
  font-size: 0.78rem;
}

.edu-btn--danger {
  color: #dc2626;
}

.edu-input {
  width: 100%;
  box-sizing: border-box;
  padding: 0.5rem 0.75rem;
  border-radius: var(--radius-md, 8px);
  border: 1px solid var(--border-subtle, var(--border, #cbd5e1));
  background: var(--bg-canvas, var(--surface, #ffffff));
  color: var(--text-primary, var(--text-1, #0f172a));
  font: inherit;
}

.edu-pill,
.edu-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 500;
  background: var(--bg-subtle, var(--surface-2, #f1f5f9));
  color: var(--text-primary, var(--text-1, #0f172a));
}

.edu-pill--success {
  background: color-mix(in srgb, #16a34a 18%, var(--bg-surface, var(--surface, #ffffff)));
  color: #16a34a;
}

.edu-editor-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.875rem 1rem;
  background: var(--bg-surface, var(--surface, #ffffff));
  border: 1px solid var(--border-subtle, var(--border, #cbd5e1));
  border-radius: var(--radius-lg, 12px);
}

.edu-editor-bar__left {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}

.edu-editor-bar__title {
  font-size: var(--type-h2-size, 1.25rem);
  font-weight: 600;
  margin: 0;
}

.edu-editor-bar__badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 0.25rem;
}

.edu-editor-bar__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.edu-editor-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: 1rem;
  align-items: start;
}

.edu-editor-main {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.edu-card {
  background: var(--bg-surface, var(--surface, #ffffff));
  border: 1px solid var(--border-subtle, var(--border, #cbd5e1));
  border-radius: var(--radius-lg, 12px);
  padding: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.edu-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.edu-field__label {
  font-size: 0.85rem;
  font-weight: 500;
  color: var(--text-2);
}

.edu-questions-toolbar {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  padding: 0.25rem 0;
}

.edu-question-card__head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  border-bottom: 1px solid var(--border);
  padding-bottom: 0.5rem;
}

.edu-question-card__meta,
.edu-question-card__controls {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
}

.edu-question-num {
  font-weight: 700;
}

.edu-points-input {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.82rem;
  color: var(--text-2);
}

.edu-input--xs {
  width: 68px;
  padding: 0.25rem 0.4rem;
}

.edu-q-options,
.edu-q-matching,
.edu-q-ordering {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.edu-q-option-row,
.edu-q-pair-row,
.edu-q-order-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.edu-q-tf {
  display: flex;
  gap: 0.5rem;
}

.edu-q-numeric {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.edu-q-hint {
  font-size: 0.85rem;
  color: var(--text-2);
  margin: 0;
}

.edu-add-palette__title,
.edu-settings-card__title {
  font-size: 1rem;
  font-weight: 600;
  margin: 0;
}

.edu-add-palette__grid {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.edu-checkbox-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
  cursor: pointer;
}

.edu-notice {
  padding: 0.75rem 1rem;
  border-radius: var(--radius-md, 8px);
  border: 1px solid var(--border);
}

.edu-notice--success {
  background: color-mix(in srgb, #16a34a 12%, var(--surface));
  border-color: #16a34a;
}

.edu-notice--error {
  background: color-mix(in srgb, #dc2626 12%, var(--surface));
  border-color: #dc2626;
}

.edu-notice--info {
  background: color-mix(in srgb, var(--accent, #2563eb) 10%, var(--surface));
  border-color: var(--accent, #2563eb);
}

.edu-immutable-banner {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  padding: 0.875rem 1rem;
  border-radius: var(--radius-md, 8px);
  border: 1px solid var(--accent, #2563eb);
  background: color-mix(in srgb, var(--accent, #2563eb) 8%, var(--surface));
}

.edu-immutable-banner__text p {
  margin: 0.25rem 0 0;
  font-size: 0.85rem;
  color: var(--text-2);
}

.edu-immutable-banner__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.edu-pill--warn {
  background: color-mix(in srgb, #d97706 18%, var(--surface));
  color: var(--text-1);
}

.edu-versions-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.edu-versions-list__item {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  font-size: 0.88rem;
  padding: 0.4rem 0;
  border-bottom: 1px solid var(--border);
}

@media (max-width: 900px) {
  .edu-editor-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 600px) {
  .edu-q-numeric {
    grid-template-columns: 1fr;
  }
  .edu-q-pair-row {
    flex-wrap: wrap;
  }
}
</style>
