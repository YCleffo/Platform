import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, ref } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { educationApi, type GroupProgram, type Lesson } from './api';
import LessonEditView from './LessonEditView.vue';

const refreshHeader = vi.hoisted(() => vi.fn());
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: 'lesson' } }),
  useRouter: () => ({ push: vi.fn() }),
}));
vi.mock('./workspace', () => ({
  useEducationWorkspace: () => ({
    organizationId: 'school', revision: 0, header: { timezone: 'Asia/Yekaterinburg' }, refreshHeader,
  }),
}));
vi.mock('./i18n', () => ({
  useEducationI18n: () => ({ t: (key: string) => key, locale: ref('en') }),
}));
vi.mock('./api', () => ({
  educationApi: { groups: vi.fn(), lesson: vi.fn(), groupPrograms: vi.fn(), updateLesson: vi.fn() },
}));
const lesson: Lesson = {
  id: 'lesson',
  title: 'Fractions',
  groupId: 'group-a',
  groupName: 'Group A',
  startsAt: '2026-11-24T07:00:00Z',
  endsAt: '2026-11-24T08:00:00Z',
  location: '',
  status: 'scheduled',
  studentCount: 1,
  curriculumLessonId: 'original',
  curriculumSnapshot: { programName: 'Historical program', moduleName: 'Module A', title: 'Original lesson' },
};
const program: GroupProgram = {
  id: 'assignment',
  programId: 'program',
  name: 'Mathematics',
  assignedAt: '2026-11-18T07:00:00Z',
  endedAt: null,
  modules: [{
    id: 'module', name: 'Arithmetic', description: '', position: 0, archivedAt: null,
    lessons: [{ id: 'replacement', title: 'Mixed fractions', description: '', position: 0, archivedAt: null }],
  }],
};
const dropdown = defineComponent({
  props: ['id', 'options', 'modelValue'],
  emits: ['update:modelValue'],
  template: '<select :id="id" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option></select>',
});
const input = defineComponent({
  props: ['id', 'modelValue', 'type'],
  emits: ['update:modelValue'],
  template: '<input :id="id" :type="type" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
});

beforeEach(() => {
  vi.clearAllMocks();
  coreAdminSession.set({ accessToken: 'teacher', refreshToken: 'refresh', accessExpiresAt: '', sessionExpiresAt: '', sessionId: 'teacher' });
  vi.mocked(educationApi.lesson).mockResolvedValue(lesson);
  vi.mocked(educationApi.groups).mockResolvedValue({
    items: [{ id: 'group-a', name: 'Group A' }, { id: 'group-b', name: 'Group B' }],
    limit: 50, offset: 0, nextOffset: null,
  });
  vi.mocked(educationApi.groupPrograms).mockImplementation(async (_org, group) => ({ items: group === 'group-b' ? [program] : [] }));
  vi.mocked(educationApi.updateLesson).mockResolvedValue(lesson);
});
afterEach(() => coreAdminSession.clear());

async function open() {
  const wrapper = mount(LessonEditView, {
    global: { stubs: { AppDropdown: dropdown, AppInput: input, RouterLink: true } },
  });
  await flushPromises();
  return wrapper;
}

describe('editing scheduled lesson curriculum', () => {
  it('keeps the historical curriculum link on a time-only edit', async () => {
    const wrapper = await open();
    expect(wrapper.get('#education-edit-curriculum-lesson').text()).toContain('Historical program / Module A / Original lesson');
    await wrapper.get('#education-edit-starts-at').setValue('2026-11-24T12:30');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    const body = vi.mocked(educationApi.updateLesson).mock.calls[0]?.[2];
    expect(body?.startsAt).toBe('2026-11-24T07:30:00.000Z');
    expect(body).not.toHaveProperty('curriculumLessonId');
    wrapper.unmount();
  });

  it('offers the new group curriculum when moving the lesson and sends the chosen binding', async () => {
    const wrapper = await open();
    await wrapper.get('#education-edit-group').setValue('group-b');
    await flushPromises();
    const list = wrapper.get('#education-edit-curriculum-lesson');
    expect(list.text()).not.toContain('Original lesson');
    expect(list.text()).toContain('Mathematics / Arithmetic / Mixed fractions');
    await list.setValue('replacement');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(educationApi.updateLesson).toHaveBeenCalledWith('school', 'lesson', expect.objectContaining({
      groupId: 'group-b', curriculumLessonId: 'replacement',
    }));
    wrapper.unmount();
  });

  it('sends an explicit null when converting the lesson to standalone', async () => {
    const wrapper = await open();
    await wrapper.get('#education-edit-curriculum-lesson').setValue('');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(educationApi.updateLesson).toHaveBeenCalledWith('school', 'lesson', expect.objectContaining({
      groupId: 'group-a', curriculumLessonId: null,
    }));
    wrapper.unmount();
  });
});
