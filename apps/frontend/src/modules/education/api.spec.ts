import { beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiResponseError } from '@/shared/api/errors';
import { educationApi } from './api';

vi.mock('@/shared/api/core/adminHttp', () => ({ coreAdminHttp: { request: vi.fn(), binary: vi.fn() } }));
const id = '01900000-0000-7000-8000-000000000001';
const header = { displayName: 'Teacher', avatarFileId: null, personId: id, date: '2026-11-06', now: '2026-11-06T12:00:00Z', greeting: 'afternoon', locale: 'ru-RU', timezone: 'Asia/Yekaterinburg', unreadCount: 0 };

describe('Education response boundary', () => {
  beforeEach(() => vi.mocked(coreAdminHttp.request).mockReset());
  it('accepts genuine empty data and carries the explicit organization scope', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue(null);
    await expect(educationApi.next(id)).resolves.toBeNull();
    expect(coreAdminHttp.request).toHaveBeenCalledWith('/education/lessons/next', expect.objectContaining({ headers: { 'X-Education-Organization': id } }));
  });
  it('rejects a different date or range instead of showing it as the selected day', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ date: '2026-11-05', timezone: 'UTC', items: [] });
    await expect(educationApi.timeline(id, '2026-11-06')).rejects.toBeInstanceOf(ApiResponseError);
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ from: '2026-11-01', to: '2026-11-07', timezone: 'UTC', lessons: [], events: [] });
    await expect(educationApi.schedule(id, '2026-11-02', '2026-11-08')).rejects.toBeInstanceOf(ApiResponseError);
  });
  it.each([{ date: '2026-02-30' }, { timezone: 'not/a-zone' }, { locale: 'invalid locale' }, { unreadCount: -1 }])('rejects malformed header fields %j', async patch => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ ...header, ...patch });
    await expect(educationApi.header(id)).rejects.toBeInstanceOf(ApiResponseError);
  });
  it('rejects path injection before issuing a request', () => {
    expect(() => educationApi.lesson(id, '../../auth/sessions')).toThrow();
    expect(() => educationApi.updateGroup(id, '../../auth/sessions', 'Name')).toThrow();
    expect(() => educationApi.deleteFile(id, '../../auth/sessions')).toThrow();
    expect(() => educationApi.completeLesson(id, '../../auth/sessions')).toThrow();
    expect(coreAdminHttp.request).not.toHaveBeenCalled();
  });
  it('accepts lifecycle fields and rejects malformed archive timestamps', async () => {
    const group = { id, name: 'Group', studentCount: 1, archivedAt: null };
    vi.mocked(coreAdminHttp.request).mockResolvedValue(group);
    await expect(educationApi.group(id, id)).resolves.toEqual(group);
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ ...group, archivedAt: 'not-an-instant' });
    await expect(educationApi.group(id, id)).rejects.toBeInstanceOf(ApiResponseError);
    const lesson = { id, groupId: id, groupName: 'Group', title: 'Lesson', startsAt: header.now, endsAt: header.now, location: '', status: 'completed', studentCount: 1, completedAt: header.now, cancelledAt: null };
    vi.mocked(coreAdminHttp.request).mockResolvedValue(lesson);
    await expect(educationApi.lesson(id, id)).resolves.toEqual(lesson);
  });
  it('uses real methods for lifecycle operations', async () => {
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ updated: true });
    await educationApi.removeGroupStudent(id, id, id);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/education/groups/${id}/students/${id}`, expect.objectContaining({ method: 'DELETE' }));
    await educationApi.transferStudent(id, id, id, id);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/education/groups/${id}/students/${id}/transfer`, expect.objectContaining({ method: 'POST', body: { groupId: id } }));
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ id, ...{ groupId: id, groupName: 'Group', title: 'Lesson', startsAt: header.now, endsAt: header.now, location: '', status: 'completed', studentCount: 1 } });
    await educationApi.completeLesson(id, id);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/education/lessons/${id}/complete`, expect.objectContaining({ method: 'POST' }));
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ id, deleted: true });
    await educationApi.deleteFile(id, id);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/education/files/${id}`, expect.objectContaining({ method: 'DELETE' }));
  });
  it('reads and saves lesson outcomes with real result statuses', async () => {
    const report = { id, lessonId: id, topic: 'Vectors', summary: 'Scalar product', teacherComment: null, updatedAt: header.now };
    const entry = { id, personId: id, name: 'Student', status: 'mastered', comment: 'Fine', updatedAt: null };
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ report, items: [entry] });
    const results = await educationApi.lessonResults(id, id);
    expect(results.report).toEqual(report);
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/education/lessons/${id}/results`, expect.objectContaining({ headers: { 'X-Education-Organization': id } }));
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ report, items: [{ ...entry, status: 'five' }] });
    await expect(educationApi.lessonResults(id, id)).rejects.toBeInstanceOf(ApiResponseError);
    vi.mocked(coreAdminHttp.request).mockResolvedValue(report);
    await educationApi.saveLessonReport(id, id, { topic: 'Topic', summary: 'Summary', teacherComment: 'Next' });
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/education/lessons/${id}/report`, expect.objectContaining({ method: 'PUT', body: { topic: 'Topic', summary: 'Summary', teacherComment: 'Next' } }));
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ id, lessonId: id, studentId: id, status: 'developing', comment: null, updatedAt: header.now });
    await educationApi.saveLessonResult(id, id, id, { status: 'developing', comment: null });
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/education/lessons/${id}/results/${id}`, expect.objectContaining({ method: 'PUT', body: { status: 'developing', comment: null } }));
  });
  it('builds journal and student card queries and validates responses', async () => {
    const entry = { id, groupId: id, groupName: 'Group', title: 'Lesson', startsAt: header.now, endsAt: header.now, location: '', status: 'completed', studentCount: 1, report: null, results: [] };
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ items: [entry], limit: 50, offset: 0, nextOffset: null });
    await educationApi.journal(id, id, { from: '2026-11-01', to: '2026-11-07', studentId: id, offset: 50 });
    expect(coreAdminHttp.request).toHaveBeenLastCalledWith(`/education/groups/${id}/journal?from=2026-11-01&to=2026-11-07&studentId=${id}&offset=50`, expect.objectContaining({ headers: { 'X-Education-Organization': id } }));
    const card = { id, personId: id, name: 'Student', createdAt: header.now, groups: [{ id, name: 'Group', archivedAt: null }] };
    vi.mocked(coreAdminHttp.request).mockResolvedValue(card);
    await expect(educationApi.student(id, id)).resolves.toEqual(card);
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ items: [{ lessonId: id, title: 'Lesson', startsAt: header.now, status: 'completed', attendance: 'present' }], limit: 50, offset: 0, nextOffset: null, from: '2026-11-01', to: '2026-11-07' });
    await expect(educationApi.studentAttendance(id, id, '2026-11-01', '2026-11-07')).resolves.toMatchObject({ from: '2026-11-01' });
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ from: '2026-11-01', to: '2026-11-07', attendance: { present: 2, absent: 1 }, results: { mastered: 1, developing: 0, needs_support: 1 } });
    await expect(educationApi.studentProgress(id, id, '2026-11-01', '2026-11-07')).resolves.toMatchObject({ results: { mastered: 1 } });
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ from: '2026-11-02', to: '2026-11-07', attendance: { present: 0, absent: 0 }, results: { mastered: 0, developing: 0, needs_support: 0 } });
    await expect(educationApi.studentProgress(id, id, '2026-11-01', '2026-11-07')).rejects.toBeInstanceOf(ApiResponseError);
    vi.mocked(coreAdminHttp.request).mockResolvedValue({ items: [{ id, lessonId: id, lessonTitle: 'Lesson', startsAt: header.now, status: 'mastered', comment: 'Nice', updatedAt: header.now }], limit: 50, offset: 0, nextOffset: null });
    await expect(educationApi.studentResults(id, id)).resolves.toMatchObject({ items: [{ status: 'mastered' }] });
  });
});
