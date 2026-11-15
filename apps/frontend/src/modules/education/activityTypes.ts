export type ActivityLanguage = 'ru' | 'en';
export type TypingMode = 'words' | 'sentences' | 'numbers' | 'code' | 'accuracy';
export interface ActivityConfig {
  language: ActivityLanguage;
  durationSeconds?: 15 | 30 | 60;
  mode?: TypingMode;
  difficulty?: 1 | 2 | 3;
  minAccuracy?: number;
  targetWpm?: number;
  maxAttempts?: number;
  roundCount?: 5 | 10;
}
export interface LogicAnswer { roundId: string; optionId: string }
export interface LogicRound { id: string; kind: string; prompt: string; options: { id: string; label: string }[] }
export interface ActivityExercise {
  attempt: { id: string; activityCode?: string; status: string; config?: ActivityConfig; stateVersion?: number; state?: { typed?: string; answers?: LogicAnswer[]; currentRound?: number }; startedAt: string };
  exercise: { text?: string; durationSeconds?: 15 | 30 | 60; language: ActivityLanguage; mode?: TypingMode; difficulty?: number; version?: number; rounds?: LogicRound[] };
  result?: LogicResult | null;
}
export interface LogicResult {
  attemptId: string; correctAnswers: number; totalRounds: number; errors: number; score: number; accuracy: number; passed: boolean;
  rounds: { roundId: string; optionId: string | null; correctOptionId: string; correct: boolean; explanation: string }[];
}
export interface Achievement {
  code: string; name: string; description: string; ruleVersion: number; unlockedAt: string | null;
  sourceType: string | null; sourceId: string | null; progress: { current: number; target: number };
}
export interface ActivityHistoryEntry {
  attemptId: string; createdAt: string; language: ActivityLanguage; difficulty?: number;
  wpm?: number; accuracy: number; errors: number; score?: number; correctAnswers?: number; totalRounds?: number; passed?: boolean;
}
export interface ActivityProgress {
  typing: { attempts: number; bestWpm: number | null; averageAccuracy: number | null; history: ActivityHistoryEntry[] };
  logic: { attempts: number; bestScore: number | null; history: ActivityHistoryEntry[] };
  achievements: Achievement[]; completedAssignments: number; historyLimit?: number; historyTruncated?: boolean;
}
export interface GroupActivityProgress { items: (ActivityProgress & { studentId: string; name: string })[]; from: string; to: string }
export function defaultActivityConfig(code = 'platform-typing'): ActivityConfig {
  return code === 'platform-logic' ? { language: 'ru', difficulty: 1, roundCount: 5 } : { language: 'ru', durationSeconds: 30, mode: 'words', difficulty: 1 };
}
