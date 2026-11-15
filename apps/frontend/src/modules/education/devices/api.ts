import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiResponseError } from '@/shared/api/errors';
import type { Page } from '../api';

export interface DeviceClassroom { id: string; name: string; createdAt: string; archivedAt?: string | null }
export interface DeviceSession { id: string; deviceId: string; studentId: string; studentPersonId: string; studentName: string; status: string; desiredStatus: string | null; startedAt: string }
export type DeviceStatus = 'OFFLINE' | 'AVAILABLE' | 'RESERVED' | 'PREPARING' | 'SYNCING_IN' | 'ACTIVE' | 'SYNCING_OUT' | 'CLEANUP' | 'RECOVERY_REQUIRED' | 'ERROR';
export interface Device { id: string; name: string; number: string; classroomId: string | null; classroomName: string | null; status: DeviceStatus; enrollmentState: 'pending' | 'confirmed' | 'revoked'; lastSeenAt: string | null; createdAt: string }
export const deviceActions = ['assign', 'enroll', 'revoke', 'edit', 'end_session', 'cancel_assignment', 'retry_recovery'] as const;
export type DeviceAction = typeof deviceActions[number];
export interface DeviceCommand { id: string; kind: string; payload: Record<string, unknown>; status: string; createdAt: string; expiresAt: string; acknowledgedAt: string | null }
export interface SessionProgress { stage: string; completed: number; total: number }
export interface SessionError { code: string; message: string; at: string }
export interface RecoveryStatus { required: boolean; reason: string | null; retryAllowed: boolean }
export interface DeviceDetail extends Device { sessions: DeviceSession[]; stateVersion: number; availableActions: DeviceAction[]; commands: DeviceCommand[]; progress: SessionProgress | null; lastError: SessionError | null; recovery: RecoveryStatus | null; agentVersion?: string | null; online?: boolean; testAdapter?: boolean }
export const canDevice = (device: DeviceDetail | null, action: DeviceAction): boolean => !!device?.availableActions.includes(action);
export interface DeviceEvent { id: string; kind: string; payload: Record<string, unknown>; createdAt: string }
export interface DeviceEnrollment { id: string; deviceId: string; code: string; expiresAt: string }

type Guard<T> = (value: unknown) => value is T;
const record = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value);
const text = (value: unknown): value is string => typeof value === 'string';
const uuid = (value: unknown): value is string => text(value) && /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(value);
const instant = (value: unknown): value is string => text(value) && Number.isFinite(Date.parse(value)) && value.includes('T');
const integer = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
const nullableText = (value: unknown): value is string | null => value === null || text(value);
const deviceStates = ['OFFLINE', 'AVAILABLE', 'RESERVED', 'PREPARING', 'SYNCING_IN', 'ACTIVE', 'SYNCING_OUT', 'CLEANUP', 'RECOVERY_REQUIRED', 'ERROR'];
const sessionStates = ['AUTHORIZED', 'PREPARING', 'WINDOWS_PROFILE_READY', 'SYNCING_IN', 'ACTIVE', 'ENDING', 'SYNCING_OUT', 'STORAGE_CONFIRMED', 'CLEANUP', 'CLOSED', 'SYNC_PENDING', 'RECOVERY_REQUIRED', 'FAILED'];
const pageGuard = <T>(item: Guard<T>): Guard<Page<T>> => (v): v is Page<T> => record(v) && Array.isArray(v.items) && v.items.length <= 50 && v.items.every(item) && integer(v.limit) && integer(v.offset) && (v.nextOffset === null || integer(v.nextOffset));

const classroomGuard: Guard<DeviceClassroom> = (v): v is DeviceClassroom => record(v) && uuid(v.id) && text(v.name) && instant(v.createdAt);
const deviceGuard: Guard<Device> = (v): v is Device => record(v) && uuid(v.id) && text(v.name) && text(v.number) && (v.classroomId === null || uuid(v.classroomId)) && nullableText(v.classroomName) && deviceStates.includes(String(v.status)) && ['pending', 'confirmed', 'revoked'].includes(String(v.enrollmentState)) && (v.lastSeenAt === null || instant(v.lastSeenAt)) && instant(v.createdAt);
const sessionGuard: Guard<DeviceSession> = (v): v is DeviceSession => record(v) && uuid(v.id) && uuid(v.deviceId) && uuid(v.studentId) && uuid(v.studentPersonId) && text(v.studentName) && sessionStates.includes(String(v.status)) && (v.desiredStatus === null || sessionStates.includes(String(v.desiredStatus))) && instant(v.startedAt);
const commandGuard: Guard<DeviceCommand> = (v): v is DeviceCommand => record(v) && uuid(v.id) && text(v.kind) && record(v.payload) && text(v.status) && instant(v.createdAt) && instant(v.expiresAt) && (v.acknowledgedAt === null || instant(v.acknowledgedAt));
const progressGuard: Guard<SessionProgress> = (v): v is SessionProgress => record(v) && text(v.stage) && integer(v.completed) && integer(v.total) && v.completed <= v.total;
const errorGuard: Guard<SessionError> = (v): v is SessionError => record(v) && text(v.code) && text(v.message) && instant(v.at);
const recoveryGuard: Guard<RecoveryStatus> = (v): v is RecoveryStatus => record(v) && typeof v.required === 'boolean' && nullableText(v.reason) && typeof v.retryAllowed === 'boolean';
const detailGuard: Guard<DeviceDetail> = (v): v is DeviceDetail => {
  if (!deviceGuard(v) || !record(v)) return false;
  return Array.isArray(v.sessions) && v.sessions.every(sessionGuard) && integer(v.stateVersion)
    && Array.isArray(v.availableActions) && v.availableActions.every(a => deviceActions.includes(a as DeviceAction))
    && Array.isArray(v.commands) && v.commands.every(commandGuard)
    && (v.progress === null || progressGuard(v.progress)) && (v.lastError === null || errorGuard(v.lastError))
    && (v.recovery === null || recoveryGuard(v.recovery)) && (v.agentVersion === undefined || nullableText(v.agentVersion))
    && (v.testAdapter === undefined || typeof v.testAdapter === 'boolean');
};
const enrollmentGuard: Guard<DeviceEnrollment> = (v): v is DeviceEnrollment => record(v) && uuid(v.id) && uuid(v.deviceId) && text(v.code) && instant(v.expiresAt);
const eventGuard: Guard<DeviceEvent> = (v): v is DeviceEvent => record(v) && uuid(v.id) && text(v.kind) && record(v.payload) && instant(v.createdAt);

async function get<T>(path: string, org: string, guard: Guard<T>, signal?: AbortSignal): Promise<T> {
  const payload = await coreAdminHttp.request<unknown>(path, { method: 'GET', headers: { 'X-Education-Organization': org }, signal });
  if (guard(payload)) return payload;
  throw new ApiResponseError(path);
}
async function post<T>(path: string, org: string, body: unknown, guard: Guard<T>): Promise<T> {
  const payload = await coreAdminHttp.request<unknown>(path, { method: 'POST', headers: { 'X-Education-Organization': org }, body: JSON.stringify(body) });
  if (guard(payload)) return payload;
  throw new ApiResponseError(path);
}
async function put<T>(path: string, org: string, body: unknown, guard: Guard<T>): Promise<T> {
  const payload = await coreAdminHttp.request<unknown>(path, { method: 'PUT', headers: { 'X-Education-Organization': org }, body: JSON.stringify(body) });
  if (guard(payload)) return payload;
  throw new ApiResponseError(path);
}
const pathId = (id: string): string => encodeURIComponent(id);

export const deviceApi = {
  classrooms: (org: string, signal?: AbortSignal, offset = 0, search = '', archived = false) => {
    const params = new URLSearchParams({ offset: String(offset) });
    if (search) params.set('search', search);
    if (archived) params.set('archived', '1');
    return get(`/api/device-management/classrooms?${params}`, org, pageGuard(classroomGuard), signal);
  },
  allClassrooms: async (org: string, signal?: AbortSignal): Promise<{ items: DeviceClassroom[] }> => {
    const items: DeviceClassroom[] = []; let offset = 0;
    do {
      const page = await deviceApi.classrooms(org, signal, offset);
      items.push(...page.items);
      if (page.nextOffset === null) return { items };
      if (page.nextOffset <= offset) throw new ApiResponseError('/api/device-management/classrooms');
      offset = page.nextOffset;
    } while (!signal?.aborted);
    throw new DOMException('Aborted', 'AbortError');
  },
  createClassroom: (org: string, name: string) => post('/api/device-management/classrooms', org, { name }, classroomGuard),
  updateClassroom: (org: string, id: string, name: string) => put(`/api/device-management/classrooms/${pathId(id)}`, org, { name }, classroomGuard),
  restoreClassroom: (org: string, id: string) => post(`/api/device-management/classrooms/${pathId(id)}/restore`, org, {}, classroomGuard),
  archiveClassroom: (org: string, id: string) => post(`/api/device-management/classrooms/${pathId(id)}/archive`, org, {}, classroomGuard),
  devices: (org: string, offset = 0, classroomId?: string, status?: string, search?: string, signal?: AbortSignal) => {
    const params = new URLSearchParams({ offset: String(offset) });
    if (classroomId) params.set('classroomId', classroomId);
    if (status) params.set('status', status);
    if (search) params.set('search', search);
    return get(`/api/device-management/devices?${params.toString()}`, org, pageGuard(deviceGuard), signal);
  },
  device: (org: string, id: string, signal?: AbortSignal) => get(`/api/device-management/devices/${pathId(id)}`, org, detailGuard, signal),
  createDevice: (org: string, values: { classroomId?: string | null; name: string; number: string }) => post('/api/device-management/devices', org, values, deviceGuard),
  updateDevice: (org: string, id: string, values: { classroomId?: string | null; name: string; number: string }) => put(`/api/device-management/devices/${pathId(id)}`, org, values, deviceGuard),
  enroll: (org: string, id: string) => post(`/api/device-management/devices/${pathId(id)}/enroll`, org, {}, enrollmentGuard),
  revoke: (org: string, id: string) => post(`/api/device-management/devices/${pathId(id)}/revoke`, org, {}, (v): v is { id: string; revoked: boolean } => record(v) && uuid(v.id) && v.revoked === true),
  startSession: (org: string, id: string, studentId: string, stateVersion?: number) => post(`/api/device-management/devices/${pathId(id)}/session`, org, { studentId, stateVersion }, sessionGuard),
  endSession: (org: string, id: string, sessionId: string) => post(`/api/device-management/devices/${pathId(id)}/session/end`, org, { sessionId }, (v): v is { sessionId: string; ending: boolean } => record(v) && uuid(v.sessionId) && v.ending === true),
  cancelAssignment: (org: string, id: string, sessionId: string, stateVersion: number) => post(`/api/device-management/devices/${pathId(id)}/session/cancel`, org, { sessionId, stateVersion }, (v): v is { sessionId: string; cancelled: boolean } => record(v) && uuid(v.sessionId) && v.cancelled === true),
  retryRecovery: (org: string, id: string, stateVersion: number) => post(`/api/device-management/devices/${pathId(id)}/recovery/retry`, org, { stateVersion }, (v): v is { sessionId: string; retrying: boolean } => record(v) && uuid(v.sessionId) && v.retrying === true),
  sessions: (org: string, id: string, signal?: AbortSignal, offset = 0) => get(`/api/device-management/devices/${pathId(id)}/sessions?offset=${offset}`, org, pageGuard(sessionGuard), signal),
  events: (org: string, id: string, offset = 0, signal?: AbortSignal) => get(`/api/device-management/devices/${pathId(id)}/events?offset=${offset}`, org, pageGuard(eventGuard), signal),
};
