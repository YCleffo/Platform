export type QuizQuestionType = 'single_choice' | 'multi_choice' | 'ordering';

export interface QuizOption {
  id: string;
  text: string;
}

export interface QuizQuestion {
  id: string;
  type: QuizQuestionType;
  prompt: string;
  options: QuizOption[];
  correctAnswer: string | string[]; // Option ID for single_choice; array of Option IDs for multi_choice; ordered array of Option IDs for ordering
  explanation?: string;
  points?: number;
}

export interface QuizActivityData {
  id: string;
  title: string;
  description?: string;
  passingScorePercent?: number; // default 70%
  questions: QuizQuestion[];
}

export interface QuizQuestionEvaluation {
  questionId: string;
  correct: boolean;
  userAnswer: string | string[];
  correctAnswer: string | string[];
  explanation?: string;
}

export interface QuizResult {
  score: number;
  totalPoints: number;
  percent: number;
  passed: boolean;
  questionResults: QuizQuestionEvaluation[];
}
