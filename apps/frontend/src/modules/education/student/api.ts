import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiResponseError } from '@/shared/api/errors';
import type { Lesson, Page, ResultStatus } from '../api';
import type { ActivityConfig } from '../activityTypes';

export interface StudentActivityDefinition { id: string; code: string; name: string; kind: 'typing' | 'web-game' | 'test' | 'programming' | 'native-app'; contractVersion: number; enabled: boolean; config: Record<string, unknown>; metrics: Record<string, unknown>[]; requiredPermission: string }
export interface StudentAssignment { id: string; activityId: string; activityCode: string; activityName: string; groupId: string | null; studentId: string | null; config: Record<string, unknown>; opensAt: string | null; dueAt: string | null; completion: Record<string, unknown> | null }
export interface StudentDiaryLesson { id: string; groupId: string; groupName: string; title: string; startsAt: string; endsAt: string; location: string; status: Lesson['status']; attendance: 'pending' | 'present' | 'absent'; resultStatus: ResultStatus; resultComment: string | null }
export interface TypingResult { attemptId: string; durationSeconds: 15 | 30 | 60; language: 'ru' | 'en'; keystrokes: number; correctChars: number; errors: number; wpm: number; accuracy: number; createdAt?: string; typedCharacters?: number; corrections?: number; inputErrors?: number; telemetryVerified?: boolean; passed?: boolean }
export interface ActivityAttemptSummary { id: string; activityId: string; activityCode: string; activityName: string; status: 'running' | 'finished' | 'expired' | 'failed'; source: string; startedAt: string; finishedAt: string | null; durationSeconds?: number | null; language?: string | null; wpm?: number | null; accuracy?: number | null; errors?: number | null; score?: number | null; correctAnswers?: number | null; totalRounds?: number | null }
export interface TypingExercise { attempt: { id: string; status: string; startedAt: string }; exercise: { text: string; durationSeconds: 15 | 30 | 60; language: 'ru' | 'en' } }
export interface StudentHome { nextLesson: StudentDiaryLesson | null; groups: { id: string; name: string; archivedAt: string | null }[]; recentResults: StudentDiaryLesson[]; activities: StudentActivityDefinition[]; pendingAttempts: ActivityAttemptSummary[]; bestTyping: TypingResult | null }
export interface StudentLessonDetail extends StudentDiaryLesson { materials: { id: string; name: string; mime: string; sizeBytes: number; createdAt: string }[] }

type Guard<T> = (value: unknown) => value is T;
const record = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value);
const text = (value: unknown): value is string => typeof value === 'string';
const uuid = (value: unknown): value is string => text(value) && /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(value);
const integer = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
const number = (value: unknown): value is number => typeof value === 'number' && Number.isFinite(value);
const instant = (value: unknown): value is string => text(value) && Number.isFinite(Date.parse(value)) && value.includes('T');
const nullableText = (value: unknown): value is string | null => value === null || text(value);
const nullableInstant = (value: unknown): value is string | null => value === null || instant(value);
const lessonStatus = (value: unknown): boolean => ['scheduled', 'completed', 'cancelled'].includes(String(value));
const resultStatus = (value: unknown): value is ResultStatus => ['pending', 'mastered', 'developing', 'needs_support'].includes(String(value));
const pageGuard = <T>(item: Guard<T>): Guard<Page<T>> => (v): v is Page<T> => record(v) && Array.isArray(v.items) && v.items.length <= 50 && v.items.every(item) && integer(v.limit) && integer(v.offset) && (v.nextOffset === null || integer(v.nextOffset));

const definitionGuard: Guard<StudentActivityDefinition> = (v): v is StudentActivityDefinition => record(v) && uuid(v.id) && text(v.code) && text(v.name) && ['typing', 'web-game', 'test', 'programming', 'native-app'].includes(String(v.kind)) && integer(v.contractVersion) && typeof v.enabled === 'boolean' && record(v.config) && Array.isArray(v.metrics) && text(v.requiredPermission);
const assignmentGuard: Guard<StudentAssignment> = (v): v is StudentAssignment => record(v) && uuid(v.id) && uuid(v.activityId) && text(v.activityCode) && text(v.activityName) && (v.groupId === null || uuid(v.groupId)) && (v.studentId === null || uuid(v.studentId)) && record(v.config) && nullableInstant(v.opensAt) && nullableInstant(v.dueAt) && (v.completion === null || record(v.completion));
const diaryLessonGuard: Guard<StudentDiaryLesson> = (v): v is StudentDiaryLesson => record(v) && uuid(v.id) && uuid(v.groupId) && text(v.groupName) && text(v.title) && instant(v.startsAt) && instant(v.endsAt) && text(v.location) && lessonStatus(v.status) && ['pending', 'present', 'absent'].includes(String(v.attendance)) && resultStatus(v.resultStatus) && nullableText(v.resultComment);
const typingResultGuard: Guard<TypingResult> = (v): v is TypingResult => record(v) && uuid(v.attemptId) && [15, 30, 60].includes(Number(v.durationSeconds)) && ['ru', 'en'].includes(String(v.language)) && integer(v.keystrokes) && integer(v.correctChars) && integer(v.errors) && number(v.wpm) && number(v.accuracy) && (v.createdAt === undefined || instant(v.createdAt));
const attemptSummaryGuard: Guard<ActivityAttemptSummary> = (v): v is ActivityAttemptSummary => record(v) && uuid(v.id) && uuid(v.activityId) && text(v.activityCode) && text(v.activityName) && ['running', 'finished', 'expired', 'failed'].includes(String(v.status)) && text(v.source) && instant(v.startedAt) && nullableInstant(v.finishedAt);
const exerciseGuard: Guard<TypingExercise> = (v): v is TypingExercise => record(v) && record(v.attempt) && uuid(v.attempt.id) && text(v.attempt.status) && instant(v.attempt.startedAt) && record(v.exercise) && text(v.exercise.text) && [15, 30, 60].includes(Number(v.exercise.durationSeconds)) && ['ru', 'en'].includes(String(v.exercise.language));
const homeGuard: Guard<StudentHome> = (v): v is StudentHome => record(v) && (v.nextLesson === null || diaryLessonGuard(v.nextLesson)) && Array.isArray(v.groups) && Array.isArray(v.recentResults) && v.recentResults.every(diaryLessonGuard) && Array.isArray(v.activities) && v.activities.every(definitionGuard) && Array.isArray(v.pendingAttempts) && v.pendingAttempts.every(attemptSummaryGuard) && (v.bestTyping === null || typingResultGuard(v.bestTyping));
const lessonDetailGuard: Guard<StudentLessonDetail> = (v): v is StudentLessonDetail => diaryLessonGuard(v) && Array.isArray((v as unknown as Record<string, unknown>).materials) && ((v as unknown as Record<string, unknown>).materials as unknown[]).every(m => record(m) && uuid(m.id) && text(m.name) && text(m.mime) && integer(m.sizeBytes) && instant(m.createdAt));
const configGuard: Guard<StudentActivityDefinition & { assignments: StudentAssignment[] }> = (v): v is StudentActivityDefinition & { assignments: StudentAssignment[] } => definitionGuard(v) && Array.isArray((v as unknown as Record<string, unknown>).assignments) && ((v as unknown as Record<string, unknown>).assignments as unknown[]).every(assignmentGuard);
const typingHistoryGuard: Guard<Page<TypingResult> & { best: TypingResult | null }> = (v): v is Page<TypingResult> & { best: TypingResult | null } => pageGuard(typingResultGuard)(v) && ((v as unknown as Record<string, unknown>).best === null || typingResultGuard((v as unknown as Record<string, unknown>).best));

async function get<T>(path: string, org: string, guard: Guard<T>, signal?: AbortSignal): Promise<T> {
  const payload = await coreAdminHttp.request<unknown>(path, {
    method: 'GET',
    headers: { 'X-Education-Organization': org },
    signal,
  });
  if (guard(payload)) return payload;
  throw new ApiResponseError(path);
}

async function write<T>(path: string, org: string, body: unknown, guard: Guard<T>): Promise<T> {
  const payload = await coreAdminHttp.request<unknown>(path, {
    method: 'PUT',
    headers: { 'X-Education-Organization': org },
    body: JSON.stringify(body),
  });
  if (guard(payload)) return payload;
  throw new ApiResponseError(path);
}

async function post<T>(path: string, org: string, body: unknown, guard: Guard<T>, signal?: AbortSignal): Promise<T> {
  const payload = await coreAdminHttp.request<unknown>(path, {
    method: 'POST',
    headers: { 'X-Education-Organization': org },
    body: JSON.stringify(body),
    signal,
  });
  if (guard(payload)) return payload;
  throw new ApiResponseError(path);
}

const pathId = (id: string): string => encodeURIComponent(id);

export const studentPortalApi = {
  home: (org: string, signal?: AbortSignal) => get('/education/student/home', org, homeGuard, signal),
  diary: (org: string, offset = 0, signal?: AbortSignal) => get(`/education/student/lessons?offset=${offset}`, org, pageGuard(diaryLessonGuard), signal),
  schedule: (org: string, from: string, to: string, signal?: AbortSignal) => get(`/education/student/schedule?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`, org, (v): v is { from: string; to: string; items: StudentDiaryLesson[] } => record(v) && text(v.from) && text(v.to) && Array.isArray(v.items) && v.items.every(diaryLessonGuard), signal),
  lesson: (org: string, id: string, signal?: AbortSignal) => get(`/education/student/lessons/${pathId(id)}`, org, lessonDetailGuard, signal),
  activities: (org: string, signal?: AbortSignal) => get('/education/student/activities', org, (v): v is { items: StudentActivityDefinition[] } => record(v) && Array.isArray(v.items) && v.items.every(definitionGuard), signal),
  activityConfig: (org: string, code: string, signal?: AbortSignal) => get(`/education/student/activities/${pathId(code)}`, org, configGuard, signal),
  startAttempt: (org: string, code: string, values: ActivityConfig & { durationSeconds: 15 | 30 | 60 }, signal?: AbortSignal) => post(`/education/student/activities/${pathId(code)}/attempts`, org, values, exerciseGuard, signal),
  finishAttempt: (org: string, attemptId: string, values: { typed: string; durationSeconds: number; corrections?: number; inputErrors?: number }) => write(`/education/student/attempts/${pathId(attemptId)}/finish`, org, values, typingResultGuard),
  attempts: (org: string, offset = 0, signal?: AbortSignal) => get(`/education/student/attempts?offset=${offset}`, org, pageGuard(attemptSummaryGuard), signal),
  typingHistory: (org: string, offset = 0, signal?: AbortSignal) => get(`/education/student/typing?offset=${offset}`, org, typingHistoryGuard, signal),
};
