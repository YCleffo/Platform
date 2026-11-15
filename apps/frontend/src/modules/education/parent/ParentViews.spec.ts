import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { defineComponent, reactive, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { ApiError } from '@/shared/api/errors';
import { parentApi, type FamilyList, type ParentChild, type ParentHomework } from './api';
import { parentChildKey, useParentWorkspace } from './workspace';
import ParentSectionView from './ParentSectionView.vue';
import StudentFamilyPanel from './StudentFamilyPanel.vue';

const state = vi.hoisted(() => ({ route: undefined as unknown, teacher: undefined as unknown }));
vi.mock('vue-router', () => ({ useRoute: () => state.route }));
vi.mock('../workspace', () => ({ useEducationWorkspace: () => state.teacher }));
vi.mock('../i18n', () => ({
  useEducationI18n: () => ({ t: (key: string) => key, locale: ref('en') }),
}));
vi.mock('./api', () => ({
  parentApi: {
    context: vi.fn(),
    home: vi.fn(),
    schedule: vi.fn(),
    diary: vi.fn(),
    homework: vi.fn(),
    progress: vi.fn(),
    notifications: vi.fn(),
    readNotification: vi.fn(),
    guardians: vi.fn(),
    history: vi.fn(),
    candidates: vi.fn(),
    addGuardian: vi.fn(),
    relationship: vi.fn(),
    familyAction: vi.fn(),
    invite: vi.fn(),
  },
}));
const child: ParentChild = {
  id: '01900000-0000-7000-8000-000000000001',
  personId: '01900000-0000-7000-8000-000000000002',
  name: 'Child A',
  organizationId: '01900000-0000-7000-8000-000000000003',
  organizationName: 'School A',
  groups: [],
};
const guardian = '01900000-0000-7000-8000-000000000004';
const now = '2026-11-14T12:00:00Z';
const wrappers: VueWrapper[] = [];
const dropdown = defineComponent({
  props: ['modelValue', 'options', 'label'],
  emits: ['update:modelValue'],
  template:
    '<select :aria-label="label" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option value=""></option><option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option></select>',
});
function open(component: typeof ParentSectionView | typeof StudentFamilyPanel, props = {}) {
  const wrapper = mount(component, {
    props,
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' }, AppDropdown: dropdown } },
  });
  wrappers.push(wrapper);
  return wrapper;
}
function family(canManage = true, canConfirm = false): FamilyList {
  return {
    items: [
      {
        personId: guardian,
        name: 'Guardian A',
        relationship: 'mother',
        status: 'pending',
        confirmedAt: null,
        revokedAt: null,
        account: null,
        invitation: null,
      },
    ],
    canManage,
    canConfirm,
  };
}
beforeEach(() => {
  vi.clearAllMocks();
  setActivePinia(createPinia());
  coreAdminSession.set({
    accessToken: 'access',
    refreshToken: 'refresh',
    accessExpiresAt: '',
    sessionExpiresAt: '',
    sessionId: 'qa',
  });
  state.route = reactive({ meta: { parentSection: 'homework' }, query: {} });
  state.teacher = reactive({
    organizationId: child.organizationId,
    revision: 0,
    header: { timezone: 'UTC' },
    reset: vi.fn(),
  });
  const workspace = useParentWorkspace();
  workspace.context = {
    personId: guardian,
    displayName: 'Guardian A',
    locale: 'en',
    timezone: 'UTC',
    children: [child],
  };
  workspace.selectedKey = parentChildKey(child);
  vi.mocked(parentApi.history).mockResolvedValue({
    items: [],
    limit: 50,
    offset: 0,
    nextOffset: null,
  });
});
afterEach(() => {
  wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
  coreAdminSession.clear();
});

describe('parent pages', () => {
  it('localizes family notification events without exposing their internal subject and body codes', async () => {
    state.route = reactive({ meta: { parentSection: 'notifications' }, query: {} });
    vi.mocked(parentApi.notifications).mockResolvedValue({
      items: [{ id: child.id, threadId: null, kind: 'parent_homework_published',
        subject: 'INTERNAL_SUBJECT', body: 'INTERNAL_BODY', createdAt: now, readAt: null }],
      limit: 50, offset: 0, nextOffset: null,
    });
    const wrapper = open(ParentSectionView);
    await flushPromises();
    expect(wrapper.text()).toContain('education.parentNotice_parent_homework_published');
    expect(wrapper.text()).not.toContain('INTERNAL_SUBJECT');
    expect(wrapper.text()).not.toContain('INTERNAL_BODY');
  });
  it('shows reviewed homework as read only and omits unexpected private draft fields', async () => {
    const assignment: ParentHomework & { draftText: string } = {
      id: child.id,
      title: 'Explain loops',
      description: 'Assignment',
      instructions: 'Use an example',
      type: 'text',
      dueAt: null,
      status: 'PUBLISHED',
      submissionState: 'ACCEPTED',
      submittedAt: now,
      review: {
        decision: 'accepted',
        resultStatus: 'mastered',
        comment: 'Clear explanation',
        createdAt: now,
      },
      draftText: 'Private unsent secret',
    };
    vi.mocked(parentApi.homework).mockResolvedValue({
      items: [assignment],
      limit: 50,
      offset: 0,
      nextOffset: null,
    });
    const wrapper = open(ParentSectionView);
    await flushPromises();
    expect(wrapper.text()).toContain('Explain loops');
    expect(wrapper.text()).toContain('Clear explanation');
    expect(wrapper.text()).not.toContain('Private unsent secret');
    expect(wrapper.find('textarea').exists()).toBe(false);
    expect(wrapper.find('input').exists()).toBe(false);
    expect(wrapper.html()).not.toContain('/student/documents');
  });
  it('removes rendered child data on logout even while the page remains mounted', async () => {
    vi.mocked(parentApi.homework).mockResolvedValue({
      items: [
        {
          id: child.id,
          title: 'Child A homework',
          description: '',
          instructions: '',
          type: 'text',
          dueAt: null,
          status: 'PUBLISHED',
          submissionState: 'NOT_STARTED',
          submittedAt: null,
          review: null,
        },
      ],
      limit: 50,
      offset: 0,
      nextOffset: null,
    });
    const wrapper = open(ParentSectionView);
    await flushPromises();
    expect(wrapper.text()).toContain('Child A homework');
    coreAdminSession.clear();
    await flushPromises();
    expect(wrapper.text()).not.toContain('Child A homework');
  });
  it('shows an empty state for absent assignments and a retryable error for denial', async () => {
    vi.mocked(parentApi.homework).mockResolvedValueOnce({
      items: [],
      limit: 50,
      offset: 0,
      nextOffset: null,
    });
    const wrapper = open(ParentSectionView);
    await flushPromises();
    expect(wrapper.text()).toContain('education.noHomework');
    vi.mocked(parentApi.homework).mockRejectedValue(new ApiError(403, 'access_denied', 'Denied'));
    await wrapper.find('button').trigger('click');
    await flushPromises();
    expect(wrapper.text()).not.toContain('Child A homework');
    expect(useParentWorkspace().child).toBeNull();
  });
});
describe('teacher family card', () => {
  it('hides confirmation and mutations when their individual capabilities are absent', async () => {
    vi.mocked(parentApi.guardians).mockResolvedValue(family(false, false));
    const wrapper = open(StudentFamilyPanel, { studentId: child.id });
    await flushPromises();
    expect(wrapper.text()).toContain('Guardian A');
    expect(wrapper.text()).not.toContain('education.familyConfirm');
    expect(wrapper.text()).not.toContain('education.familyAdd');
    expect(wrapper.findAll('select')).toHaveLength(0);
  });
  it('lets a managing teacher add a pending relationship without a confirm control', async () => {
    vi.mocked(parentApi.guardians).mockResolvedValue(family(true, false));
    vi.mocked(parentApi.candidates).mockResolvedValue({
      items: [{ id: guardian, name: 'Guardian A' }],
    });
    vi.mocked(parentApi.addGuardian).mockResolvedValue({ added: true });
    const wrapper = open(StudentFamilyPanel, { studentId: child.id });
    await flushPromises();
    await wrapper.find('#family-person-search').setValue('Guardian');
    await wrapper.find('form').trigger('submit');
    await flushPromises();
    const selects = wrapper.findAll('select');
    await selects[1]!.setValue(guardian);
    await selects[2]!.setValue('father');
    await wrapper.findAll('form')[1]!.trigger('submit');
    await flushPromises();
    expect(parentApi.addGuardian).toHaveBeenCalledWith(
      child.organizationId,
      child.id,
      guardian,
      'father',
      expect.any(AbortSignal),
    );
    expect(wrapper.text()).toContain('education.familypending');
    expect(wrapper.text()).not.toContain('education.familyConfirm');
  });
  it('requires the second revoke action and preserves the selected child identifier', async () => {
    vi.mocked(parentApi.guardians).mockResolvedValue(family(true, false));
    vi.mocked(parentApi.familyAction).mockResolvedValue({ revoked: true });
    const wrapper = open(StudentFamilyPanel, { studentId: child.id });
    await flushPromises();
    await wrapper
      .findAll('button')
      .find((item) => item.text() === 'education.familyRevoke')!
      .trigger('click');
    expect(parentApi.familyAction).not.toHaveBeenCalled();
    await wrapper
      .findAll('button')
      .find((item) => item.text() === 'education.confirm')!
      .trigger('click');
    await flushPromises();
    expect(parentApi.familyAction).toHaveBeenCalledWith(
      child.organizationId,
      child.id,
      guardian,
      'revoke',
      expect.any(AbortSignal),
    );
  });
});
