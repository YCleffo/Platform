import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { coreAdminSession } from '@/core/admin/session';
import { ApiResponseError } from '@/shared/api/errors';
import { clearEducationCache, educationRead, scopeEducationCache } from '../cache';
import { homeworkApi, type HomeworkInput } from './api';

vi.mock('@/shared/api/core/adminHttp', () => ({ coreAdminHttp: { request: vi.fn(), binary: vi.fn() } }));
const id = '01900000-0000-7000-8000-000000000001', org = '01900000-0000-7000-8000-000000000002';
const now = '2026-11-13T12:00:00Z';
const input: HomeworkInput = { title: 'Explain loops', description: 'Description', instructions: 'Use an example.', type: 'text', groupId: id, studentIds: [], lessonId: null, dueAt: now, allowLate: false, activityCode: null, activityConfig: { durationSeconds: 30, language: 'en' }, maxAttempts: 3, attemptPolicy: 'latest' };
const assignment = { ...input, id, organizationId: org, status: 'PUBLISHED', version: 2, createdAt: now };
const answer = { assignment, now, recipient: { studentId: id }, submission: { id, recipientId: id, studentId: id, personId: id, state: 'RETURNED', version: 4, text: 'Draft', currentVersion: null, versions: [], reviews: [], files: [], selectedActivityAttemptId: null, activityResult: null } };
beforeEach(() => {
  vi.mocked(coreAdminHttp.request).mockReset(); clearEducationCache();
  coreAdminSession.set({ accessToken: 'qa', refreshToken: 'qa', accessExpiresAt: '', sessionExpiresAt: '', sessionId: 'qa' });
  scopeEducationCache(org);
});
afterEach(() => coreAdminSession.clear());

describe('Homework HTTP contract', () => {
  it('creates and publishes with the organization and optimistic version', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ assignment, now });
    await homeworkApi.create(org, input);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith('/education/homework', expect.objectContaining({ method: 'POST', body: input, headers: { 'X-Education-Organization': org, 'Cache-Control': 'no-store' } }));
    await homeworkApi.lifecycle(org, id, 'publish', 1);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/education/homework/${id}/publish`, expect.objectContaining({ method: 'POST', body: { version: 1 } }));
  });

  it('sends only answer text/version and submits with the same client idempotency key', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue(answer);
    await homeworkApi.draft(org, id, 4, 'Revised answer');
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/education/student/homework/${id}/draft`, expect.objectContaining({ method: 'PUT', body: { version: 4, text: 'Revised answer' } }));
    await homeworkApi.submit(org, id, 5, id);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/education/student/homework/${id}/submit`, expect.objectContaining({ method: 'POST', body: { version: 5, idempotencyKey: id } }));
  });

  it('invalidates journal, progress and notifications after returning work for revision', async () => {
    const paths = [`/groups/${id}/journal`, `/students/${id}/progress`, '/student/homework/progress', '/notifications'];
    for (const path of paths) await educationRead(org, path, async () => 'old facts');
    vi.mocked(coreAdminHttp.request).mockResolvedValue(answer);
    await homeworkApi.review(org, id, { version: 3, idempotencyKey: id, decision: 'returned', resultStatus: null, comment: 'Add an example.' });
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/education/homework/submissions/${id}/review`, expect.objectContaining({ method: 'POST', body: { version: 3, idempotencyKey: id, decision: 'returned', resultStatus: null, comment: 'Add an example.' } }));
    for (const path of paths) expect(await educationRead(org, path, async () => 'refreshed facts')).toBe('refreshed facts');
  });

  it('rejects malformed immutable versions instead of displaying a successful submission', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ ...answer, submission: { ...answer.submission, currentVersion: { id, sequence: 1, assignmentVersion: 1, text: 'Answer', submittedAt: 'invalid', late: false, files: [] } } });
    await expect(homeworkApi.submit(org, id, 4, id)).rejects.toBeInstanceOf(ApiResponseError);
  });

  it('rejects unsafe IDs before issuing student, review or attachment requests', () => {
    expect(() => homeworkApi.studentDetail(org, '../../auth/sessions')).toThrow();
    expect(() => homeworkApi.review(org, '../private', { version: 1, idempotencyKey: id, decision: 'accepted', resultStatus: 'mastered', comment: '' })).toThrow();
    expect(() => homeworkApi.download(org, '../storage')).toThrow();
    expect(coreAdminHttp.request).not.toHaveBeenCalled();
    expect(coreAdminHttp.binary).not.toHaveBeenCalled();
  });
});
