/// <reference types="node" />
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { coreAdminSession } from '@/core/admin/session';
import { educationApi } from '../api';
import { homeworkApi } from './api';

vi.mock('@/shared/api/core/adminHttp', () => ({ coreAdminHttp: { request: vi.fn(), binary: vi.fn() } }));
const path = resolve(process.cwd(), '../../runtime/education04/api-contract-evidence.json');
const live = existsSync(path);
// Generated only by the guarded localhost HTTP script. No credentials are captured.
describe.skipIf(!live)('Homework Vue guards against real isolated HTTP responses', () => {
  const payload = live ? JSON.parse(readFileSync(path, 'utf8')) as Record<string, unknown> : {};
  const org = '01900000-0000-7000-8000-000000000001';
  afterEach(() => coreAdminSession.clear());
  it('accepts publication, immutable answers and Typing evidence from the integrated backend', async () => {
    coreAdminSession.set({ accessToken: 'contract', refreshToken: 'contract', accessExpiresAt: '', sessionExpiresAt: '', sessionId: 'contract' });
    for (const key of ['created', 'published']) {
      vi.mocked(coreAdminHttp.request).mockResolvedValue(payload[key]);
      await expect(homeworkApi.detail(org, org)).resolves.toEqual(payload[key]);
    }
    for (const key of ['notStarted', 'submitted', 'reviewed', 'typing']) {
      vi.mocked(coreAdminHttp.request).mockResolvedValue(payload[key]);
      await expect(homeworkApi.studentDetail(org, org)).resolves.toEqual(payload[key]);
    }
  });
  it('accepts lists, progress, notifications and the Core-backed parent card', async () => {
    coreAdminSession.set({ accessToken: 'contract', refreshToken: 'contract', accessExpiresAt: '', sessionExpiresAt: '', sessionId: 'contract' });
    for (const [key, call] of [
      ['teacherList', () => homeworkApi.list(org, {})], ['studentList', () => homeworkApi.studentList(org, {})],
      ['studentProgress', () => homeworkApi.progress(org, {}, true)], ['teacherProgress', () => homeworkApi.progress(org)],
      ['history', () => homeworkApi.history(org, org)], ['recipients', () => homeworkApi.submissions(org, org)],
      ['teacherNotifications', () => educationApi.notifications(org)], ['studentCard', () => educationApi.student(org, org)],
    ] as const) {
      vi.mocked(coreAdminHttp.request).mockResolvedValue(payload[key]);
      await expect(call()).resolves.toEqual(payload[key]);
    }
  });
});
