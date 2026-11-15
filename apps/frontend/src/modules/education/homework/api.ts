import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { educationUpload, educationDownload } from '../fileTransfer';
import { ApiError, ApiResponseError } from '@/shared/api/errors';
import { invalidateEducationCache } from '../cache';
import type { Page, ResultStatus } from '../api';
import type { ActivityConfig } from '../activityTypes';
import type { TypingExercise } from '../student/api';

export const assignmentStates = ['DRAFT', 'PUBLISHED', 'CLOSED', 'ARCHIVED'] as const;
export const submissionStates = ['NOT_STARTED', 'IN_PROGRESS', 'SUBMITTED', 'RETURNED', 'RESUBMITTED', 'REVIEWED'] as const;
export type AssignmentState = typeof assignmentStates[number];
export type SubmissionState = typeof submissionStates[number];
export interface HomeworkFile { id: string; name: string; mime: string; sizeBytes: number; createdAt?: string }
export interface HomeworkInput {
  title: string; description: string; instructions: string; type: 'text' | 'files' | 'typing' | 'activity';
  groupId: string | null; studentIds: string[]; lessonId: string | null; dueAt: string | null; allowLate: boolean;
  activityCode: string | null; activityConfig: ActivityConfig;
  maxAttempts: number; attemptPolicy: 'first' | 'latest' | 'best';
}
export interface HomeworkAssignment extends HomeworkInput {
  id: string; organizationId: string; status: AssignmentState; version: number; createdAt: string;
  files?: HomeworkFile[]; overdue?: boolean; state?: SubmissionState; resultStatus?: ResultStatus | null;
  assigned?: number; submitted?: number; pendingReview?: number; returned?: number;
}
export interface HomeworkActivityResult { attemptId: string; type?: string; wpm?: number; score?: number; accuracy: number; errors: number; passed?: boolean }
export interface HomeworkVersion { id: string; sequence: number; assignmentVersion: number; text: string; submittedAt: string; late: boolean; activityAttemptId: string | null; files: HomeworkFile[]; activityResult?: HomeworkActivityResult | null }
export interface HomeworkReview { id: string; decision: 'accepted' | 'returned'; resultStatus: ResultStatus | null; comment: string; createdAt: string; teacherName?: string | null }
export interface HomeworkSubmission {
  id: string | null; recipientId: string; studentId: string; personId: string; state: SubmissionState; version: number;
  text: string; currentVersion: HomeworkVersion | null; versions: HomeworkVersion[]; reviews: HomeworkReview[];
  files: HomeworkFile[]; selectedActivityAttemptId: string | null; activityResult: HomeworkActivityResult | null;
}
export interface HomeworkRecipient { recipientId: string; studentId: string; personId: string; name: string; submissionId: string | null; state: SubmissionState; version: number; submittedAt: string | null; late: boolean; resultStatus: ResultStatus | null }
export interface HomeworkDetail { assignment: HomeworkAssignment; now: string; counts?: Record<string, number> }
export interface HomeworkAnswer extends HomeworkDetail { recipient: { id?: string; name?: string; studentId: string }; submission: HomeworkSubmission }
export interface HomeworkHistory { id?: string; version: number; action: string; createdAt: string }
export interface HomeworkProgress { assigned: number; completed: number; pendingReview: number; returned: number; overdue: number; notStarted?: number; inProgress?: number }
export type HomeworkPage<T> = Page<T> & { now: string };
export type HomeworkStudentRow = HomeworkAssignment | { assignment: HomeworkAssignment; submission: { state: SubmissionState }; overdue?: boolean };

type Guard<T> = (value: unknown) => value is T;
const record = (v: unknown): v is Record<string, unknown> => typeof v === 'object' && v !== null && !Array.isArray(v);
const string = (v: unknown): v is string => typeof v === 'string';
const integer = (v: unknown): v is number => typeof v === 'number' && Number.isSafeInteger(v) && v >= 0;
const uuid = (v: unknown): v is string => string(v) && /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(v);
const instant = (v: unknown): v is string => string(v) && v.includes('T') && Number.isFinite(Date.parse(v));
const fileGuard: Guard<HomeworkFile> = (v): v is HomeworkFile => record(v) && uuid(v.id) && string(v.name) && string(v.mime) && integer(v.sizeBytes);
export const homeworkAssignmentGuard: Guard<HomeworkAssignment> = (v): v is HomeworkAssignment => record(v) && uuid(v.id) && string(v.title) && string(v.description) && string(v.instructions) && ['text', 'files', 'typing', 'activity'].includes(String(v.type)) && assignmentStates.includes(v.status as AssignmentState) && integer(v.version) && (v.dueAt === null || instant(v.dueAt)) && typeof v.allowLate === 'boolean' && integer(v.maxAttempts) && ['first', 'latest', 'best'].includes(String(v.attemptPolicy)) && record(v.activityConfig) && (v.files === undefined || Array.isArray(v.files) && v.files.every(fileGuard));
const versionGuard: Guard<HomeworkVersion> = (v): v is HomeworkVersion => record(v) && uuid(v.id) && integer(v.sequence) && integer(v.assignmentVersion) && string(v.text) && instant(v.submittedAt) && typeof v.late === 'boolean' && Array.isArray(v.files) && v.files.every(fileGuard);
const reviewGuard: Guard<HomeworkReview> = (v): v is HomeworkReview => record(v) && uuid(v.id) && ['accepted', 'returned'].includes(String(v.decision)) && (v.comment === null || string(v.comment)) && instant(v.createdAt);
export const homeworkSubmissionGuard: Guard<HomeworkSubmission> = (v): v is HomeworkSubmission => record(v) && (v.id === null || uuid(v.id)) && submissionStates.includes(v.state as SubmissionState) && integer(v.version) && string(v.text) && (v.currentVersion === null || versionGuard(v.currentVersion)) && Array.isArray(v.versions) && v.versions.every(versionGuard) && Array.isArray(v.reviews) && v.reviews.every(reviewGuard) && Array.isArray(v.files) && v.files.every(fileGuard) && (v.activityResult === null || record(v.activityResult) && (typeof v.activityResult.wpm === 'number' || typeof v.activityResult.score === 'number') && typeof v.activityResult.accuracy === 'number');
const detailGuard: Guard<HomeworkDetail> = (v): v is HomeworkDetail => record(v) && homeworkAssignmentGuard(v.assignment) && instant(v.now);
const answerGuard: Guard<HomeworkAnswer> = (v): v is HomeworkAnswer => detailGuard(v) && record(v) && record(v.recipient) && homeworkSubmissionGuard(v.submission);
const recipientGuard: Guard<HomeworkRecipient> = (v): v is HomeworkRecipient => record(v) && uuid(v.recipientId) && uuid(v.studentId) && string(v.name) && submissionStates.includes(v.state as SubmissionState) && integer(v.version);
const historyGuard: Guard<HomeworkHistory> = (v): v is HomeworkHistory => record(v) && integer(v.version) && string(v.action) && instant(v.createdAt);
const progressGuard: Guard<HomeworkProgress> = (v): v is HomeworkProgress => record(v) && ['assigned', 'completed', 'pendingReview', 'returned', 'overdue'].every(k => integer(v[k]));
const pageGuard = <T>(item: Guard<T>): Guard<HomeworkPage<T>> => (v): v is HomeworkPage<T> => record(v) && Array.isArray(v.items) && v.items.length <= 50 && v.items.every(item) && integer(v.limit) && integer(v.offset) && (v.nextOffset === null || integer(v.nextOffset)) && instant(v.now);
const studentRowGuard: Guard<HomeworkStudentRow> = (v): v is HomeworkStudentRow => homeworkAssignmentGuard(v) || record(v) && homeworkAssignmentGuard(v.assignment) && record(v.submission) && submissionStates.includes(v.submission.state as SubmissionState);
const exerciseGuard: Guard<TypingExercise> = (v): v is TypingExercise => record(v) && record(v.attempt) && uuid(v.attempt.id) && record(v.exercise) && string(v.exercise.text) && [15, 30, 60].includes(Number(v.exercise.durationSeconds)) && ['ru', 'en'].includes(String(v.exercise.language));
const anyRecord: Guard<Record<string, unknown>> = record;
const pathId = (id: string): string => { if (!uuid(id)) throw new ApiError(404, 'http.not_found', 'Record not found.'); return id; };
export function homeworkQuery(values: Record<string, string | number | boolean | undefined>): string { const params = new URLSearchParams(); Object.entries(values).forEach(([key, value]) => { if (value !== undefined && value !== '') params.set(key, String(value)); }); return params.toString(); }
async function request<T>(org: string, path: string, guard: Guard<T>, method: 'GET' | 'POST' | 'PUT' | 'DELETE' = 'GET', body?: unknown, signal?: AbortSignal): Promise<T> {
  try {
    const value = await coreAdminHttp.request<unknown>(`/education${path}`, { method, body, signal, headers: { 'X-Education-Organization': org, 'Cache-Control': 'no-store' } });
    if (!guard(value)) throw new ApiResponseError(path);
    return value;
  } finally { if (method !== 'GET') invalidateEducationCache(['homework', 'student', 'students', 'groups', 'lessons', 'messages', 'header']); }
}
export const homeworkApi = {
  list: (org: string, filters: Record<string, string | number | boolean | undefined>, signal?: AbortSignal) => request(org, `/homework?${homeworkQuery(filters)}`, pageGuard(homeworkAssignmentGuard), 'GET', undefined, signal),
  detail: (org: string, id: string, signal?: AbortSignal) => request(org, `/homework/${pathId(id)}`, detailGuard, 'GET', undefined, signal),
  create: (org: string, body: HomeworkInput) => request(org, '/homework', detailGuard, 'POST', body),
  update: (org: string, id: string, body: Partial<HomeworkInput> & { version: number }) => request(org, `/homework/${pathId(id)}`, detailGuard, 'PUT', body),
  lifecycle: (org: string, id: string, action: 'publish' | 'close' | 'archive', version: number) => request(org, `/homework/${pathId(id)}/${action}`, detailGuard, 'POST', { version }),
  recipients: (org: string, id: string, version: number, studentIds: string[]) => request(org, `/homework/${pathId(id)}/recipients`, detailGuard, 'POST', { version, studentIds }),
  history: (org: string, id: string, offset = 0, signal?: AbortSignal) => request(org, `/homework/${pathId(id)}/history?offset=${offset}`, pageGuard(historyGuard), 'GET', undefined, signal),
  submissions: (org: string, id: string, offset = 0, signal?: AbortSignal) => request(org, `/homework/${pathId(id)}/submissions?offset=${offset}`, pageGuard(recipientGuard), 'GET', undefined, signal),
  answer: (org: string, id: string, signal?: AbortSignal) => request(org, `/homework/submissions/${pathId(id)}`, answerGuard, 'GET', undefined, signal),
  review: (org: string, id: string, body: { version: number; idempotencyKey: string; decision: 'accepted' | 'returned'; resultStatus: string | null; comment: string }) => request(org, `/homework/submissions/${pathId(id)}/review`, answerGuard, 'POST', body),
  progress: (org: string, filters: Record<string, string> = {}, student = false, signal?: AbortSignal) => request(org, `${student ? '/student' : ''}/homework/progress?${homeworkQuery(filters)}`, progressGuard, 'GET', undefined, signal),
  studentList: (org: string, filters: Record<string, string | number | boolean | undefined>, signal?: AbortSignal) => request(org, `/student/homework?${homeworkQuery(filters)}`, pageGuard(studentRowGuard), 'GET', undefined, signal),
  studentDetail: (org: string, id: string, signal?: AbortSignal) => request(org, `/student/homework/${pathId(id)}`, answerGuard, 'GET', undefined, signal),
  draft: (org: string, id: string, version: number, text: string) => request(org, `/student/homework/${pathId(id)}/draft`, answerGuard, 'PUT', { version, text }),
  submit: (org: string, id: string, version: number, idempotencyKey: string) => request(org, `/student/homework/${pathId(id)}/submit`, answerGuard, 'POST', { version, idempotencyKey }),
  upload: async (org: string, id: string, file: File, student = false) => {
    try { return await educationUpload(org, `/education${student ? '/student' : ''}/homework/${pathId(id)}/file-transfers`, file); }
    finally { invalidateEducationCache(['homework', 'student', 'students', 'groups', 'lessons', 'header']); }
  },
  removeFile: (org: string, id: string, fileId: string) => request(org, `/student/homework/${pathId(id)}/files/${pathId(fileId)}`, anyRecord, 'DELETE'),
  download: (org: string, id: string, signal?: AbortSignal) => educationDownload(org, `/education/homework/files/${pathId(id)}/transfer`, signal),
  startActivity: (org: string, id: string) => request(org, `/student/homework/${pathId(id)}/activity/start`, exerciseGuard, 'POST', {}),
  attachActivity: (org: string, id: string, attemptId: string) => request(org, `/student/homework/${pathId(id)}/activity/attach`, answerGuard, 'POST', { attemptId }),
};

export function studentAssignment(row: HomeworkStudentRow): HomeworkAssignment { return 'assignment' in row ? row.assignment : row; }
export function studentState(row: HomeworkStudentRow): SubmissionState { return 'assignment' in row ? row.submission.state : row.state ?? 'NOT_STARTED'; }
