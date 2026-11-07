import { coreAdminHttp } from '@/shared/api/core/adminHttp';
import { ApiResponseError } from '@/shared/api/errors';
import type { Page } from '../api';

export interface DeviceClassroom { id: string; name: string; createdAt: string }
export interface DeviceSession { id: string; deviceId: string; studentId: string; studentPersonId: string; studentName: string; status: string; desiredStatus: string | null; startedAt: string }
export type DeviceStatus = 'OFFLINE' | 'AVAILABLE' | 'RESERVED' | 'PREPARING' | 'SYNCING_IN' | 'ACTIVE' | 'SYNCING_OUT' | 'CLEANUP' | 'RECOVERY_REQUIRED' | 'ERROR';
export interface Device { id: string; name: string; number: string; classroomId: string | null; classroomName: string | null; status: DeviceStatus; enrollmentState: 'pending' | 'confirmed' | 'revoked'; lastSeenAt: string | null; createdAt: string }
export interface DeviceDetail extends Device { sessions: DeviceSession[] }
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
const detailGuard: Guard<DeviceDetail> = (v): v is DeviceDetail => deviceGuard(v) && Array.isArray((v as unknown as Record<string, unknown>).sessions) && ((v as unknown as Record<string, unknown>).sessions as unknown[]).every(sessionGuard);
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
const pathId = (id: string): string => encodeURIComponent(id);

export const deviceApi = {
  classrooms: (org: string, signal?: AbortSignal) => get('/api/device-management/classrooms', org, (v): v is { items: DeviceClassroom[] } => record(v) && Array.isArray(v.items) && v.items.every(classroomGuard), signal),
  createClassroom: (org: string, name: string) => post('/api/device-management/classrooms', org, { name }, classroomGuard),
  devices: (org: string, offset = 0, signal?: AbortSignal) => get(`/api/device-management/devices?offset=${offset}`, org, pageGuard(deviceGuard), signal),
  device: (org: string, id: string, signal?: AbortSignal) => get(`/api/device-management/devices/${pathId(id)}`, org, detailGuard, signal),
  createDevice: (org: string, values: { classroomId?: string; name: string; number: string }) => post('/api/device-management/devices', org, values, deviceGuard),
  enroll: (org: string, id: string) => post(`/api/device-management/devices/${pathId(id)}/enroll`, org, {}, enrollmentGuard),
  revoke: (org: string, id: string) => post(`/api/device-management/devices/${pathId(id)}/revoke`, org, {}, (v): v is { id: string; revoked: boolean } => record(v) && uuid(v.id) && v.revoked === true),
  startSession: (org: string, id: string, studentId: string) => post(`/api/device-management/devices/${pathId(id)}/session`, org, { studentId }, sessionGuard),
  endSession: (org: string, id: string, sessionId: string) => post(`/api/device-management/devices/${pathId(id)}/session/end`, org, { sessionId }, (v): v is { sessionId: string; ending: boolean } => record(v) && uuid(v.sessionId) && v.ending === true),
  events: (org: string, id: string, offset = 0, signal?: AbortSignal) => get(`/api/device-management/devices/${pathId(id)}/events?offset=${offset}`, org, pageGuard(eventGuard), signal),
};
