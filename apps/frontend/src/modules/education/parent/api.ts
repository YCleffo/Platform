import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiResponseError } from '@/shared/api/errors';
import type { ActivityProgress, Achievement } from '../activityTypes';
import { progressGuard as activityProgressGuard, achievementGuard } from '../activityApi';
import type { Page, ProgramProgress, Notification, PersonChoice, Thread, Message, EducationContact } from '../api';

export interface ParentChild {
  id: string;
  personId: string;
  name: string;
  organizationId: string;
  organizationName: string;
  groups: { id: string; name: string }[];
}
export interface ParentContext {
  personId: string;
  displayName: string | null;
  locale: string;
  timezone: string;
  children: ParentChild[];
  notices?: { id: string; kind: string; createdAt: string; readAt: string | null }[];
}
export interface ParentLesson {
  id: string;
  groupId: string;
  groupName: string;
  title: string;
  startsAt: string;
  endsAt: string;
  location: string;
  status: 'scheduled' | 'completed' | 'cancelled';
  attendance?: string;
  resultStatus?: string;
  resultComment?: string | null;
  topic?: string | null;
  summary?: string | null;
}
export interface ParentHomework {
  id: string;
  title: string;
  description: string;
  instructions: string;
  type: string;
  dueAt: string | null;
  status: string;
  submissionState: string;
  submittedAt: string | null;
  review: {
    decision: string;
    resultStatus: string | null;
    comment: string | null;
    createdAt: string;
  } | null;
}
export interface AttendanceTotals {
  present: number;
  absent: number;
  pending: number;
  total: number;
}
export interface ParentHome {
  child: ParentChild;
  nextLesson: ParentLesson | null;
  results: ParentLesson[];
  homework: ParentHomework[];
  attendance: AttendanceTotals;
  unreadCount: number;
}
export interface ParentProgress {
  programs: ProgramProgress[];
  attendance: AttendanceTotals;
  homework: { total: number; submitted: number; reviewed: number; returned: number };
  typing: {
    attemptId?: string;
    id?: string;
    wpm: number;
    accuracy: number;
    createdAt?: string;
    language?: string;
    durationSeconds?: number;
  }[];
  activities: { id?: string; activityName?: string; status?: string; finishedAt?: string | null }[];
  achievements: Achievement[];
  activityProgress: ActivityProgress;
}
export type Relationship = 'mother' | 'father' | 'guardian';
export interface Guardian {
  personId: string;
  name: string;
  relationship: Relationship;
  status: 'pending' | 'confirmed' | 'revoked';
  confirmedAt: string | null;
  revokedAt: string | null;
  account: { accountId: string; activatedAt: string } | null;
  invitation: {
    id: string;
    expiresAt: string;
    usedAt: string | null;
    revokedAt: string | null;
  } | null;
}
export interface FamilyList {
  items: Guardian[];
  canManage: boolean;
  canConfirm: boolean;
}
export interface FamilyHistory {
  id: string;
  personId: string;
  actorAccountId: string | null;
  action: string;
  relationship: Relationship;
  status: string;
  createdAt: string;
}
export interface ParentInvitation {
  id: string;
  code: string;
  expiresAt: string;
  studentId: string;
  personId: string;
}

type Guard<T> = (value: unknown) => value is T;
const record = (v: unknown): v is Record<string, unknown> =>
  typeof v === 'object' && v !== null && !Array.isArray(v);
const text = (v: unknown): v is string => typeof v === 'string';
const nullableText = (v: unknown): v is string | null => v === null || text(v);
const uuid = (v: unknown): v is string =>
  text(v) && /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(v);
const nullableUuid = (v: unknown): v is string | null => v === null || uuid(v);
const numeric = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v) && v >= 0;
const integer = (v: unknown): v is number => numeric(v) && Number.isSafeInteger(v);
const instant = (v: unknown): v is string => text(v) && Number.isFinite(Date.parse(v));
const nullableInstant = (v: unknown): v is string | null => v === null || instant(v);
const named = (v: unknown): v is PersonChoice => record(v) && uuid(v.id) && text(v.name);
const arrayOf = <T>(v: unknown, guard: Guard<T>): v is T[] => Array.isArray(v) && v.every(guard);
const page =
  <T>(item: Guard<T>): Guard<Page<T>> =>
  (v): v is Page<T> =>
    record(v) &&
    arrayOf(v.items, item) &&
    integer(v.limit) &&
    integer(v.offset) &&
    (v.nextOffset === null || integer(v.nextOffset));
const childGuard: Guard<ParentChild> = (v): v is ParentChild =>
  record(v) &&
  uuid(v.id) &&
  uuid(v.personId) &&
  text(v.name) &&
  uuid(v.organizationId) &&
  text(v.organizationName) &&
  arrayOf(v.groups, named);
const contextGuard: Guard<ParentContext> = (v): v is ParentContext =>
  record(v) &&
  uuid(v.personId) &&
  nullableText(v.displayName) &&
  text(v.locale) &&
  text(v.timezone) &&
  arrayOf(v.children, childGuard) &&
  (v.notices === undefined ||
    arrayOf(
      v.notices,
      (n): n is NonNullable<ParentContext['notices']>[number] =>
        record(n) &&
        uuid(n.id) &&
        text(n.kind) &&
        instant(n.createdAt) &&
        nullableInstant(n.readAt),
    ));
const lessonGuard: Guard<ParentLesson> = (v): v is ParentLesson =>
  record(v) &&
  uuid(v.id) &&
  uuid(v.groupId) &&
  text(v.groupName) &&
  text(v.title) &&
  instant(v.startsAt) &&
  instant(v.endsAt) &&
  text(v.location) &&
  ['scheduled', 'completed', 'cancelled'].includes(String(v.status)) &&
  (v.attendance === undefined || ['pending', 'present', 'absent'].includes(String(v.attendance))) &&
  (v.resultComment === undefined || nullableText(v.resultComment));
const homeworkGuard: Guard<ParentHomework> = (v): v is ParentHomework =>
  record(v) &&
  uuid(v.id) &&
  text(v.title) &&
  text(v.description) &&
  text(v.instructions) &&
  text(v.type) &&
  nullableInstant(v.dueAt) &&
  text(v.status) &&
  text(v.submissionState) &&
  nullableInstant(v.submittedAt) &&
  (v.review === null ||
    (record(v.review) &&
      text(v.review.decision) &&
      nullableText(v.review.resultStatus) &&
      nullableText(v.review.comment) &&
      instant(v.review.createdAt)));
const attendanceGuard: Guard<AttendanceTotals> = (v): v is AttendanceTotals =>
  record(v) && integer(v.present) && integer(v.absent) && integer(v.pending) && integer(v.total);
const homeGuard: Guard<ParentHome> = (v): v is ParentHome =>
  record(v) &&
  childGuard(v.child) &&
  (v.nextLesson === null || lessonGuard(v.nextLesson)) &&
  arrayOf(v.results, lessonGuard) &&
  arrayOf(v.homework, homeworkGuard) &&
  attendanceGuard(v.attendance) &&
  integer(v.unreadCount);
const programGuard: Guard<ProgramProgress> = (v): v is ProgramProgress =>
  record(v) &&
  uuid(v.assignmentId) &&
  uuid(v.programId) &&
  text(v.name) &&
  integer(v.totalLessons) &&
  integer(v.completedLessons) &&
  numeric(v.percent) &&
  arrayOf(
    v.modules,
    (m): m is ProgramProgress['modules'][number] =>
      record(m) &&
      text(m.name) &&
      integer(m.totalLessons) &&
      integer(m.completedLessons) &&
      numeric(m.percent),
  );
const progressGuard: Guard<ParentProgress> = (v): v is ParentProgress =>
  record(v) &&
  arrayOf(v.programs, programGuard) &&
  attendanceGuard(v.attendance) &&
  record(v.homework) &&
  ['total', 'submitted', 'reviewed', 'returned'].every((key) =>
    integer((v.homework as Record<string, unknown>)[key]),
  ) &&
  arrayOf(
    v.typing,
    (item): item is ParentProgress['typing'][number] =>
      record(item) && numeric(item.wpm) && numeric(item.accuracy),
  ) &&
  arrayOf(v.activities, (item): item is ParentProgress['activities'][number] => record(item)) &&
  arrayOf(v.achievements, achievementGuard) && activityProgressGuard(v.activityProgress);
const notificationGuard: Guard<Notification> = (v): v is Notification =>
  record(v) && uuid(v.id) && text(v.subject) && instant(v.createdAt) && nullableInstant(v.readAt);
const contactGuard: Guard<EducationContact> = (v): v is EducationContact =>
  record(v) && uuid(v.personId) && text(v.name) && ['teacher', 'student', 'parent'].includes(String(v.role)) &&
  (!('context' in v) || nullableText(v.context)) &&
  (!('groupId' in v) || nullableUuid(v.groupId)) &&
  (!('childName' in v) || nullableText(v.childName));
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
const messageGuard: Guard<Message> = (v): v is Message =>
  record(v) && uuid(v.id) && uuid(v.senderPersonId) && nullableText(v.body) && instant(v.createdAt) && (v.senderName === undefined || nullableText(v.senderName));
const guardianGuard: Guard<Guardian> = (v): v is Guardian =>
  record(v) &&
  uuid(v.personId) &&
  text(v.name) &&
  ['mother', 'father', 'guardian'].includes(String(v.relationship)) &&
  ['pending', 'confirmed', 'revoked'].includes(String(v.status)) &&
  nullableInstant(v.confirmedAt) &&
  nullableInstant(v.revokedAt) &&
  (v.account === null ||
    (record(v.account) && uuid(v.account.accountId) && instant(v.account.activatedAt))) &&
  (v.invitation === null ||
    (record(v.invitation) &&
      uuid(v.invitation.id) &&
      instant(v.invitation.expiresAt) &&
      nullableInstant(v.invitation.usedAt) &&
      nullableInstant(v.invitation.revokedAt)));
const familyGuard: Guard<FamilyList> = (v): v is FamilyList =>
  record(v) &&
  arrayOf(v.items, guardianGuard) &&
  typeof v.canManage === 'boolean' &&
  typeof v.canConfirm === 'boolean';
const historyGuard: Guard<FamilyHistory> = (v): v is FamilyHistory =>
  record(v) &&
  uuid(v.id) &&
  uuid(v.personId) &&
  (v.actorAccountId === null || uuid(v.actorAccountId)) &&
  text(v.action) &&
  text(v.relationship) &&
  text(v.status) &&
  instant(v.createdAt);
const invitationGuard: Guard<ParentInvitation> = (v): v is ParentInvitation =>
  record(v) &&
  uuid(v.id) &&
  text(v.code) &&
  instant(v.expiresAt) &&
  uuid(v.studentId) &&
  uuid(v.personId);

async function request<T>(
  path: string,
  guard: Guard<T>,
  org?: string,
  signal?: AbortSignal,
  method: 'GET' | 'POST' | 'PUT' = 'GET',
  body?: unknown,
): Promise<T> {
  const value = await coreAdminHttp.request<unknown>(path, {
    method,
    headers: org ? { 'X-Education-Organization': org } : undefined,
    signal,
    ...(body === undefined ? {} : { body }),
  });
  if (!guard(value)) throw new ApiResponseError(path);
  return value;
}
const childPath = (child: ParentChild, section = '') =>
  `/education/parent/children/${encodeURIComponent(child.id)}${section ? `/${section}` : ''}`;
const familyPath = (studentId: string, personId?: string) =>
  `/education/students/${encodeURIComponent(studentId)}/guardians${personId ? `/${encodeURIComponent(personId)}` : ''}`;
const success = (v: unknown): v is Record<string, unknown> => record(v);

export const parentApi = {
  context: (signal?: AbortSignal) =>
    request('/education/parent/context', contextGuard, undefined, signal),
  activate: (code: string, signal?: AbortSignal) =>
    request('/education/parent/activate', success, undefined, signal, 'POST', { code }),
  register: (body: { code: string; email: string; password: string }, signal?: AbortSignal) =>
    request('/education/parent/register', success, undefined, signal, 'POST', body),
  home: (child: ParentChild, signal?: AbortSignal) =>
    request(childPath(child, 'home'), homeGuard, child.organizationId, signal),
  schedule: (child: ParentChild, from: string, to: string, signal?: AbortSignal) =>
    request(
      `${childPath(child, 'schedule')}?${new URLSearchParams({ from, to })}`,
      (v): v is { from: string; to: string; timezone: string; items: ParentLesson[] } =>
        record(v) &&
        text(v.from) &&
        text(v.to) &&
        text(v.timezone) &&
        arrayOf(v.items, lessonGuard),
      child.organizationId,
      signal,
    ),
  diary: (child: ParentChild, offset = 0, signal?: AbortSignal, from?: string, to?: string) =>
    request(
      `${childPath(child, 'diary')}?${new URLSearchParams({ offset: String(offset), ...(from && to ? { from, to } : {}) })}`,
      page(lessonGuard),
      child.organizationId,
      signal,
    ),
  homework: (child: ParentChild, offset = 0, signal?: AbortSignal) =>
    request(
      `${childPath(child, 'homework')}?offset=${offset}`,
      page(homeworkGuard),
      child.organizationId,
      signal,
    ),
  progress: (child: ParentChild, signal?: AbortSignal, from?: string, to?: string) =>
    request(
      `${childPath(child, 'progress')}${from && to ? `?${new URLSearchParams({ from, to })}` : ''}`,
      progressGuard,
      child.organizationId,
      signal,
    ),
  notifications: (child: ParentChild, offset = 0, signal?: AbortSignal) =>
    request(
      `${childPath(child, 'notifications')}?offset=${offset}`,
      page(notificationGuard),
      child.organizationId,
      signal,
    ),
  readNotification: (child: ParentChild, id: string, signal?: AbortSignal) =>
    request(
      `${childPath(child, 'notifications')}/${encodeURIComponent(id)}/read`,
      success,
      child.organizationId,
      signal,
      'POST',
      {},
    ),
  contacts: (child: ParentChild, signal?: AbortSignal) =>
    request(
      childPath(child, 'contacts'),
      (v): v is { items: EducationContact[] } => record(v) && arrayOf(v.items, contactGuard),
      child.organizationId,
      signal,
    ),
  threads: (child: ParentChild, offset = 0, signal?: AbortSignal) =>
    request(
      `${childPath(child, 'threads')}?offset=${offset}`,
      page(threadGuard),
      child.organizationId,
      signal,
    ),
  thread: (child: ParentChild, id: string, signal?: AbortSignal) =>
    request(
      childPath(child, `threads/${encodeURIComponent(id)}`),
      threadGuard,
      child.organizationId,
      signal,
    ),
  messages: (child: ParentChild, id: string, offset = 0, signal?: AbortSignal, before?: string) => {
    const params = new URLSearchParams({ offset: String(offset) });
    if (before) params.set('before', before);
    return request(
      `${childPath(child, `threads/${encodeURIComponent(id)}/messages`)}?${params.toString()}`,
      page(messageGuard),
      child.organizationId,
      signal,
    );
  },
  startThread: (child: ParentChild, recipientPersonId: string, subject: string, body: string, signal?: AbortSignal) =>
    request(
      childPath(child, 'threads'),
      threadGuard,
      child.organizationId,
      signal,
      'POST',
      { recipientPersonId, subject, body },
    ),
  sendMessage: (
    child: ParentChild,
    id: string,
    body: string | null,
    clientKey?: string,
    kind = 'text',
    mediaAssetId?: string | null,
    replyToId?: string | null,
    metadata?: Record<string, unknown>,
    signal?: AbortSignal,
  ) =>
    request(
      childPath(child, `threads/${encodeURIComponent(id)}/messages`),
      messageGuard,
      child.organizationId,
      signal,
      'POST',
      {
        body,
        ...(clientKey ? { clientKey } : {}),
        kind,
        ...(mediaAssetId ? { mediaAssetId } : {}),
        ...(replyToId ? { replyToId } : {}),
        ...(metadata ? { metadata } : {}),
      },
    ),
  toggleReaction: (
    child: ParentChild,
    id: string,
    messageId: string,
    emoji: string,
    signal?: AbortSignal,
  ) =>
    request(
      childPath(child, `threads/${encodeURIComponent(id)}/reactions`),
      (v): v is { reacted: boolean; reactions: unknown[] } =>
        typeof v === 'object' && v !== null && 'reacted' in v && 'reactions' in v,
      child.organizationId,
      signal,
      'POST',
      { messageId, emoji },
    ),
  readThread: (child: ParentChild, id: string, lastSeenMessageId: string, signal?: AbortSignal) =>
    request(
      childPath(child, `threads/${encodeURIComponent(id)}/read`),
      success,
      child.organizationId,
      signal,
      'POST',
      { lastSeenMessageId },
    ),
  guardians: (org: string, student: string, signal?: AbortSignal) =>
    request(familyPath(student), familyGuard, org, signal),
  history: (org: string, student: string, offset = 0, signal?: AbortSignal) =>
    request(`${familyPath(student)}/history?offset=${offset}`, page(historyGuard), org, signal),
  candidates: (org: string, query: string, signal?: AbortSignal) =>
    request(
      `/education/family/candidates?${new URLSearchParams({ q: query })}`,
      (v): v is { items: PersonChoice[] } => record(v) && arrayOf(v.items, named),
      org,
      signal,
    ),
  addGuardian: (
    org: string,
    student: string,
    personId: string,
    relationship: Relationship,
    signal?: AbortSignal,
  ) => request(familyPath(student), success, org, signal, 'POST', { personId, relationship }),
  relationship: (
    org: string,
    student: string,
    person: string,
    relationship: Relationship,
    signal?: AbortSignal,
  ) => request(familyPath(student, person), success, org, signal, 'PUT', { relationship }),
  familyAction: (
    org: string,
    student: string,
    person: string,
    action: 'confirm' | 'revoke' | 'invite/revoke',
    signal?: AbortSignal,
  ) => request(`${familyPath(student, person)}/${action}`, success, org, signal, 'POST', {}),
  invite: (org: string, student: string, person: string, signal?: AbortSignal) =>
    request(`${familyPath(student, person)}/invite`, invitationGuard, org, signal, 'POST', {}),
};
