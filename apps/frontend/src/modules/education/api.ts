import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { educationUpload, educationDownload } from './fileTransfer';
import { ApiError, ApiResponseError } from '@/shared/api/errors';
import { educationRead, educationTags, invalidateEducationCache, educationMeasurements } from './cache';

export interface EducationContext { organizations: { id: string; name: string; teacher?: boolean; student?: boolean; parent?: boolean }[]; personId: string; displayName: string | null; locale: string; timezone: string }
export interface Header { displayName: string | null; avatarFileId: string | null; personId: string; date: string; now: string; greeting: 'night' | 'morning' | 'afternoon' | 'evening'; locale: string; timezone: string; unreadCount: number }
export interface Group { id: string; name: string; studentCount?: number; archivedAt?: string | null }
export interface Student { id: string; personId: string; name?: string; createdAt?: string }
export interface PersonChoice { id: string; name: string; personId?: string }
export interface Lesson { curriculumLessonId?: string | null; curriculumSnapshot?: Record<string, unknown> | null; id: string; groupId: string; groupName: string; title: string; startsAt: string; endsAt: string; location: string; status: 'scheduled' | 'completed' | 'cancelled'; studentCount: number; completedAt?: string | null; cancelledAt?: string | null }
export interface CalendarEvent { id: string; title: string; kind: 'meeting' | 'birthday' | 'other'; startsAt: string; endsAt: string; location: string }
export type TimelineItem = (Lesson & { kind: 'lesson' }) | CalendarEvent;
export interface Schedule { from: string; to: string; timezone: string; lessons: Lesson[]; events: CalendarEvent[] }
export interface Participant { id: string; personId: string; name: string; status: 'pending' | 'present' | 'absent' }
export type ResultStatus = 'pending' | 'mastered' | 'developing' | 'needs_support';
export interface LessonReport { id: string; lessonId: string; topic: string; summary: string; teacherComment: string | null; updatedAt: string }
export interface LessonResultEntry { id: string; personId: string; name: string; status: ResultStatus; comment: string | null; updatedAt?: string | null }
export interface LessonResults { report: LessonReport | null; items: LessonResultEntry[] }
export interface JournalStudentResult { studentId: string; name: string; status: ResultStatus; comment: string | null }
export interface JournalEntry extends Lesson { report: LessonReport | null; results: JournalStudentResult[] }
export interface StudentGroup { id: string; name: string; archivedAt: string | null }
export interface StudentGuardian { personId: string; name: string; relationship: 'mother' | 'father' | 'guardian'; contacts: { type: string; value: string; label: string | null }[] }
export interface StudentCard { id: string; personId: string; name: string; createdAt: string; groups: StudentGroup[]; account?: StudentAccount | null; guardians?: StudentGuardian[] }
export interface StudentAttendanceEntry { lessonId: string; title: string; startsAt: string; status: Lesson['status']; attendance: 'pending' | 'present' | 'absent' }
export interface StudentResultEntry { id: string; lessonId: string; lessonTitle: string; startsAt: string; status: ResultStatus; comment: string | null; updatedAt: string }
export interface StudentProgress { from: string; to: string; attendance: { present: number; absent: number }; results: { mastered: number; developing: number; needs_support: number } }
export interface SavedLessonResult { id: string; lessonId: string; studentId: string; status: ResultStatus; comment: string | null; updatedAt: string }
export interface StudentAccount { accountId: string; activatedAt: string; revokedAt: string | null }
export interface ActivityAssignment { id: string; activityId: string; activityCode: string; activityName: string; groupId: string | null; groupName: string | null; studentId: string | null; studentPersonId: string | null; config: Record<string, unknown>; opensAt: string | null; dueAt: string | null; completion: Record<string, unknown> | null; createdAt: string }
export interface GroupActivityAttempt { id: string; studentId: string; personId: string; name?: string; status: 'running' | 'finished' | 'expired' | 'failed'; source: string; startedAt: string; finishedAt: string | null; durationSeconds?: number | null; language?: string | null; keystrokes?: number | null; correctChars?: number | null; errors?: number | null; wpm?: number | null; accuracy?: number | null; score?: number | null; correctAnswers?: number | null; totalRounds?: number | null }
export interface Material { id: string; name: string; mime: string; sizeBytes: number; lessonId?: string | null; lessonStatus?: Lesson['status'] | null; createdAt?: string }
export interface Attention { date: string; items: { id: string; title: string; absentCount: number }[] }
export interface Thread {
  id: string;
  subject: string;
  createdAt?: string;
  lastActivityAt?: string;
  peerPersonId?: string | null;
  peerName?: string | null;
  peerRole?: string | null;
  contextType?: string | null;
  contextId?: string | null;
  lastMessageBody?: string | null;
  lastMessageAt?: string | null;
  lastMessageSenderId?: string | null;
  unreadCount?: number;
}
export interface EducationContact {
  personId: string;
  name: string;
  role: 'teacher' | 'student' | 'parent';
  contextType?: string | null;
  contextId?: string | null;
  contextName?: string | null;
}
export interface MessageReaction {
  emoji: string;
  count: number;
  reacted?: boolean;
}

export interface MessageMedia {
  id: string;
  kind: string;
  name?: string;
  mime?: string;
  size?: number;
  duration?: number | null;
  status?: string;
}

export interface MessageReply {
  id: string;
  body?: string | null;
  senderPersonId?: string;
}

export interface Message {
  id: string;
  senderPersonId: string;
  senderName?: string | null;
  body: string | null;
  createdAt: string;
  clientKey?: string | null;
  kind?: string;
  mediaAssetId?: string | null;
  replyToId?: string | null;
  metadata?: Record<string, unknown> | null;
  reactions?: MessageReaction[];
  media?: MessageMedia | null;
  replyTo?: MessageReply | null;
}
export interface Notification { id: string; threadId: string | null; subject: string; createdAt: string; readAt: string | null; kind?: string; resourceId?: string | null; resourcePath?: string | null; body?: string | null }
export interface Page<T> { items: T[]; limit: number; offset: number; nextOffset: number | null }

export interface CurriculumLesson { id: string; title: string; description: string; position: number; archivedAt: string | null }
export interface CurriculumModule { id: string; name: string; description: string; position: number; archivedAt: string | null; lessons: CurriculumLesson[] }
export interface EducationProgram { id: string; name: string; description: string; archivedAt: string | null; modules?: CurriculumModule[] }
export interface GroupProgram { id: string; programId: string; name: string; assignedAt: string; endedAt: string | null; modules: CurriculumModule[] }
export interface TeacherAssignments { ownerAccountId: string; canAssign: boolean; items: { accountId: string; personId: string; name: string; assigned: boolean }[] }
export interface ProgramProgress { assignmentId: string; programId: string; name: string; totalLessons: number; completedLessons: number; percent: number; modules: { id?: string; name: string; totalLessons: number; completedLessons: number; percent: number }[] }

type Guard<T> = (value: unknown) => value is T;
const record = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value);
const text = (value: unknown): value is string => typeof value === 'string';
const uuid = (value: unknown): value is string => text(value) && /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(value);
const integer = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
const number = (value: unknown): value is number => typeof value === 'number' && Number.isFinite(value);
const instant = (value: unknown): value is string => text(value) && Number.isFinite(Date.parse(value)) && value.includes('T');
const date = (value: unknown): value is string => text(value) && /^\d{4}-\d{2}-\d{2}$/.test(value) && Number.isFinite(Date.parse(`${value}T12:00:00Z`)) && new Date(`${value}T12:00:00Z`).toISOString().slice(0, 10) === value;
const timezone = (value: unknown): value is string => { if (!text(value) || !value) return false; try { new Intl.DateTimeFormat('en', { timeZone: value }); return true; } catch { return false; } };
const locale = (value: unknown): value is string => { if (!text(value) || !value) return false; try { return Intl.getCanonicalLocales(value).length === 1; } catch { return false; } };
const nullableText = (value: unknown): value is string | null => value === null || text(value);
const nullableUuid = (value: unknown): value is string | null => value === null || uuid(value);
const nullableInstant = (value: unknown): value is string | null => value === null || instant(value);
const named = (value: unknown): value is PersonChoice => record(value) && uuid(value.id) && text(value.name);
const curriculumLessonGuard: Guard<CurriculumLesson> = (v): v is CurriculumLesson => record(v) && uuid(v.id) && text(v.title) && text(v.description) && integer(v.position) && nullableInstant(v.archivedAt);
const curriculumModuleGuard: Guard<CurriculumModule> = (v): v is CurriculumModule => record(v) && uuid(v.id) && text(v.name) && text(v.description) && integer(v.position) && nullableInstant(v.archivedAt) && Array.isArray(v.lessons) && v.lessons.every(curriculumLessonGuard);
const programGuard: Guard<EducationProgram> = (v): v is EducationProgram => record(v) && uuid(v.id) && text(v.name) && text(v.description) && nullableInstant(v.archivedAt) && (v.modules === undefined || Array.isArray(v.modules) && v.modules.every(curriculumModuleGuard));
const groupProgramGuard: Guard<GroupProgram> = (v): v is GroupProgram => record(v) && uuid(v.id) && uuid(v.programId) && text(v.name) && instant(v.assignedAt) && nullableInstant(v.endedAt) && Array.isArray(v.modules) && v.modules.every(curriculumModuleGuard);
const progressGuard: Guard<{ programs: ProgramProgress[] }> = (v): v is { programs: ProgramProgress[] } => record(v) && Array.isArray(v.programs) && v.programs.every(p => record(p) && uuid(p.assignmentId) && uuid(p.programId) && text(p.name) && integer(p.totalLessons) && integer(p.completedLessons) && number(p.percent) && p.percent >= 0 && p.percent <= 100 && Array.isArray(p.modules) && p.modules.every(m => record(m) && text(m.name) && integer(m.totalLessons) && integer(m.completedLessons) && number(m.percent)));
const teacherGuard: Guard<TeacherAssignments> = (v): v is TeacherAssignments => record(v) && uuid(v.ownerAccountId) && typeof v.canAssign === 'boolean' && Array.isArray(v.items) && v.items.every(x => record(x) && uuid(x.accountId) && uuid(x.personId) && text(x.name) && typeof x.assigned === 'boolean');
const contextGuard: Guard<EducationContext> = (v): v is EducationContext => record(v) && Array.isArray(v.organizations) && v.organizations.every(named) && uuid(v.personId) && nullableText(v.displayName) && locale(v.locale) && timezone(v.timezone);
const headerGuard: Guard<Header> = (v): v is Header => record(v) && nullableText(v.displayName) && nullableUuid(v.avatarFileId) && uuid(v.personId) && date(v.date) && instant(v.now) && ['night', 'morning', 'afternoon', 'evening'].includes(String(v.greeting)) && locale(v.locale) && timezone(v.timezone) && integer(v.unreadCount);
const groupGuard: Guard<Group> = (v): v is Group => named(v) && (!('studentCount' in v) || integer(v.studentCount)) && (!('archivedAt' in v) || nullableInstant(v.archivedAt));
const studentGuard: Guard<Student> = (v): v is Student => record(v) && uuid(v.id) && uuid(v.personId) && (v.name === undefined || text(v.name));
const lessonGuard: Guard<Lesson> = (v): v is Lesson => record(v) && uuid(v.id) && uuid(v.groupId) && text(v.groupName) && text(v.title) && instant(v.startsAt) && instant(v.endsAt) && text(v.location) && ['scheduled', 'completed', 'cancelled'].includes(String(v.status)) && integer(v.studentCount) && (!('completedAt' in v) || nullableInstant(v.completedAt)) && (!('cancelledAt' in v) || nullableInstant(v.cancelledAt)) && (!('curriculumLessonId' in v) || v.curriculumLessonId === null || uuid(v.curriculumLessonId)) && (!('curriculumSnapshot' in v) || v.curriculumSnapshot === null || record(v.curriculumSnapshot));
const eventGuard: Guard<CalendarEvent> = (v): v is CalendarEvent => record(v) && uuid(v.id) && text(v.title) && ['meeting', 'birthday', 'other'].includes(String(v.kind)) && instant(v.startsAt) && instant(v.endsAt) && text(v.location);
const timelineGuard: Guard<TimelineItem> = (v): v is TimelineItem => (record(v) && v.kind === 'lesson' && lessonGuard(v)) || eventGuard(v);
const scheduleGuard: Guard<Schedule> = (v): v is Schedule => record(v) && date(v.from) && date(v.to) && timezone(v.timezone) && Array.isArray(v.lessons) && v.lessons.every(lessonGuard) && Array.isArray(v.events) && v.events.every(eventGuard);
const materialGuard: Guard<Material> = (v): v is Material => record(v) && uuid(v.id) && text(v.name) && ['application/pdf', 'text/plain', 'image/png', 'image/jpeg', 'image/webp'].includes(String(v.mime)) && integer(v.sizeBytes) && v.sizeBytes > 0 && v.sizeBytes <= 10485760 && (!('lessonStatus' in v) || v.lessonStatus === null || ['scheduled', 'completed', 'cancelled'].includes(String(v.lessonStatus)));
const participantGuard: Guard<Participant> = (v): v is Participant => named(v) && record(v) && uuid(v.personId) && ['pending', 'present', 'absent'].includes(String(v.status));
const resultStatusGuard: Guard<ResultStatus> = (v): v is ResultStatus => ['pending', 'mastered', 'developing', 'needs_support'].includes(String(v));
const lessonReportGuard: Guard<LessonReport> = (v): v is LessonReport => record(v) && uuid(v.id) && uuid(v.lessonId) && text(v.topic) && text(v.summary) && nullableText(v.teacherComment) && instant(v.updatedAt);
const lessonResultEntryGuard: Guard<LessonResultEntry> = (v): v is LessonResultEntry => record(v) && uuid(v.id) && uuid(v.personId) && text(v.name) && resultStatusGuard(v.status) && nullableText(v.comment) && (v.updatedAt === undefined || nullableInstant(v.updatedAt));
const lessonResultsGuard: Guard<LessonResults> = (v): v is LessonResults => record(v) && (v.report === null || lessonReportGuard(v.report)) && Array.isArray(v.items) && v.items.every(lessonResultEntryGuard);
const journalStudentResultGuard: Guard<JournalStudentResult> = (v): v is JournalStudentResult => record(v) && uuid(v.studentId) && text(v.name) && resultStatusGuard(v.status) && nullableText(v.comment);
const journalEntryGuard: Guard<JournalEntry> = (v): v is JournalEntry => record(v) && lessonGuard(v) && (v.report === null || lessonReportGuard(v.report)) && Array.isArray(v.results) && v.results.every(journalStudentResultGuard);
const studentGroupGuard: Guard<StudentGroup> = (v): v is StudentGroup => record(v) && uuid(v.id) && text(v.name) && nullableInstant(v.archivedAt);
const guardianGuard: Guard<StudentGuardian> = (v): v is StudentGuardian => record(v) && uuid(v.personId) && text(v.name) && ['mother', 'father', 'guardian'].includes(String(v.relationship)) && Array.isArray(v.contacts) && v.contacts.every(c => record(c) && text(c.type) && text(c.value) && nullableText(c.label));
const studentCardGuard: Guard<StudentCard> = (v): v is StudentCard => record(v) && uuid(v.id) && uuid(v.personId) && text(v.name) && instant(v.createdAt) && Array.isArray(v.groups) && v.groups.every(studentGroupGuard) && (v.guardians === undefined || Array.isArray(v.guardians) && v.guardians.every(guardianGuard)) && (v.account === undefined || v.account === null || (record(v.account) && uuid(v.account.accountId) && instant(v.account.activatedAt) && nullableInstant(v.account.revokedAt)));
const assignmentGuard: Guard<ActivityAssignment> = (v): v is ActivityAssignment => record(v) && uuid(v.id) && uuid(v.activityId) && text(v.activityCode) && text(v.activityName) && (v.groupId === null || uuid(v.groupId)) && (v.groupName === null || text(v.groupName)) && (v.studentId === null || uuid(v.studentId)) && (v.studentPersonId === null || uuid(v.studentPersonId)) && record(v.config) && nullableInstant(v.opensAt) && nullableInstant(v.dueAt) && (v.completion === null || record(v.completion)) && instant(v.createdAt);
const groupActivityAttemptGuard: Guard<GroupActivityAttempt> = (v): v is GroupActivityAttempt => record(v) && uuid(v.id) && uuid(v.studentId) && uuid(v.personId) && (v.name === undefined || text(v.name)) && ['running', 'finished', 'expired', 'failed'].includes(String(v.status)) && text(v.source) && instant(v.startedAt) && nullableInstant(v.finishedAt) && (v.wpm === undefined || v.wpm === null || number(v.wpm)) && (v.accuracy === undefined || v.accuracy === null || number(v.accuracy));
const studentAttendanceGuard: Guard<StudentAttendanceEntry> = (v): v is StudentAttendanceEntry => record(v) && uuid(v.lessonId) && text(v.title) && instant(v.startsAt) && ['scheduled', 'completed', 'cancelled'].includes(String(v.status)) && ['pending', 'present', 'absent'].includes(String(v.attendance));
const studentResultEntryGuard: Guard<StudentResultEntry> = (v): v is StudentResultEntry => record(v) && uuid(v.id) && uuid(v.lessonId) && text(v.lessonTitle) && instant(v.startsAt) && resultStatusGuard(v.status) && nullableText(v.comment) && instant(v.updatedAt);
const studentProgressGuard: Guard<StudentProgress> = (v): v is StudentProgress => record(v) && date(v.from) && date(v.to) && record(v.attendance) && integer(v.attendance.present) && integer(v.attendance.absent) && record(v.results) && integer(v.results.mastered) && integer(v.results.developing) && integer(v.results.needs_support);
const savedResultGuard: Guard<SavedLessonResult> = (v): v is SavedLessonResult => record(v) && uuid(v.id) && uuid(v.lessonId) && uuid(v.studentId) && resultStatusGuard(v.status) && nullableText(v.comment) && instant(v.updatedAt);
const contactGuard: Guard<EducationContact> = (v): v is EducationContact =>
  record(v) && uuid(v.personId) && text(v.name) &&
  ['teacher', 'student', 'parent'].includes(String(v.role)) &&
  (v.contextType === undefined || v.contextType === null || text(v.contextType)) &&
  (v.contextId === undefined || v.contextId === null || uuid(v.contextId)) &&
  (v.contextName === undefined || v.contextName === null || text(v.contextName));
const threadGuard: Guard<Thread> = (v): v is Thread =>
  record(v) &&
  uuid(v.id) &&
  text(v.subject) &&
  (v.unreadCount === undefined || integer(v.unreadCount)) &&
  (!('createdAt' in v) || nullableInstant(v.createdAt)) &&
  (!('lastActivityAt' in v) || nullableInstant(v.lastActivityAt)) &&
  (!('peerPersonId' in v) || nullableUuid(v.peerPersonId)) &&
  (!('peerName' in v) || nullableText(v.peerName)) &&
  (!('peerRole' in v) || nullableText(v.peerRole)) &&
  (!('contextType' in v) || nullableText(v.contextType)) &&
  (!('contextId' in v) || nullableUuid(v.contextId)) &&
  (!('lastMessageBody' in v) || nullableText(v.lastMessageBody)) &&
  (!('lastMessageAt' in v) || nullableInstant(v.lastMessageAt)) &&
  (!('lastMessageSenderId' in v) || nullableUuid(v.lastMessageSenderId));
const messageGuard: Guard<Message> = (v): v is Message => record(v) && uuid(v.id) && uuid(v.senderPersonId) && nullableText(v.body) && instant(v.createdAt) && (v.senderName === undefined || nullableText(v.senderName));
const notificationGuard: Guard<Notification> = (v): v is Notification => record(v) && uuid(v.id) && nullableUuid(v.threadId) && text(v.subject) && instant(v.createdAt) && nullableInstant(v.readAt) && (v.kind === undefined || text(v.kind)) && (v.resourceId === undefined || nullableUuid(v.resourceId)) && (v.resourcePath === undefined || nullableText(v.resourcePath)) && (v.body === undefined || nullableText(v.body));
function pageGuard<T>(guard: Guard<T>): Guard<Page<T>> {
  return (v): v is Page<T> => record(v) && Array.isArray(v.items) && v.items.length <= 50 && v.items.every(guard) && v.limit === 50 && integer(v.offset) && (v.nextOffset === null || (integer(v.nextOffset) && v.nextOffset === v.offset + 50));
}
function checked<T>(value: unknown, guard: Guard<T>, path: string): T {
  if (!guard(value)) throw new ApiResponseError(path);
  return value;
}

async function get<T>(path: string, organization: string | null, guard: Guard<T>, signal?: AbortSignal): Promise<T> {
  return educationRead(organization, path, async requestSignal => checked(await coreAdminHttp.request<unknown>(`/education${path}`, { signal: requestSignal, headers: organization ? { 'X-Education-Organization': organization } : {} }), guard, path), signal);
}
async function write<T>(path: string, organization: string, body: unknown, guard: Guard<T>, method: 'POST' | 'PUT' | 'DELETE' = 'POST'): Promise<T> {
  const started = performance.now();
  try { return checked(await coreAdminHttp.request<unknown>(`/education${path}`, { method, body, headers: { 'X-Education-Organization': organization } }), guard, path); }
  finally { invalidateEducationCache(educationTags(path)); educationMeasurements.record('write', started); }
}
const updated: Guard<{ updated: true }> = (v): v is { updated: true } => record(v) && v.updated === true;
const deleted: Guard<{ id: string; deleted: true }> = (v): v is { id: string; deleted: true } => record(v) && uuid(v.id) && v.deleted === true;
function pathId(id: string): string { if (!uuid(id)) throw new ApiError(404, 'http.not_found', 'Record not found.'); return id; }
const query = (path: string, offset: number) => `${path}${path.includes('?') ? '&' : '?'}offset=${offset}`;

export const educationApi = {
  revokeActivityAttempt: (org: string, id: string) => write(`/attempts/${pathId(id)}/revoke`, org, {},
    (value): value is { id: string; studentId: string; revokedAt: string } =>
      record(value) && value.id === id && uuid(value.studentId) && instant(value.revokedAt), 'PUT'),
  async activateStudent(code: string, signal?: AbortSignal) {
    const path = '/education/student-activate';
    try {
      const result = await coreAdminHttp.request<unknown>(path, { method: 'POST', body: { code }, signal });
      return checked(result, (value): value is { studentId: string; organizationId: string; accountId: string } =>
        record(value) && uuid(value.studentId) && uuid(value.organizationId) && uuid(value.accountId), path);
    } finally {
      invalidateEducationCache(['context', 'student', 'students', 'groups', 'header']);
    }
  },
  programs: (org: string, offset = 0, signal?: AbortSignal) => get(query('/programs', offset), org, pageGuard(programGuard), signal),
  program: (org: string, id: string, signal?: AbortSignal) => get(`/programs/${pathId(id)}`, org, programGuard, signal),
  createProgram: (org: string, values: { name: string; description: string }) => write('/programs', org, values, programGuard),
  updateProgram: (org: string, id: string, values: { name: string; description: string; archived?: boolean }) => write(`/programs/${pathId(id)}`, org, values, programGuard, 'PUT'),
  createCurriculumModule: (org: string, id: string, values: { name: string; description: string; position: number }) => write(`/programs/${pathId(id)}/modules`, org, values, (v): v is { id: string } => record(v) && uuid(v.id)),
  updateCurriculumModule: (org: string, id: string, values: { name: string; description: string; position: number; archived?: boolean }) => write(`/curriculum-modules/${pathId(id)}`, org, values, (v): v is { id: string } => record(v) && uuid(v.id), 'PUT'),
  createCurriculumLesson: (org: string, id: string, values: { title: string; description: string; position: number }) => write(`/curriculum-modules/${pathId(id)}/lessons`, org, values, (v): v is { id: string } => record(v) && uuid(v.id)),
  updateCurriculumLesson: (org: string, id: string, values: { title: string; description: string; position: number; archived?: boolean }) => write(`/curriculum-lessons/${pathId(id)}`, org, values, (v): v is { id: string } => record(v) && uuid(v.id), 'PUT'),
  groupPrograms: (org: string, id: string, signal?: AbortSignal) => get(`/groups/${pathId(id)}/programs`, org, (v): v is { items: GroupProgram[] } => record(v) && Array.isArray(v.items) && v.items.every(groupProgramGuard), signal),
  assignProgram: (org: string, id: string, programId: string) => write(`/groups/${pathId(id)}/programs`, org, { programId }, (v): v is { id: string } => record(v) && uuid(v.id)),
  groupProgress: (org: string, id: string, signal?: AbortSignal) => get(`/groups/${pathId(id)}/progress`, org, progressGuard, signal),
  groupTeachers: (org: string, id: string, signal?: AbortSignal) => get(`/groups/${pathId(id)}/teachers`, org, teacherGuard, signal),
  assignTeachers: (org: string, id: string, accountIds: string[]) => write(`/groups/${pathId(id)}/teachers`, org, { accountIds }, teacherGuard, 'PUT'),
  createStudent: (org: string, values: { givenName: string; familyName: string; middleName?: string; birthDate?: string; groupId: string }) => write('/students/new', org, values, studentGuard),
  context: (signal?: AbortSignal) => get('/context', null, contextGuard, signal),
  header: (org: string, signal?: AbortSignal) => get('/today/header', org, headerGuard, signal),
  profile: (org: string, signal?: AbortSignal) => get('/profile', org, headerGuard, signal),
  schedule: (org: string, from: string, to: string, signal?: AbortSignal) => get(`/schedule?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`, org, (v): v is Schedule => scheduleGuard(v) && v.from === from && v.to === to, signal),
  timeline: (org: string, selected: string, signal?: AbortSignal) => get(`/timeline?date=${encodeURIComponent(selected)}`, org, (v): v is { date: string; timezone: string; items: TimelineItem[] } => record(v) && v.date === selected && date(v.date) && timezone(v.timezone) && Array.isArray(v.items) && v.items.every(timelineGuard), signal),
  next: (org: string, signal?: AbortSignal) => get('/lessons/next', org, (v): v is Lesson | null => v === null || lessonGuard(v), signal),
  current: (org: string, signal?: AbortSignal) => get('/lessons/current', org, (v): v is Lesson | null => v === null || lessonGuard(v), signal),
  attention: (org: string, signal?: AbortSignal) => get('/attention', org, (v): v is Attention => record(v) && date(v.date) && Array.isArray(v.items) && v.items.every(item => record(item) && uuid(item.id) && text(item.title) && integer(item.absentCount) && item.absentCount > 0), signal),
  groups: (org: string, offset = 0, signal?: AbortSignal) => get(query('/groups', offset), org, pageGuard(groupGuard), signal),
  group: (org: string, id: string, signal?: AbortSignal) => get(`/groups/${pathId(id)}`, org, groupGuard, signal),
  groupStudents: (org: string, id: string, offset = 0, signal?: AbortSignal) => get(query(`/groups/${pathId(id)}/students`, offset), org, pageGuard(studentGuard), signal),
  students: (org: string, offset = 0, signal?: AbortSignal) => get(query('/students', offset), org, pageGuard(studentGuard), signal),
  candidates: (org: string, offset = 0, signal?: AbortSignal) => get(query('/people/candidates', offset), org, pageGuard(named), signal),
  colleagues: (org: string, offset = 0, signal?: AbortSignal) => get(query('/colleagues', offset), org, pageGuard(named), signal),
  files: (org: string, offset = 0, lessonId?: string, signal?: AbortSignal) => get(query(lessonId ? `/lessons/${pathId(lessonId)}/materials` : '/files', offset), org, pageGuard(materialGuard), signal),
  lesson: (org: string, id: string, signal?: AbortSignal) => get(`/lessons/${pathId(id)}`, org, lessonGuard, signal),
  participants: (org: string, id: string, signal?: AbortSignal) => get(`/lessons/${pathId(id)}/participants`, org, (v): v is { items: Participant[] } => record(v) && Array.isArray(v.items) && v.items.every(participantGuard), signal),
  lessonResults: (org: string, id: string, signal?: AbortSignal) => get(`/lessons/${pathId(id)}/results`, org, lessonResultsGuard, signal),
  saveLessonReport: (org: string, id: string, values: { topic: string; summary: string; teacherComment: string | null }) => write(`/lessons/${pathId(id)}/report`, org, values, lessonReportGuard, 'PUT'),
  saveLessonResult: (org: string, id: string, studentId: string, values: { status: ResultStatus; comment: string | null }) => write(`/lessons/${pathId(id)}/results/${pathId(studentId)}`, org, values, savedResultGuard, 'PUT'),
  journal: (org: string, id: string, filters: { from?: string; to?: string; studentId?: string; offset?: number } = {}, signal?: AbortSignal) => {
    const params = new URLSearchParams();
    if (filters.from) params.set('from', filters.from);
    if (filters.to) params.set('to', filters.to);
    if (filters.studentId) params.set('studentId', filters.studentId);
    params.set('offset', String(filters.offset ?? 0));
    return get(`/groups/${pathId(id)}/journal?${params.toString()}`, org, pageGuard(journalEntryGuard), signal);
  },
  studentCurriculumProgress: (org: string, id: string, signal?: AbortSignal) => get(`/students/${pathId(id)}/curriculum-progress`, org, progressGuard, signal),
  myCurriculumProgress: (org: string, signal?: AbortSignal) => get('/student/curriculum-progress', org, progressGuard, signal),
  student: (org: string, id: string, signal?: AbortSignal) => get(`/students/${pathId(id)}`, org, studentCardGuard, signal),
  studentAttendance: (org: string, id: string, from: string, to: string, offset = 0, signal?: AbortSignal) => get(
    `/students/${pathId(id)}/attendance?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&offset=${offset}`,
    org,
    (v): v is Page<StudentAttendanceEntry> & { from: string; to: string } => pageGuard(studentAttendanceGuard)(v) && date((v as { from?: unknown }).from) && date((v as { to?: unknown }).to),
    signal,
  ),
  studentResults: (org: string, id: string, offset = 0, signal?: AbortSignal) => get(query(`/students/${pathId(id)}/results`, offset), org, pageGuard(studentResultEntryGuard), signal),
  studentProgress: (org: string, id: string, from: string, to: string, signal?: AbortSignal) => get(
    `/students/${pathId(id)}/progress?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,
    org,
    (v): v is StudentProgress => studentProgressGuard(v) && v.from === from && v.to === to,
    signal,
  ),
  inviteStudent: (org: string, id: string) => write(`/students/${pathId(id)}/invite`, org, {}, (v): v is { id: string; code: string; expiresAt: string } => record(v) && uuid(v.id) && text(v.code) && instant(v.expiresAt), 'POST'),
  revokeStudentAccount: (org: string, id: string) => write(`/students/${pathId(id)}/revoke-account`, org, {}, (v): v is { revoked: boolean } => record(v) && v.revoked === true, 'POST'),
  activityAssignments: (org: string, offset = 0, signal?: AbortSignal) => get(query('/activities/assignments', offset), org, pageGuard(assignmentGuard), signal),
  assignActivity: (org: string, code: string, values: { groupId?: string; studentId?: string; config?: Record<string, unknown> }) => write(`/activities/${encodeURIComponent(code)}/assignments`, org, values, assignmentGuard, 'POST'),
  groupActivityResults: (org: string, id: string, activity: string, offset = 0, signal?: AbortSignal, period?: { from: string; to: string }) => get(
    `/groups/${pathId(id)}/activity-results?activity=${encodeURIComponent(activity)}&offset=${offset}${period ? `&from=${encodeURIComponent(period.from)}&to=${encodeURIComponent(period.to)}` : ''}`,
    org,
    (v): v is Page<GroupActivityAttempt> & { activity?: string } => pageGuard(groupActivityAttemptGuard)(v) && ((v as { activity?: unknown }).activity === undefined || text((v as { activity?: unknown }).activity)),
    signal,
  ),
  contacts: (org: string, signal?: AbortSignal) => get('/contacts', org, (v): v is { items: EducationContact[] } => record(v) && Array.isArray(v.items) && v.items.every(contactGuard), signal),
  studentContacts: (org: string, signal?: AbortSignal) => get('/student/contacts', org, (v): v is { items: EducationContact[] } => record(v) && Array.isArray(v.items) && v.items.every(contactGuard), signal),
  studentThreads: (org: string, offset = 0, signal?: AbortSignal) => get(query('/student/threads', offset), org, pageGuard(threadGuard), signal),
  studentThread: (org: string, id: string, signal?: AbortSignal) => get(`/student/threads/${pathId(id)}`, org, threadGuard, signal),
  studentMessages: (org: string, id: string, offset = 0, signal?: AbortSignal, before?: string) => {
    const params = new URLSearchParams({ offset: String(offset) });
    if (before) params.set('before', before);
    return get(`/student/threads/${pathId(id)}/messages?${params.toString()}`, org, pageGuard(messageGuard), signal);
  },
  studentStartThread: (org: string, recipientPersonId: string, subject: string, body: string, contextType?: string, contextId?: string) => write('/student/threads', org, { recipientPersonId, subject, body, ...(contextType ? { contextType } : {}), ...(contextId ? { contextId } : {}) }, threadGuard),
  studentSendMessage: (org: string, id: string, body: string | null, clientKey?: string, kind = 'text', mediaAssetId?: string | null, replyToId?: string | null, metadata?: Record<string, unknown>) => write(`/student/threads/${pathId(id)}/messages`, org, { body, ...(clientKey ? { clientKey } : {}), kind, ...(mediaAssetId ? { mediaAssetId } : {}), ...(replyToId ? { replyToId } : {}), ...(metadata ? { metadata } : {}) }, messageGuard),
  studentToggleReaction: (org: string, id: string, messageId: string, emoji: string) => write(`/student/threads/${pathId(id)}/reactions`, org, { messageId, emoji }, (v): v is { reacted: boolean; reactions: MessageReaction[] } => record(v) && typeof v.reacted === 'boolean' && Array.isArray(v.reactions)),
  studentReadThread: (org: string, id: string, lastSeenMessageId: string) => write(`/student/threads/${pathId(id)}/read`, org, { lastSeenMessageId }, updated),
  threads: (org: string, offset = 0, signal?: AbortSignal) => get(query('/threads', offset), org, pageGuard(threadGuard), signal),
  thread: (org: string, id: string, signal?: AbortSignal) => get(`/threads/${pathId(id)}`, org, threadGuard, signal),
  messages: (org: string, id: string, offset = 0, signal?: AbortSignal, before?: string) => {
    const params = new URLSearchParams({ offset: String(offset) });
    if (before) params.set('before', before);
    return get(`/threads/${pathId(id)}/messages?${params.toString()}`, org, pageGuard(messageGuard), signal);
  },
  notifications: (org: string, offset = 0, signal?: AbortSignal) => get(query('/notifications', offset), org, pageGuard(notificationGuard), signal),
  studentNotifications: (org: string, offset = 0, signal?: AbortSignal) => get(query('/student/notifications', offset), org, pageGuard(notificationGuard), signal),
  readStudentNotification: (org: string, id: string) => write(`/student/notifications/${pathId(id)}/read`, org, {}, updated),
  createGroup: (org: string, name: string) => write('/groups', org, { name }, groupGuard),
  updateGroup: (org: string, id: string, name: string) => write(`/groups/${pathId(id)}`, org, { name }, groupGuard, 'PUT'),
  archiveGroup: (org: string, id: string) => write(`/groups/${pathId(id)}/archive`, org, {}, groupGuard),
  removeGroupStudent: (org: string, groupId: string, studentId: string) => write(`/groups/${pathId(groupId)}/students/${pathId(studentId)}`, org, {}, updated, 'DELETE'),
  transferStudent: (org: string, groupId: string, studentId: string, targetGroupId: string) => write(
    `/groups/${pathId(groupId)}/students/${pathId(studentId)}/transfer`,
    org,
    { groupId: targetGroupId },
    updated,
  ),
  enroll: (org: string, personId: string, groupId: string) => write('/students', org, { personId, groupId }, studentGuard),
  createLesson: (org: string, values: { title: string; groupId: string; startsAt: string; endsAt: string; location: string; curriculumLessonId?: string | null }) => write('/lessons', org, values, lessonGuard),
  updateLesson: (org: string, id: string, values: { title: string; groupId: string; startsAt: string; endsAt: string; location: string; curriculumLessonId?: string | null }) => write(`/lessons/${pathId(id)}`, org, values, lessonGuard, 'PUT'),
  completeLesson: (org: string, id: string) => write(`/lessons/${pathId(id)}/complete`, org, {}, lessonGuard),
  cancelLesson: (org: string, id: string) => write(`/lessons/${pathId(id)}/cancel`, org, {}, lessonGuard),
  createEvent: (org: string, values: { title: string; kind: string; startsAt: string; endsAt: string; location: string }) => write('/events', org, values, eventGuard),
  attendance: (org: string, lessonId: string, studentId: string, status: string) => write(`/lessons/${pathId(lessonId)}/participants/${pathId(studentId)}`, org, { status }, updated, 'PUT'),
  saveProfile: (org: string, displayName: string | null, avatarFileId: string | null) => write('/profile', org, { displayName, avatarFileId }, headerGuard, 'PUT'),
  startThread: (org: string, recipientPersonId: string, subject: string, body: string, contextType?: string, contextId?: string) => write('/threads', org, { recipientPersonId, subject, body, ...(contextType ? { contextType } : {}), ...(contextId ? { contextId } : {}) }, threadGuard),
  sendMessage: (org: string, id: string, body: string | null, clientKey?: string, kind = 'text', mediaAssetId?: string | null, replyToId?: string | null, metadata?: Record<string, unknown>) => write(`/threads/${pathId(id)}/messages`, org, { body, ...(clientKey ? { clientKey } : {}), kind, ...(mediaAssetId ? { mediaAssetId } : {}), ...(replyToId ? { replyToId } : {}), ...(metadata ? { metadata } : {}) }, messageGuard),
  toggleReaction: (org: string, id: string, messageId: string, emoji: string) => write(`/threads/${pathId(id)}/reactions`, org, { messageId, emoji }, (v): v is { reacted: boolean; reactions: MessageReaction[] } => record(v) && typeof v.reacted === 'boolean' && Array.isArray(v.reactions)),
  readThread: (org: string, id: string, lastSeenMessageId: string) => write(`/threads/${pathId(id)}/read`, org, { lastSeenMessageId }, updated),
  readNotification: (org: string, id: string) => write(`/notifications/${pathId(id)}/read`, org, {}, updated),
  upload: async (org: string, file: File, lessonId?: string, avatar = false): Promise<Material> => {
    try {
      const value = await educationUpload(org, '/education/file-transfers', file, { ...(lessonId ? { lessonId } : {}), avatar });
      if (!materialGuard(value)) throw new ApiResponseError('/education/file-transfers');
      return value;
    } finally { invalidateEducationCache(educationTags('/files')); }
  },
  deleteFile: (org: string, id: string) => write(`/files/${pathId(id)}`, org, {}, deleted, 'DELETE'),
  download: (org: string, id: string, signal?: AbortSignal) => educationDownload(org, `/education/files/${pathId(id)}/transfer`, signal),
};
