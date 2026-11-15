import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, reactive, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { ApiError } from '@/shared/api/errors';
import { studentSession } from '../student/session';
import { homeworkApi, type HomeworkAnswer, type SubmissionState } from './api';
import StudentHomeworkView from './StudentHomeworkView.vue';
import HomeworkReviewView from './HomeworkReviewView.vue';

const state = vi.hoisted(() => ({ route: undefined as unknown, workspace: undefined as unknown }));
vi.mock('vue-router', () => ({ useRoute: () => state.route }));
vi.mock('../workspace', () => ({ useEducationWorkspace: () => state.workspace }));
vi.mock('../i18n', () => ({ useEducationI18n: () => ({ t: (key: string) => key, locale: ref('en') }) }));
vi.mock('../resources', () => ({ useEducationError: () => () => 'Request failed' }));
vi.mock('./api', () => ({ homeworkApi: { studentDetail: vi.fn(), draft: vi.fn(), submit: vi.fn(), answer: vi.fn(), review: vi.fn() } }));
const id = '01900000-0000-7000-8000-000000000001', org = '01900000-0000-7000-8000-000000000002';
const now = '2026-11-13T12:00:00Z';
const button = defineComponent({ props: ['disabled', 'loading', 'type'], emits: ['click'], template: '<button :type="type ?? \'button\'" :disabled="disabled || loading" @click="$emit(\'click\')"><slot /></button>' });
const confirm = defineComponent({ name: 'AppConfirmDialog', props: ['modelValue', 'busy'], emits: ['confirm', 'update:modelValue'], template: '<button v-if="modelValue" data-confirm :disabled="busy" @click="$emit(\'confirm\')">Confirm</button>' });
const dropdown = defineComponent({ props: ['modelValue', 'disabled', 'options'], emits: ['update:modelValue'], template: '<select :value="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.value)"><option value=""></option><option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option></select>' });
const stubs = { AppButton: button, AppConfirmDialog: confirm, AppDropdown: dropdown, EducationState: true, HomeworkFiles: true, HomeworkAnswerHistory: true, RouterLink: { template: '<a><slot /></a>' } };
const wrappers: VueWrapper[] = [];
function open(component: typeof StudentHomeworkView | typeof HomeworkReviewView) { const wrapper = mount(component, { global: { stubs } }); wrappers.push(wrapper); return wrapper; }
function findButton(wrapper: VueWrapper, label: string) { const result = wrapper.findAll('button').find(item => item.text() === label); expect(result).toBeDefined(); return result!; }
function answer(status: SubmissionState = 'IN_PROGRESS'): HomeworkAnswer {
  return { now, assignment: { id, organizationId: org, title: 'Explain loops', description: 'Private learner assignment', instructions: 'Show an example.', type: 'text', groupId: id, studentIds: [], lessonId: null, dueAt: '2099-11-14T12:00:00Z', allowLate: false, activityCode: null, activityConfig: { durationSeconds: 30, language: 'en' }, maxAttempts: 3, attemptPolicy: 'latest', status: 'PUBLISHED', version: 2, createdAt: now, files: [] }, recipient: { studentId: id, name: 'Private learner' }, submission: { id, recipientId: id, studentId: id, personId: id, state: status, version: 3, text: 'Saved answer', currentVersion: { id, sequence: 1, assignmentVersion: 2, text: 'Submitted answer', submittedAt: now, late: false, activityAttemptId: null, files: [] }, versions: [], reviews: [], files: [], selectedActivityAttemptId: null, activityResult: null } };
}
beforeEach(() => {
  vi.clearAllMocks(); state.route = reactive({ params: { id }, query: {} });
  state.workspace = reactive({ organizationId: org, header: { timezone: 'UTC' }, refreshHeader: vi.fn().mockResolvedValue(undefined) });
  coreAdminSession.set({ accessToken: 'qa', refreshToken: 'qa', accessExpiresAt: '', sessionExpiresAt: '', sessionId: 'qa' });
  studentSession.organizationId = org;
  vi.mocked(homeworkApi.studentDetail).mockResolvedValue(answer());
  vi.mocked(homeworkApi.answer).mockResolvedValue(answer('SUBMITTED'));
});
afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); coreAdminSession.clear(); studentSession.organizationId = ''; });

describe('Homework learner view', () => {
  it('requires confirmation and sends a single submit while the response is pending', async () => {
    let resolve!: (value: HomeworkAnswer) => void;
    vi.mocked(homeworkApi.submit).mockImplementation(() => new Promise(accept => { resolve = accept; }));
    const wrapper = open(StudentHomeworkView); await flushPromises();
    await findButton(wrapper, 'education.homeworkSubmit').trigger('click');
    expect(homeworkApi.submit).not.toHaveBeenCalled();
    const dialog = wrapper.findComponent({ name: 'AppConfirmDialog' });
    dialog.vm.$emit('confirm'); dialog.vm.$emit('confirm'); await flushPromises();
    expect(homeworkApi.submit).toHaveBeenCalledTimes(1);
    expect(homeworkApi.submit).toHaveBeenCalledWith(org, id, 3, expect.stringMatching(/^[0-9a-f-]{36}$/));
    resolve(answer('SUBMITTED')); await flushPromises();
    expect(wrapper.find('textarea').attributes('disabled')).toBeDefined();
    expect(wrapper.text()).toContain('education.homeworkSUBMITTED');
  });

  it('allows editing a returned answer, saves it, then enables resubmission', async () => {
    vi.mocked(homeworkApi.studentDetail).mockResolvedValue(answer('RETURNED'));
    const saved = answer('RETURNED'); saved.submission.text = 'Improved explanation'; saved.submission.version = 4;
    vi.mocked(homeworkApi.draft).mockResolvedValue(saved);
    const wrapper = open(StudentHomeworkView); await flushPromises();
    await wrapper.find('textarea').setValue('Improved explanation');
    expect(findButton(wrapper, 'education.homeworkSubmit').attributes('disabled')).toBeDefined();
    await findButton(wrapper, 'education.homeworkSaveDraft').trigger('click'); await flushPromises();
    expect(homeworkApi.draft).toHaveBeenCalledWith(org, id, 3, 'Improved explanation');
    expect(findButton(wrapper, 'education.homeworkSubmit').attributes('disabled')).toBeUndefined();
  });

  it('displays API errors without discarding the unsent answer', async () => {
    vi.mocked(homeworkApi.draft).mockRejectedValue(new ApiError(422, 'education.invalid_input', 'Invalid answer'));
    const wrapper = open(StudentHomeworkView); await flushPromises();
    await wrapper.find('textarea').setValue('Keep my unsent work');
    await findButton(wrapper, 'education.homeworkSaveDraft').trigger('click'); await flushPromises();
    expect(wrapper.text()).toContain('Request failed');
    expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('Keep my unsent work');
  });

  it('removes the previous learner answer immediately on logout', async () => {
    const wrapper = open(StudentHomeworkView); await flushPromises();
    expect(wrapper.text()).toContain('Private learner assignment');
    coreAdminSession.clear(); await flushPromises();
    expect(wrapper.text()).not.toContain('Private learner assignment');
    expect(wrapper.find('textarea').exists()).toBe(false);
  });
});

describe('Homework teacher review', () => {
  it('requires a return comment and never sends an acceptance grade with a return', async () => {
    vi.mocked(homeworkApi.review).mockResolvedValue(answer('RETURNED'));
    vi.mocked(homeworkApi.answer).mockResolvedValueOnce(answer('SUBMITTED')).mockResolvedValueOnce(answer('RETURNED'));
    const wrapper = open(HomeworkReviewView); await flushPromises();
    await findButton(wrapper, 'education.homeworkReturn').trigger('click');
    expect(wrapper.text()).toContain('education.homeworkReturnComment');
    expect(homeworkApi.review).not.toHaveBeenCalled();
    await wrapper.find('select').setValue('mastered');
    await wrapper.find('textarea').setValue('Add a stopping condition.');
    await findButton(wrapper, 'education.homeworkReturn').trigger('click');
    wrapper.findComponent({ name: 'AppConfirmDialog' }).vm.$emit('confirm'); await flushPromises();
    expect(homeworkApi.review).toHaveBeenCalledWith(org, id, expect.objectContaining({ decision: 'returned', resultStatus: null, comment: 'Add a stopping condition.' }));
    expect(homeworkApi.answer).toHaveBeenCalledTimes(2);
    expect(wrapper.find('form').exists()).toBe(false);
  });

  it('refreshes the server answer and workspace header after acceptance', async () => {
    vi.mocked(homeworkApi.review).mockResolvedValue(answer('REVIEWED'));
    vi.mocked(homeworkApi.answer).mockResolvedValueOnce(answer('SUBMITTED')).mockResolvedValueOnce(answer('REVIEWED'));
    const wrapper = open(HomeworkReviewView); await flushPromises();
    await wrapper.find('form').trigger('submit');
    wrapper.findComponent({ name: 'AppConfirmDialog' }).vm.$emit('confirm'); await flushPromises();
    expect(homeworkApi.review).toHaveBeenCalledTimes(1);
    expect(homeworkApi.answer).toHaveBeenCalledTimes(2);
    expect((state.workspace as { refreshHeader: unknown }).refreshHeader).toHaveBeenCalledTimes(1);
    expect(wrapper.text()).toContain('education.homeworkREVIEWED');
    expect(wrapper.find('form').exists()).toBe(false);
  });
});
