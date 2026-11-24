export type ActivityLanguage = 'ru' | 'en';
export type TypingMode = 'words' | 'sentences' | 'numbers' | 'code' | 'accuracy';

export type QuestionType = 'single_choice' | 'multi_choice' | 'true_false' | 'short_text' | 'numeric' | 'matching';

export interface AssessmentOption {
  id: string;
  label: string;
}

export interface AssessmentQuestion {
  id: string;
  type: QuestionType;
  prompt: string;
  points: number;
  options?: AssessmentOption[];
  items?: unknown[];
  answerKey?: unknown;
  explanation?: string;
  tolerance?: number;
}

export interface AssessmentVersion {
  id: string;
  assessmentId: string;
  versionNumber: number;
  title: string;
  instructions: string;
  timeLimitSeconds: number | null;
  passingScorePercent: number;
  maxAttempts: number;
  shuffleQuestions: boolean;
  shuffleOptions: boolean;
  revealAnswersPolicy: 'never' | 'immediate' | 'after_grading' | 'deadline';
  gradingPolicy: 'highest' | 'latest' | 'average';
  questionsCount?: number;
  questions?: AssessmentQuestion[];
  createdAt: string;
}

export interface AssessmentItem {
  id: string;
  title: string;
  description: string;
  status: 'DRAFT' | 'PUBLISHED' | 'CLOSED' | 'ARCHIVED';
  latestVersionId: string | null;
  latestVersionNumber?: number;
  questionsCount?: number;
  versionsCount?: number;
  attemptsCount?: number;
  avgScore?: number | null;
  createdAt: string;
  updatedAt: string;
}

export interface AssessmentQuestionEvaluation {
  questionId: string;
  type: QuestionType;
  correct: boolean;
  earnedPoints: number;
  possiblePoints: number;
  explanation?: string;
  correctAnswer?: unknown;
  clientAnswer?: unknown;
}

export interface AssessmentResult {
  score: number;
  maxScore: number;
  percentage: number;
  passed: boolean;
  passingScorePercent: number;
  totalQuestions: number;
  correctQuestions: number;
  elapsedSeconds: number;
  questions?: AssessmentQuestionEvaluation[];
}

export interface AssessmentAttempt {
  id: string;
  versionId: string;
  studentId: string;
  state: 'IN_PROGRESS' | 'SUBMITTED' | 'EVALUATED' | 'AWAITING_MANUAL_REVIEW';
  startedAt: string;
  submittedAt?: string | null;
  answers: Record<string, unknown>;
  result?: AssessmentResult | null;
  score?: number | null;
  maxScore?: number | null;
  percentage?: number | null;
  passed?: boolean | null;
  manualReviewNotes?: string | null;
}

export interface QuestionBankItem {
  id: string;
  category: string;
  tags: string[];
  difficulty: 1 | 2 | 3;
  questionType: QuestionType;
  content: {
    prompt: string;
    options?: AssessmentOption[];
    tolerance?: number;
  };
  answerKey: unknown;
  explanation: string;
  defaultPoints: number;
  createdAt: string;
  updatedAt: string;
}

export interface ActivityConfig {
  language: ActivityLanguage;
  durationSeconds?: 15 | 30 | 60 | 90 | 120;
  mode?: TypingMode;
  difficulty?: 1 | 2 | 3;
  minAccuracy?: number;
  targetWpm?: number;
  maxAttempts?: number;
  roundCount?: 5 | 10;
  assessmentId?: string;
  versionId?: string;
  timeLimitSeconds?: number;
  passingScorePercent?: number;
  shuffleQuestions?: boolean;
  shuffleOptions?: boolean;
  revealAnswersPolicy?: 'never' | 'immediate' | 'after_grading' | 'deadline';
  gradingPolicy?: 'highest' | 'latest' | 'average';
}

export interface LogicAnswer {
  roundId: string;
  optionId: string;
}

export interface LogicRound {
  id: string;
  kind: string;
  prompt: string;
  options: { id: string; label: string }[];
}

export interface ActivityExercise {
  attempt: {
    id: string;
    activityCode?: string;
    assignmentId?: string | null;
    status: string;
    config?: ActivityConfig;
    stateVersion?: number;
    state?: {
      typed?: string;
      answers?: LogicAnswer[] | Record<string, unknown>;
      currentRound?: number;
      currentQuestionIndex?: number;
      flaggedQuestions?: string[];
      elapsedSeconds?: number;
    };
    startedAt: string;
  };
  exercise: {
    text?: string;
    title?: string;
    durationSeconds?: 15 | 30 | 60;
    timeLimitSeconds?: number | null;
    passingScorePercent?: number;
    language?: ActivityLanguage;
    mode?: TypingMode;
    difficulty?: number;
    version?: number;
    rounds?: LogicRound[];
    questions?: AssessmentQuestion[];
  };
  result?: LogicResult | AssessmentResult | null;
}

export interface LogicResult {
  attemptId: string;
  correctAnswers: number;
  totalRounds: number;
  errors: number;
  score: number;
  accuracy: number;
  passed: boolean;
  rounds: {
    roundId: string;
    optionId: string | null;
    correctOptionId: string;
    correct: boolean;
    explanation: string;
  }[];
}

export interface Achievement {
  code: string;
  name: string;
  description: string;
  ruleVersion: number;
  unlockedAt: string | null;
  sourceType: string | null;
  sourceId: string | null;
  progress: { current: number; target: number };
}

export interface ActivityHistoryEntry {
  attemptId: string;
  createdAt: string;
  language: ActivityLanguage;
  difficulty?: number;
  wpm?: number;
  rawWpm?: number;
  accuracy: number;
  consistency?: number;
  errors: number;
  score?: number;
  correctAnswers?: number;
  totalRounds?: number;
  passed?: boolean;
  type?: string;
  title?: string;
}

export interface ActivityProgress {
  typing: {
    attempts: number;
    bestWpm: number | null;
    averageAccuracy: number | null;
    history: ActivityHistoryEntry[];
  };
  logic: {
    attempts: number;
    bestScore: number | null;
    history: ActivityHistoryEntry[];
  };
  assessments?: {
    attempts: number;
    passedCount: number;
    avgScore: number | null;
    history: ActivityHistoryEntry[];
  };
  achievements: Achievement[];
  completedAssignments: number;
  historyLimit?: number;
  historyTruncated?: boolean;
}

export interface GroupActivityProgress {
  items: (ActivityProgress & { studentId: string; name: string })[];
  from: string;
  to: string;
}

export function defaultActivityConfig(code = 'platform-typing'): ActivityConfig {
  if (code === 'platform-math-sprint') {
    return { language: 'ru', durationSeconds: 60, difficulty: 1 };
  }
  if (code === 'platform-quiz') {
    return { language: 'ru', difficulty: 1, roundCount: 5 };
  }
  if (code === 'platform-assessment') {
    return {
      language: 'ru',
      passingScorePercent: 60,
      timeLimitSeconds: 1800,
      shuffleQuestions: false,
      shuffleOptions: false,
      maxAttempts: 1,
      revealAnswersPolicy: 'after_grading',
      gradingPolicy: 'highest',
    };
  }
  return code === 'platform-logic'
    ? { language: 'ru', difficulty: 1, roundCount: 5 }
    : { language: 'ru', durationSeconds: 30, mode: 'words', difficulty: 1 };
}
