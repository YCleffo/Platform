import { describe, expect, it } from 'vitest';
import type { Lesson } from './api';
import { lessonDisplayStatus, lessonStarted } from './status';

const lesson: Lesson = {
  id: '01900000-0000-7000-8000-000000000001',
  groupId: '01900000-0000-7000-8000-000000000002',
  groupName: 'Group',
  title: 'Lesson',
  startsAt: '2026-11-06T10:00:00Z',
  endsAt: '2026-11-06T11:00:00Z',
  location: '',
  status: 'scheduled',
  studentCount: 3,
};

describe('Education lesson status', () => {
  it('keeps stored lifecycle state instead of inventing completion', () => {
    expect(lessonDisplayStatus(lesson, '2026-11-06T09:00:00Z')).toBe('scheduled');
    expect(lessonDisplayStatus(lesson, '2026-11-06T10:30:00Z')).toBe('now');
    expect(lessonDisplayStatus(lesson, '2026-11-06T12:00:00Z')).toBe('scheduled');
    expect(lessonDisplayStatus({ ...lesson, status: 'completed' }, '2026-11-06T09:00:00Z')).toBe('completed');
    expect(lessonDisplayStatus({ ...lesson, status: 'cancelled' }, '2026-11-06T10:30:00Z')).toBe('cancelled');
  });
  it('exposes whether attendance may be recorded', () => {
    expect(lessonStarted(lesson, '2026-11-06T09:59:59Z')).toBe(false);
    expect(lessonStarted(lesson, '2026-11-06T10:00:00Z')).toBe(true);
  });
});
