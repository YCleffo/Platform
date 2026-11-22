import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiResponseError } from '@/shared/api/errors';
import type { Achievement, ActivityConfig, ActivityExercise, ActivityHistoryEntry, ActivityProgress, GroupActivityProgress, LogicAnswer, LogicResult } from './activityTypes';
import type { StudentActivityDefinition } from './student/api';

type Guard<T> = (v: unknown) => v is T;
const record = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v);
const str = (v: unknown): v is string => typeof v === 'string';
const num = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v);
const count = (v: unknown): v is number => num(v) && Number.isSafeInteger(v) && v >= 0;
const instant = (v: unknown): v is string => str(v) && v.includes('T') && Number.isFinite(Date.parse(v));
const id = (v: unknown): v is string => str(v) && /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(v);
export const activityExerciseGuard: Guard<ActivityExercise> = (v): v is ActivityExercise => record(v) && record(v.attempt) && id(v.attempt.id) && str(v.attempt.status) && instant(v.attempt.startedAt) && (v.attempt.stateVersion === undefined || count(v.attempt.stateVersion)) && (v.attempt.state === undefined || record(v.attempt.state)) && record(v.exercise) && ['ru', 'en'].includes(String(v.exercise.language)) && (str(v.exercise.text) && [15, 30, 60].includes(Number(v.exercise.durationSeconds)) || Array.isArray(v.exercise.rounds) && v.exercise.rounds.length > 0 && v.exercise.rounds.every(r => record(r) && str(r.id) && str(r.kind) && str(r.prompt) && Array.isArray(r.options) && r.options.length > 1 && r.options.every(o => record(o) && str(o.id) && str(o.label))));
export const logicResultGuard: Guard<LogicResult> = (v): v is LogicResult => record(v) && id(v.attemptId) && count(v.correctAnswers) && count(v.totalRounds) && count(v.errors) && num(v.score) && num(v.accuracy) && typeof v.passed === 'boolean' && Array.isArray(v.rounds) && v.rounds.every(r => record(r) && str(r.roundId) && (r.optionId === null || str(r.optionId)) && str(r.correctOptionId) && typeof r.correct === 'boolean' && str(r.explanation));
export const achievementGuard: Guard<Achievement> = (v): v is Achievement => record(v) && str(v.code) && str(v.name) && str(v.description) && count(v.ruleVersion) && (v.unlockedAt === null || instant(v.unlockedAt)) && (v.sourceType === null || str(v.sourceType)) && (v.sourceId === null || str(v.sourceId)) && record(v.progress) && num(v.progress.current) && v.progress.current >= 0 && num(v.progress.target) && v.progress.target > 0;
const historyGuard: Guard<ActivityHistoryEntry> = (v): v is ActivityHistoryEntry => record(v) && id(v.attemptId) && instant(v.createdAt) && ['ru', 'en'].includes(String(v.language)) && num(v.accuracy) && count(v.errors) && (v.wpm === undefined || num(v.wpm)) && (v.score === undefined || num(v.score));
export const progressGuard: Guard<ActivityProgress> = (v): v is ActivityProgress => record(v) && record(v.typing) && count(v.typing.attempts) && (v.typing.bestWpm === null || num(v.typing.bestWpm)) && (v.typing.averageAccuracy === null || num(v.typing.averageAccuracy)) && Array.isArray(v.typing.history) && v.typing.history.every(historyGuard) && record(v.logic) && count(v.logic.attempts) && (v.logic.bestScore === null || num(v.logic.bestScore)) && Array.isArray(v.logic.history) && v.logic.history.every(historyGuard) && Array.isArray(v.achievements) && v.achievements.every(achievementGuard) && count(v.completedAssignments);
async function request<T>(org: string, path: string, guard: Guard<T>, method: 'GET' | 'POST' | 'PUT' = 'GET', body?: unknown, signal?: AbortSignal): Promise<T> {
  const value = await coreAdminHttp.request<unknown>(`/education${path}`, { method, headers: { 'X-Education-Organization': org }, ...(body === undefined ? {} : { body }), signal });
  if (!guard(value)) throw new ApiResponseError(path);
  return value;
}
const pathId = (value: string) => encodeURIComponent(value);
export const activityApi = {
  catalog: (org: string, signal?: AbortSignal) => request(org, '/activities', (v): v is { items: StudentActivityDefinition[] } => record(v) && Array.isArray(v.items) && v.items.every(a => record(a) && id(a.id) && str(a.code) && str(a.name) && str(a.kind) && count(a.contractVersion) && typeof a.enabled === 'boolean' && record(a.config) && Array.isArray(a.metrics) && str(a.requiredPermission)), 'GET', undefined, signal),
  startHomework: (org: string, homeworkId: string) => request(org, `/student/homework/${pathId(homeworkId)}/activity/start`, activityExerciseGuard, 'POST', {}),
  startLogic: (org: string, config: ActivityConfig & { assignmentId?: string }) => request(org, '/student/activities/platform-logic/attempts', activityExerciseGuard, 'POST', config),
  attempt: (org: string, attemptId: string, signal?: AbortSignal) => request(org, `/student/attempts/${pathId(attemptId)}`, activityExerciseGuard, 'GET', undefined, signal),
  saveState: (org: string, attemptId: string, version: number, state: { typed?: string; answers?: LogicAnswer[]; currentRound?: number }) => request(org, `/student/attempts/${pathId(attemptId)}/state`, activityExerciseGuard, 'PUT', { version, state }),
  finishLogic: (org: string, attemptId: string, answers: LogicAnswer[]) => request(org, `/student/attempts/${pathId(attemptId)}/finish`, logicResultGuard, 'PUT', { answers }),
  achievements: (org: string, signal?: AbortSignal) => request(org, '/student/achievements', (v): v is { items: Achievement[] } => record(v) && Array.isArray(v.items) && v.items.every(achievementGuard), 'GET', undefined, signal),
  progress: (org: string, signal?: AbortSignal) => request(org, '/student/activity-progress', progressGuard, 'GET', undefined, signal),
  groupProgress: (org: string, groupId: string, from: string, to: string, activity: string, signal?: AbortSignal) => request(org, `/groups/${pathId(groupId)}/activity-progress?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&activity=${encodeURIComponent(activity)}`, (v): v is GroupActivityProgress => record(v) && str(v.from) && str(v.to) && Array.isArray(v.items) && v.items.every(r => progressGuard(r) && id((r as ActivityProgress & { studentId?: unknown }).studentId) && str((r as ActivityProgress & { name?: unknown }).name)), 'GET', undefined, signal),
};
