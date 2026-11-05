import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiError, ApiResponseError } from '@/shared/api/errors';

export interface EducationContext { organizations: { id: string; name: string }[]; personId: string; displayName: string | null; locale: string; timezone: string }
export interface Header { displayName: string | null; avatarFileId: string | null; personId: string; date: string; now: string; greeting: 'night' | 'morning' | 'afternoon' | 'evening'; locale: string; timezone: string; unreadCount: number }
export interface Group { id: string; name: string; studentCount?: number }
export interface Student { id: string; personId: string; name?: string; createdAt?: string }
export interface PersonChoice { id: string; name: string; personId?: string }
export interface Lesson { id: string; groupId: string; groupName: string; title: string; startsAt: string; endsAt: string; location: string; status: 'scheduled' | 'completed' | 'cancelled'; studentCount: number }
export interface CalendarEvent { id: string; title: string; kind: 'meeting' | 'birthday' | 'other'; startsAt: string; endsAt: string; location: string }
export type TimelineItem = (Lesson & { kind: 'lesson' }) | CalendarEvent;
export interface Schedule { from: string; to: string; timezone: string; lessons: Lesson[]; events: CalendarEvent[] }
export interface Participant { id: string; personId: string; name: string; status: 'pending' | 'present' | 'absent' }
export interface Material { id: string; name: string; mime: string; sizeBytes: number; lessonId?: string | null; createdAt?: string }
export interface Attention { date: string; items: { id: string; title: string; absentCount: number }[] }
export interface Thread { id: string; subject: string; createdAt?: string; unreadCount?: number }
export interface Message { id: string; senderPersonId: string; senderName?: string | null; body: string | null; createdAt: string }
export interface Notification { id: string; threadId: string; subject: string; createdAt: string; readAt: string | null }
export interface Page<T> { items: T[]; limit: number; offset: number; nextOffset: number | null }

type Guard<T> = (value: unknown) => value is T;
const record = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value);
const text = (value: unknown): value is string => typeof value === 'string';
const uuid = (value: unknown): value is string => text(value) && /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(value);
const integer = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
const instant = (value: unknown): value is string => text(value) && Number.isFinite(Date.parse(value)) && value.includes('T');
const date = (value: unknown): value is string => text(value) && /^\d{4}-\d{2}-\d{2}$/.test(value) && Number.isFinite(Date.parse(`${value}T12:00:00Z`)) && new Date(`${value}T12:00:00Z`).toISOString().slice(0, 10) === value;
const timezone = (value: unknown): value is string => { if (!text(value) || !value) return false; try { new Intl.DateTimeFormat('en', { timeZone: value }); return true; } catch { return false; } };
const locale = (value: unknown): value is string => { if (!text(value) || !value) return false; try { return Intl.getCanonicalLocales(value).length === 1; } catch { return false; } };
const nullableText = (value: unknown): value is string | null => value === null || text(value);
const nullableUuid = (value: unknown): value is string | null => value === null || uuid(value);
const named = (value: unknown): value is PersonChoice => record(value) && uuid(value.id) && text(value.name);
const contextGuard: Guard<EducationContext> = (v): v is EducationContext => record(v) && Array.isArray(v.organizations) && v.organizations.every(named) && uuid(v.personId) && nullableText(v.displayName) && locale(v.locale) && timezone(v.timezone);
const headerGuard: Guard<Header> = (v): v is Header => record(v) && nullableText(v.displayName) && nullableUuid(v.avatarFileId) && uuid(v.personId) && date(v.date) && instant(v.now) && ['night', 'morning', 'afternoon', 'evening'].includes(String(v.greeting)) && locale(v.locale) && timezone(v.timezone) && integer(v.unreadCount);
const groupGuard: Guard<Group> = (v): v is Group => named(v) && (!('studentCount' in v) || integer(v.studentCount));
const studentGuard: Guard<Student> = (v): v is Student => record(v) && uuid(v.id) && uuid(v.personId) && (v.name === undefined || text(v.name));
const lessonGuard: Guard<Lesson> = (v): v is Lesson => record(v) && uuid(v.id) && uuid(v.groupId) && text(v.groupName) && text(v.title) && instant(v.startsAt) && instant(v.endsAt) && text(v.location) && ['scheduled', 'completed', 'cancelled'].includes(String(v.status)) && integer(v.studentCount);
const eventGuard: Guard<CalendarEvent> = (v): v is CalendarEvent => record(v) && uuid(v.id) && text(v.title) && ['meeting', 'birthday', 'other'].includes(String(v.kind)) && instant(v.startsAt) && instant(v.endsAt) && text(v.location);
const timelineGuard: Guard<TimelineItem> = (v): v is TimelineItem => (record(v) && v.kind === 'lesson' && lessonGuard(v)) || eventGuard(v);
const scheduleGuard: Guard<Schedule> = (v): v is Schedule => record(v) && date(v.from) && date(v.to) && timezone(v.timezone) && Array.isArray(v.lessons) && v.lessons.every(lessonGuard) && Array.isArray(v.events) && v.events.every(eventGuard);
const materialGuard: Guard<Material> = (v): v is Material => record(v) && uuid(v.id) && text(v.name) && ['application/pdf', 'text/plain', 'image/png', 'image/jpeg', 'image/webp'].includes(String(v.mime)) && integer(v.sizeBytes) && v.sizeBytes > 0 && v.sizeBytes <= 10485760;
const participantGuard: Guard<Participant> = (v): v is Participant => named(v) && record(v) && uuid(v.personId) && ['pending', 'present', 'absent'].includes(String(v.status));
const threadGuard: Guard<Thread> = (v): v is Thread => record(v) && uuid(v.id) && text(v.subject) && (v.unreadCount === undefined || integer(v.unreadCount));
const messageGuard: Guard<Message> = (v): v is Message => record(v) && uuid(v.id) && uuid(v.senderPersonId) && nullableText(v.body) && instant(v.createdAt) && (v.senderName === undefined || nullableText(v.senderName));
const notificationGuard: Guard<Notification> = (v): v is Notification => record(v) && uuid(v.id) && uuid(v.threadId) && text(v.subject) && instant(v.createdAt) && (v.readAt === null || instant(v.readAt));
function pageGuard<T>(guard: Guard<T>): Guard<Page<T>> {
  return (v): v is Page<T> => record(v) && Array.isArray(v.items) && v.items.length <= 50 && v.items.every(guard) && v.limit === 50 && integer(v.offset) && (v.nextOffset === null || (integer(v.nextOffset) && v.nextOffset === v.offset + 50));
}
function checked<T>(value: unknown, guard: Guard<T>, path: string): T {
  if (!guard(value)) throw new ApiResponseError(path);
  return value;
}

async function get<T>(path: string, organization: string | null, guard: Guard<T>, signal?: AbortSignal): Promise<T> {
  return checked(await coreAdminHttp.request<unknown>(`/education${path}`, { signal, headers: organization ? { 'X-Education-Organization': organization } : {} }), guard, path);
}
async function write<T>(path: string, organization: string, body: unknown, guard: Guard<T>, method: 'POST' | 'PUT' = 'POST'): Promise<T> {
  return checked(await coreAdminHttp.request<unknown>(`/education${path}`, { method, body, headers: { 'X-Education-Organization': organization } }), guard, path);
}
const updated: Guard<{ updated: true }> = (v): v is { updated: true } => record(v) && v.updated === true;
function pathId(id: string): string { if (!uuid(id)) throw new ApiError(404, 'http.not_found', 'Record not found.'); return id; }
const query = (path: string, offset: number) => `${path}${path.includes('?') ? '&' : '?'}offset=${offset}`;

export const educationApi = {
  context: (signal?: AbortSignal) => get('/context', null, contextGuard, signal),
  header: (org: string, signal?: AbortSignal) => get('/today/header', org, headerGuard, signal),
  schedule: (org: string, from: string, to: string, signal?: AbortSignal) => get(`/schedule?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`, org, (v): v is Schedule => scheduleGuard(v) && v.from === from && v.to === to, signal),
  timeline: (org: string, selected: string, signal?: AbortSignal) => get(`/timeline?date=${encodeURIComponent(selected)}`, org, (v): v is { date: string; timezone: string; items: TimelineItem[] } => record(v) && v.date === selected && date(v.date) && timezone(v.timezone) && Array.isArray(v.items) && v.items.every(timelineGuard), signal),
  next: (org: string, signal?: AbortSignal) => get('/lessons/next', org, (v): v is Lesson | null => v === null || lessonGuard(v), signal),
  current: (org: string, signal?: AbortSignal) => get('/lessons/current', org, (v): v is Lesson | null => v === null || lessonGuard(v), signal),
  attention: (org: string, signal?: AbortSignal) => get('/attention', org, (v): v is Attention => record(v) && date(v.date) && Array.isArray(v.items) && v.items.every(item => record(item) && uuid(item.id) && text(item.title) && integer(item.absentCount) && item.absentCount > 0), signal),
  groups: (org: string, offset = 0, signal?: AbortSignal) => get(query('/groups', offset), org, pageGuard(groupGuard), signal),
  students: (org: string, offset = 0, signal?: AbortSignal) => get(query('/students', offset), org, pageGuard(studentGuard), signal),
  candidates: (org: string, offset = 0, signal?: AbortSignal) => get(query('/people/candidates', offset), org, pageGuard(named), signal),
  colleagues: (org: string, offset = 0, signal?: AbortSignal) => get(query('/colleagues', offset), org, pageGuard(named), signal),
  files: (org: string, offset = 0, lessonId?: string, signal?: AbortSignal) => get(query(lessonId ? `/lessons/${pathId(lessonId)}/materials` : '/files', offset), org, pageGuard(materialGuard), signal),
  lesson: (org: string, id: string, signal?: AbortSignal) => get(`/lessons/${pathId(id)}`, org, lessonGuard, signal),
  participants: (org: string, id: string, signal?: AbortSignal) => get(`/lessons/${pathId(id)}/participants`, org, (v): v is { items: Participant[] } => record(v) && Array.isArray(v.items) && v.items.every(participantGuard), signal),
  threads: (org: string, offset = 0, signal?: AbortSignal) => get(query('/threads', offset), org, pageGuard(threadGuard), signal),
  thread: (org: string, id: string, signal?: AbortSignal) => get(`/threads/${pathId(id)}`, org, threadGuard, signal),
  messages: (org: string, id: string, offset = 0, signal?: AbortSignal) => get(query(`/threads/${pathId(id)}/messages`, offset), org, pageGuard(messageGuard), signal),
  notifications: (org: string, offset = 0, signal?: AbortSignal) => get(query('/notifications', offset), org, pageGuard(notificationGuard), signal),
  createGroup: (org: string, name: string) => write('/groups', org, { name }, groupGuard),
  enroll: (org: string, personId: string, groupId: string) => write('/students', org, { personId, groupId }, studentGuard),
  createLesson: (org: string, values: { title: string; groupId: string; startsAt: string; endsAt: string; location: string }) => write('/lessons', org, values, lessonGuard),
  createEvent: (org: string, values: { title: string; kind: string; startsAt: string; endsAt: string; location: string }) => write('/events', org, values, eventGuard),
  attendance: (org: string, lessonId: string, studentId: string, status: string) => write(`/lessons/${pathId(lessonId)}/participants/${pathId(studentId)}`, org, { status }, updated, 'PUT'),
  saveProfile: (org: string, displayName: string | null, avatarFileId: string | null) => write('/profile', org, { displayName, avatarFileId }, headerGuard, 'PUT'),
  startThread: (org: string, recipientPersonId: string, subject: string, body: string) => write('/threads', org, { recipientPersonId, subject, body }, threadGuard),
  sendMessage: (org: string, id: string, body: string) => write(`/threads/${pathId(id)}/messages`, org, { body }, messageGuard),
  readThread: (org: string, id: string, lastSeenMessageId: string) => write(`/threads/${pathId(id)}/read`, org, { lastSeenMessageId }, updated),
  readNotification: (org: string, id: string) => write(`/notifications/${pathId(id)}/read`, org, {}, updated),
  upload: async (org: string, file: File, lessonId?: string, avatar = false) => {
    const form = new FormData(); form.set('file', file); form.set('purpose', avatar ? 'avatar' : 'material');
    if (lessonId) form.set('lessonId', lessonId);
    return write('/files', org, form, materialGuard);
  },
  download: (org: string, id: string, signal?: AbortSignal) => coreAdminHttp.binary(`/education/files/${pathId(id)}`, { 'X-Education-Organization': org }, signal),
};
