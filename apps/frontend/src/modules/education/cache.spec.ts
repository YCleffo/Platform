import { beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { clearEducationCache, educationMeasurements, educationRead, educationTags, invalidateEducationCache, scopeEducationCache } from './cache';
import { SupersededOperationError } from '@/shared/api/errors';
const tokens = { accessToken: 'test-only', refreshToken: 'test-only', accessExpiresAt: '', sessionExpiresAt: '', sessionId: 'session' };
function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>(accept => { resolve = accept; }); return { promise, resolve }; }
beforeEach(() => { coreAdminSession.set(tokens); clearEducationCache(); scopeEducationCache('org'); });
describe('Education private request cache', () => {
 it('refreshes related material, unread-header and own-progress projections after mutations', async () => {
  for (const [readPath, writePath] of [['/lessons/id/materials', '/files'], ['/today/header', '/threads/id/read'], ['/student/curriculum-progress', '/lessons/id/results/student']] as const) {
   await educationRead('org', readPath, async () => 'before');
   invalidateEducationCache(educationTags(writePath));
   expect(await educationRead('org', readPath, async () => 'after')).toBe('after');
  }
 });
 it('shares concurrent reads and serves warm data without another HTTP request', async () => {
  const pending = deferred<string>(); const load = vi.fn(() => pending.promise);
  const first = educationRead('org', '/groups', load); const second = educationRead('org', '/groups', load); pending.resolve('groups');
  expect(await first).toBe('groups'); expect(await second).toBe('groups'); expect(await educationRead('org', '/groups', load)).toBe('groups'); expect(load).toHaveBeenCalledTimes(1);
  expect(educationMeasurements.snapshot().some(item => item.cached)).toBe(true);
 });
 it('rejects a transport that resolves after logout and cannot repopulate the cache', async () => {
  const pending = deferred<string>(); const first = educationRead('org', '/students', () => pending.promise); coreAdminSession.clear(); pending.resolve('private');
  await expect(first).rejects.toBeInstanceOf(SupersededOperationError); coreAdminSession.set(tokens); scopeEducationCache('org');
  const load = vi.fn(async () => 'fresh'); expect(await educationRead('org', '/students', load)).toBe('fresh'); expect(load).toHaveBeenCalledTimes(1);
 });
 it('makes the previous organization inaccessible and rejects its late chained reads', async () => {
  await educationRead('org', '/students', async () => 'first private'); scopeEducationCache('second');
  await expect(educationRead('org', '/students', async () => 'late private')).rejects.toBeInstanceOf(SupersededOperationError);
  expect(await educationRead('second', '/students', async () => 'second private')).toBe('second private');
 });
 it('invalidates facts affected by writes and refuses pre-write results', async () => {
  await educationRead('org', '/groups', async () => 'old'); const pending = deferred<string>(); const stale = educationRead('org', '/lessons/next', () => pending.promise);
  invalidateEducationCache(['groups', 'lessons']); pending.resolve('old lesson'); await expect(stale).rejects.toBeInstanceOf(SupersededOperationError);
  const load = vi.fn(async () => 'updated'); expect(await educationRead('org', '/groups', load)).toBe('updated'); expect(load).toHaveBeenCalledTimes(1);
 });
 it('aborting one subscriber does not cancel another subscriber of a deduplicated request', async () => {
  const pending = deferred<string>(); const controller = new AbortController(); const first = educationRead('org', '/groups', () => pending.promise, controller.signal); const second = educationRead('org', '/groups', () => pending.promise);
  controller.abort(); pending.resolve('shared'); await expect(first).rejects.toBeInstanceOf(SupersededOperationError); expect(await second).toBe('shared');
 });
});
