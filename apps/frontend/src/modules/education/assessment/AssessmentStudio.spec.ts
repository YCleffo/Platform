import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { ref } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { assessmentApi } from './assessmentApi';
import AssessmentListView from './AssessmentListView.vue';
import AssessmentEditorView from './AssessmentEditorView.vue';
import StudentAssessmentRunner from './StudentAssessmentRunner.vue';
import AssessmentResultView from './AssessmentResultView.vue';
import type { AssessmentItem, AssessmentResult } from '../activityTypes';

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

  it('StudentAssessmentRunner loads attempt and handles answer selection', async () => {
    const mockAttempt = {
      id: 'att-100',
      versionId: 'v-1',
      studentId: 'stud-1',
      state: 'IN_PROGRESS' as const,
      startedAt: new Date().toISOString(),
      timeLimitSeconds: 600,
      passingScorePercent: 75,
      title: 'Operating Systems Quiz',
      instructions: 'Choose best answers',
      questions: [
        {
          id: 'q1',
          type: 'single_choice' as const,
          prompt: 'Which OS component manages process scheduling?',
          points: 2,
          options: [
            { id: 'opt_kernel', label: 'Kernel' },
            { id: 'opt_browser', label: 'Web Browser' },
          ],
        },
        {
          id: 'q2',
          type: 'true_false' as const,
          prompt: 'RAM is non-volatile memory.',
          points: 1,
        },
      ],
      answers: {},
    };

    vi.mocked(assessmentApi.startStudentAssessment).mockResolvedValue(mockAttempt);
    vi.mocked(assessmentApi.saveStudentAssessmentAttempt).mockResolvedValue({ saved: true });

    const wrapper = mount(StudentAssessmentRunner, {
      props: { assessmentId: 'asm-100' },
    });
    wrappers.push(wrapper);
    await flushPromises();

    expect(wrapper.text()).toContain('Operating Systems Quiz');
    expect(wrapper.text()).toContain('Which OS component manages process scheduling?');
    expect(wrapper.text()).toContain('Kernel');

    // Select option
    const optionBtn = wrapper.findAll('.edu-runner__option').find((b) => b.text().includes('Kernel'));
    expect(optionBtn).toBeDefined();
    await optionBtn!.trigger('click');
    await flushPromises();

    expect(optionBtn!.classes()).toContain('edu-runner__option--selected');
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

