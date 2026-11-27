import { afterEach, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { activityApi as canonicalActivityApi } from '@/modules/activity/activityApi';
import { parentApi } from './parent/api';
import { studentPortalApi } from './student/api';

const org = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
const student = '11111111-2222-3333-4444-555555555555';
const person = '11111111-2222-3333-4444-555555555556';
const attempt = '11111111-2222-3333-4444-555555555557';
const assignment = '11111111-2222-3333-4444-555555555558';
const logicActivity = '11111111-2222-3333-4444-555555555559';
const at = '2026-11-14T12:00:00Z';
const settings = { language: 'en', durationSeconds: 15, assignmentId: assignment } as const;
const typedState = { typed: 'abc', corrections: 0, inputErrors: 0 };
const exercise = {
  attempt: { id: attempt, status: 'running', startedAt: at, stateVersion: 0, state: {} },
  exercise: { text: 'abc', durationSeconds: 15, language: 'en' },
};
const success = (data: unknown) => Response.json({ status: 'success', data });

afterEach(() => {
  vi.unstubAllGlobals();
  coreAdminSession.clear();
});

it('sends guardian and student mutations as JSON objects through the real Core transport', async () => {
  const fetch = vi.fn<typeof globalThis.fetch>()
    .mockResolvedValueOnce(success({ updated: true }))
    .mockResolvedValueOnce(success(exercise))
    .mockResolvedValueOnce(success({ ...exercise, attempt: { ...exercise.attempt, stateVersion: 1, state: typedState } }))
    .mockResolvedValueOnce(success({ attemptId: attempt, durationSeconds: 15, language: 'en', keystrokes: 3, correctChars: 3, errors: 0, wpm: 2.4, accuracy: 100 }))
    .mockResolvedValueOnce(success({ id: logicActivity, pluginCode: 'platform-logic', title: 'Logic & Patterns Lab' }))
    .mockResolvedValueOnce(success({ id: attempt, activityId: logicActivity, pluginCode: 'platform-logic', state: 'IN_PROGRESS', revision: 1 }));
  vi.stubGlobal('fetch', fetch);
  coreAdminSession.set({ accessToken: 'token', refreshToken: 'refresh', accessExpiresAt: '', sessionExpiresAt: '', sessionId: person });

  await parentApi.addGuardian(org, student, person, 'guardian');
  await studentPortalApi.startAttempt(org, 'platform-typing', settings);
  await studentPortalApi.saveTypingState(org, attempt, 0, typedState);
  await studentPortalApi.finishAttempt(org, attempt, { ...typedState, durationSeconds: 15 });
  const boot = await canonicalActivityApi.bootstrapPlugin(org, 'platform-logic');
  await canonicalActivityApi.startAttempt(org, boot.id, { assignmentId: assignment });

  expect(fetch.mock.calls.map(([url, options]) => ({
    path: new URL(String(url)).pathname,
    method: options?.method,
    body: JSON.parse(String(options?.body)),
    organization: new Headers(options?.headers).get('X-Education-Organization'),
  }))).toEqual([
    { path: `/api/education/students/${student}/guardians`, method: 'POST', organization: org, body: { personId: person, relationship: 'guardian' } },
    { path: '/api/education/student/activities/platform-typing/attempts', method: 'POST', organization: org, body: settings },
    { path: `/api/education/student/attempts/${attempt}/state`, method: 'PUT', organization: org, body: { version: 0, state: typedState } },
    { path: `/api/education/student/attempts/${attempt}/finish`, method: 'PUT', organization: org, body: { ...typedState, durationSeconds: 15 } },
    { path: '/api/activity/plugins/platform-logic/bootstrap', method: 'POST', organization: org, body: {} },
    { path: `/api/activity/items/${logicActivity}/attempts`, method: 'POST', organization: org, body: { assignmentId: assignment } },
  ]);
});
