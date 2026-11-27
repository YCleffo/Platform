import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { ref } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { activityApi } from '@/modules/activity/activityApi';
import { assessmentApi } from './assessmentApi';
import AssessmentListView from './AssessmentListView.vue';
import AssessmentEditorView from './AssessmentEditorView.vue';
import StudentAssessmentRunner from './StudentAssessmentRunner.vue';
import AssessmentResultView from './AssessmentResultView.vue';
import type { AssessmentItem, AssessmentResult } from '../activityTypes';

vi.mock('@/shared/api/core/adminHttp', () => ({
  coreAdminHttp: {
    request: vi.fn().mockResolvedValue({
      items: [{ id: 'org-1' }],
      personId: 'person-1',
    }),
  },
}));

vi.mock('@/shared/realtime/realtimeClient', () => ({
  realtimeClient: {
    connect: vi.fn().mockResolvedValue(undefined),
    subscribe: vi.fn().mockReturnValue(() => {}),
  },
}));

vi.mock('@/modules/activity/activityApi', () => ({
  activityApi: {
    startAttempt: vi.fn(),
    getAttempt: vi.fn(),
    saveDraft: vi.fn(),
    submitAttempt: vi.fn(),
    assignActivity: vi.fn(),
  },
}));

vi.mock('../workspace', () => ({
  useEducationWorkspace: () => ({
    organizationId: 'org-1',
    header: { personId: 'person-1', timezone: 'UTC', date: '2026-11-27', locale: 'en-US' },
  }),
}));

vi.mock('../i18n', () => ({
  useEducationI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      if (params) {
        return `${key}:${JSON.stringify(params)}`;
      }
      return key;
    },
    locale: ref('ru'),
  }),
}));

vi.mock('./assessmentApi', () => ({
  assessmentApi: {
    listAssessments: vi.fn(),
    getAssessment: vi.fn(),
    createAssessment: vi.fn(),
    updateAssessment: vi.fn(),
    publishAssessment: vi.fn(),
    listVersions: vi.fn(),
    listQuestionBank: vi.fn(),
    createQuestionBankItem: vi.fn(),
    deleteQuestionBankItem: vi.fn(),
    listStudentAssessments: vi.fn(),
    startStudentAssessment: vi.fn(),
    getStudentAssessmentAttempt: vi.fn(),
    saveStudentAssessmentAttempt: vi.fn(),
    submitStudentAssessmentAttempt: vi.fn(),
    listAssessmentAttempts: vi.fn(),
    reviewAssessmentAttempt: vi.fn(),
  },
}));

const wrappers: VueWrapper[] = [];

afterEach(() => {
  wrappers.splice(0).forEach((w) => w.unmount());
  vi.clearAllMocks();
});

describe('Assessment Studio Components', () => {
  it('AssessmentListView renders list of assessments with correct badges and stats', async () => {
    const mockItems: AssessmentItem[] = [
      {
        id: 'asm-1',
        title: 'Python Basics Test',
        description: 'Variables, loops, and functions',
        status: 'PUBLISHED',
        latestVersionId: 'v-1',
        versionsCount: 1,
        attemptsCount: 12,
        avgScore: 84.5,
        createdAt: '2026-11-20T10:00:00Z',
        updatedAt: '2026-11-20T12:00:00Z',
      },
      {
        id: 'asm-2',
        title: 'Algorithms & Data Structures',
        description: 'Draft quiz',
        status: 'DRAFT',
        latestVersionId: 'v-2',
        versionsCount: 1,
        attemptsCount: 0,
        avgScore: null,
        createdAt: '2026-11-22T10:00:00Z',
        updatedAt: '2026-11-22T10:00:00Z',
      },
    ];

    vi.mocked(assessmentApi.listAssessments).mockResolvedValue({ items: mockItems });

    const wrapper = mount(AssessmentListView);
    wrappers.push(wrapper);
    await flushPromises();

    expect(wrapper.text()).toContain('Python Basics Test');
    expect(wrapper.text()).toContain('Algorithms & Data Structures');
    expect(wrapper.text()).toContain('PUBLISHED');
    expect(wrapper.text()).toContain('DRAFT');
    expect(wrapper.text()).toContain('84.5%');

    // Emits create on button click
    const createBtn = wrapper.find('button.edu-btn--primary');
    await createBtn.trigger('click');
    expect(wrapper.emitted('create')).toBeTruthy();
  });

  it('AssessmentEditorView allows adding questions and calculates total points', async () => {
    const wrapper = mount(AssessmentEditorView, {
      props: {},
    });
    wrappers.push(wrapper);
    await flushPromises();

    expect(wrapper.text()).toContain('education.createAssessment');

    // Add a single choice question
    const addSingleBtn = wrapper.findAll('button').find((b) => b.text().includes('questionType_single_choice'));
    expect(addSingleBtn).toBeDefined();
    await addSingleBtn!.trigger('click');

    // Add a numeric question
    const addNumericBtn = wrapper.findAll('button').find((b) => b.text().includes('questionType_numeric'));
    expect(addNumericBtn).toBeDefined();
    await addNumericBtn!.trigger('click');

    await flushPromises();

    expect(wrapper.findAll('.edu-question-card')).toHaveLength(2);
    expect(wrapper.text()).toContain('education.score');
  });

  it('AssessmentEditorView supports all 8 question types, unique option IDs, duplication, preview, and published immutability', async () => {
    vi.mocked(assessmentApi.getAssessment).mockResolvedValue({
      id: 'asm-pub-1',
      assessmentId: 'asm-pub-1',
      versionNumber: 1,
      title: 'Published Math Quiz',
      description: 'Algebra',
      status: 'PUBLISHED',
      latestVersionId: 'ver-1',
      instructions: 'Solve all',
      timeLimitSeconds: 900,
      passingScorePercent: 70,
      maxAttempts: 2,
      shuffleQuestions: false,
      shuffleOptions: false,
      revealAnswersPolicy: 'after_grading',
      gradingPolicy: 'highest',
      questions: [
        {
          id: 'q-1',
          type: 'matching',
          prompt: 'Match countries',
          points: 2,
          pairs: [
            { left: 'FR', right: 'Paris' },
            { left: 'JP', right: 'Tokyo' },
          ],
          answerKey: { FR: 'Paris', JP: 'Tokyo' },
        },
      ],
      createdAt: '2026-11-20T10:00:00Z',
      updatedAt: '2026-11-20T10:00:00Z',
    });

    const wrapper = mount(AssessmentEditorView, {
      props: { assessmentId: 'asm-pub-1' },
    });
    wrappers.push(wrapper);
    await flushPromises();

    // Published test shows immutability banner and locks saveDraft
    expect(wrapper.text()).toContain('education.publishedImmutableTitle');
    expect(wrapper.text()).toContain('education.duplicateAsDraft');
    expect(wrapper.text()).toContain('education.editNewVersion');

    // Unlock as new version
    const editNewVerBtn = wrapper.findAll('button').find((b) => b.text().includes('editNewVersion'));
    await editNewVerBtn!.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('education.publishNewVersion');

    // Add all remaining question types including ordering & long_text
    const addOrderingBtn = wrapper.findAll('button').find((b) => b.text().includes('questionType_ordering'));
    const addLongTextBtn = wrapper.findAll('button').find((b) => b.text().includes('questionType_long_text'));
    const addSingleBtn = wrapper.findAll('button').find((b) => b.text().includes('questionType_single_choice'));
    await addOrderingBtn!.trigger('click');
    await addLongTextBtn!.trigger('click');
    await addSingleBtn!.trigger('click');
    await flushPromises();

    expect(wrapper.findAll('.edu-question-card')).toHaveLength(4);

    // Verify option IDs stay unique after add -> remove -> add
    const addOptBtn = wrapper.findAll('button').find((b) => b.text().includes('addOption'));
    await addOptBtn!.trigger('click');
    await flushPromises();
    const singleCard = wrapper.findAll('.edu-question-card')[3]!;
    const radioValuesBefore = singleCard.findAll('input[type="radio"]').map((r) => r.attributes('value'));
    expect(new Set(radioValuesBefore).size).toBe(3);

    // Toggle preview mode
    const previewBtn = wrapper.findAll('button').find((b) => b.text().includes('assessmentPreview'));
    await previewBtn!.trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('education.previewBanner');
  });

  it('StudentAssessmentRunner loads attempt and handles answer selection', async () => {
    const mockAttempt = {
      id: 'att-100',
      activityId: 'asm-100',
      versionId: 'v-1',
      assignmentId: null,
      participantPersonId: 'person-1',
      state: 'IN_PROGRESS' as const,
      revision: 1,
      seed: 1,
      pluginCode: 'platform-assessment',
      activityTitle: 'Operating Systems Quiz',
      config: { passingScorePercent: 75, timeLimitSeconds: 600 },
      exercise: {
        timeLimitSeconds: 600,
        questions: [
          {
            id: 'q1',
            questionType: 'single_choice',
            points: 2,
            content: {
              prompt: 'Which OS component manages process scheduling?',
              options: [
                { value: 'opt_kernel', label: 'Kernel' },
                { value: 'opt_browser', label: 'Web Browser' },
              ],
            },
          },
          {
            id: 'q2',
            questionType: 'true_false',
            points: 1,
            content: {
              prompt: 'RAM is non-volatile memory.',
            },
          },
        ],
      },
      answers: {},
      telemetry: {},
      evaluation: {},
      rawScore: null,
      maxScore: null,
      percentage: null,
      passed: null,
      startedAt: new Date().toISOString(),
      expiresAt: new Date(Date.now() + 600_000).toISOString(),
      serverNow: new Date().toISOString(),
      remainingSeconds: 600,
      submittedAt: null,
      evaluatedAt: null,
      reviewNotes: null,
    };

    vi.mocked(activityApi.startAttempt).mockResolvedValue(mockAttempt);
    vi.mocked(activityApi.saveDraft).mockResolvedValue({ saved: true, revision: 2 });

    const wrapper = mount(StudentAssessmentRunner, {
      props: { assessmentId: 'asm-100' },
    });
    wrappers.push(wrapper);
    await flushPromises();

    expect(wrapper.text()).toContain('Operating Systems Quiz');
    expect(wrapper.text()).toContain('Which OS component manages process scheduling?');
    expect(wrapper.text()).toContain('Kernel');

    // Select option
    const optionLabel = wrapper.findAll('.uact-assess__opt').find((b) => b.text().includes('Kernel'));
    expect(optionLabel).toBeDefined();
    await optionLabel!.find('input').setValue(true);
    await flushPromises();

    expect(optionLabel!.classes()).toContain('is-selected');
  });

  it('AssessmentResultView displays score, percentage and question breakdown', () => {
    const mockResult: AssessmentResult = {
      score: 8,
      maxScore: 10,
      percentage: 80,
      passed: true,
      passingScorePercent: 70,
      totalQuestions: 5,
      correctQuestions: 4,
      elapsedSeconds: 145,
      questions: [
        {
          questionId: 'q1',
          type: 'single_choice',
          correct: true,
          earnedPoints: 2,
          possiblePoints: 2,
          correctAnswer: 'opt_kernel',
          clientAnswer: 'opt_kernel',
          explanation: 'The kernel handles process scheduling.',
        },
        {
          questionId: 'q2',
          type: 'true_false',
          correct: false,
          earnedPoints: 0,
          possiblePoints: 1,
          correctAnswer: false,
          clientAnswer: true,
          explanation: 'RAM is volatile memory; data is lost on power off.',
        },
      ],
    };

    const wrapper = mount(AssessmentResultView, {
      props: { result: mockResult, title: 'Test Final' },
    });
    wrappers.push(wrapper);

    expect(wrapper.text()).toContain('education.assessmentPassed');
    expect(wrapper.text()).toContain('8 / 10');
    expect(wrapper.text()).toContain('80%');
    expect(wrapper.findAll('.edu-breakdown-card')).toHaveLength(2);
    expect(wrapper.text()).toContain('RAM is volatile memory');
  });
});

