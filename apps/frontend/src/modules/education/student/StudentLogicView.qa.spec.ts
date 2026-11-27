// @vitest-environment happy-dom
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { coreAdminSession } from '@/core/admin/session';
import StudentLogicView from './StudentLogicView.vue';
import LogicLabPlugin from '@/modules/activity/plugins/LogicLabPlugin.vue';

const mockQuery = ref<Record<string, string>>({});
const replaceMock = vi.fn();

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: mockQuery.value }),
  useRouter: () => ({ replace: replaceMock }),
}));

vi.mock('../i18n', () => ({
  useEducationI18n: () => ({ t: (k: string) => k }),
}));

vi.mock('./session', () => ({
  studentSession: { organizationId: '01950000-0000-7000-8000-000000000001' },
}));

vi.mock('./learningAssignments', () => ({
  useLearningAssignments: () => ({
    items: ref([]),
    id: ref(''),
    selected: ref(null),
    loading: ref(false),
    error: ref(null),
    allowed: ref(true),
    refresh: vi.fn(),
  }),
}));

vi.mock('./api', () => ({
  studentPortalApi: {
    home: vi.fn().mockResolvedValue({ pendingAttempts: [] }),
  },
}));

vi.mock('../homework/api', () => ({
  homeworkApi: {
    studentDetail: vi.fn().mockResolvedValue({
      assignment: {
        id: '01950000-0000-7000-8000-000000000077',
        title: 'Logic HW',
        status: 'PUBLISHED',
        type: 'activity',
        activityCode: 'platform-logic',
        maxAttempts: 3,
      },
      recipient: {
        id: '01950000-0000-7000-8000-000000000088',
        activityAssignmentId: '01950000-0000-7000-8000-000000000099',
      },
      submission: { state: 'IN_PROGRESS' },
    }),
    attachActivity: vi.fn().mockResolvedValue({}),
  },
}));

vi.mock('@/modules/activity/activityApi', () => ({
  activityApi: {
    bootstrapPlugin: vi.fn().mockResolvedValue({
      id: '01950000-0000-7000-8000-000000000010',
      pluginCode: 'platform-logic',
      title: 'Logic & Patterns Lab',
    }),
  },
}));

const wrappers: Array<VueWrapper> = [];
const flush = async () => {
  await flushPromises();
  await new Promise<void>((resolve) => setTimeout(resolve, 10));
  await flushPromises();
};

beforeEach(() => {
  vi.clearAllMocks();
  mockQuery.value = {};
  coreAdminSession.set({
    accessToken: 'token',
    refreshToken: 'refresh',
    accessExpiresAt: '',
    sessionExpiresAt: '',
    sessionId: '01950000-0000-7000-8000-000000000002',
  });
});

afterEach(() => {
  while (wrappers.length) wrappers.pop()?.unmount();
  coreAdminSession.clear();
});

describe('StudentLogicView & LogicLabPlugin (Canonical Activity Engine)', () => {
  it('bootstraps canonical platform-logic item and mounts ActivityRunnerView in open practice', async () => {
    const { activityApi } = await import('@/modules/activity/activityApi');
    const wrapper = mount(StudentLogicView, {
      global: {
        stubs: {
          RouterLink: true,
          ActivityRunnerView: {
            props: ['organizationId', 'activityId', 'assignmentId', 'initialConfig'],
            template: '<div data-test="canonical-runner" :data-aid="activityId" :data-asgn="assignmentId"></div>',
          },
        },
      },
    });
    wrappers.push(wrapper);
    await flush();

    expect(activityApi.bootstrapPlugin).toHaveBeenCalledWith(
      '01950000-0000-7000-8000-000000000001',
      'platform-logic',
    );
    const runner = wrapper.find('[data-test="canonical-runner"]');
    expect(runner.exists()).toBe(true);
    expect(runner.attributes('data-aid')).toBe('01950000-0000-7000-8000-000000000010');
  });

  it('attaches finished canonical attempt to homework recipient when run inside homework mode', async () => {
    const { homeworkApi } = await import('../homework/api');
    mockQuery.value = { homework: '01950000-0000-7000-8000-000000000077' };

    const wrapper = mount(StudentLogicView, {
      global: {
        stubs: {
          RouterLink: true,
          ActivityRunnerView: {
            emits: ['finished'],
            props: ['organizationId', 'activityId', 'assignmentId'],
            template:
              '<button data-test="finish-sim" :data-asgn="assignmentId" @click="$emit(\'finished\', { attemptId: \'01950000-0000-7000-8000-000000000123\', score: 5, maxScore: 5, percentage: 100, passed: true })">Finish</button>',
          },
        },
      },
    });
    wrappers.push(wrapper);
    await flush();

    const btn = wrapper.find('[data-test="finish-sim"]');
    expect(btn.exists()).toBe(true);
    expect(btn.attributes('data-asgn')).toBe('01950000-0000-7000-8000-000000000099');

    await btn.trigger('click');
    await flush();

    expect(homeworkApi.attachActivity).toHaveBeenCalledWith(
      '01950000-0000-7000-8000-000000000001',
      '01950000-0000-7000-8000-000000000077',
      '01950000-0000-7000-8000-000000000123',
    );
  });

  it('LogicLabPlugin submits answers and renders breakdown explanations in readonly review', async () => {
    const wrapper = mount(LogicLabPlugin, {
      props: {
        exercise: {
          rounds: [
            { id: 'r1', prompt: 'Continue: 2, 4, 6, ?', options: ['7', '8', '9'] },
          ],
        },
        readonly: true,
        initialAnswers: { r1: '7' },
        evaluation: {
          breakdown: [
            {
              itemId: 'r1',
              correct: false,
              expected: '8',
              actual: '7',
              explanation: 'Each term adds +2.',
            },
          ],
        },
      },
    });
    wrappers.push(wrapper);

    expect(wrapper.text()).toContain('Each term adds +2.');
    expect(wrapper.text()).toContain('(8)');
  });
});
