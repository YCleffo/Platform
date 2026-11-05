import { coreAdminHttp } from './adminHttp';
import { ApiResponseError, NetworkError } from '../errors';
export const privacyTypes = [
  'access',
  'export',
  'processing_information',
  'correction',
  'restriction',
  'processing_termination',
  'erasure',
  'consent_withdrawal',
] as const;
export type PrivacyRequestType = (typeof privacyTypes)[number];
export interface PrivacyDeadline {
  code: string;
  triggeredAt: string;
  calendarType: string;
  dueAt: string;
  extendedDueAt: string | null;
  effectiveDueAt: string;
  satisfiedAt: string | null;
  overdue: boolean;
}
export interface OwnPrivacyRequest {
  id: string;
  subjectPersonId: string;
  representationId: string | null;
  intakeChannel?: string | null;
  requestType: string;
  status: string;
  submittedAt: string;
  completedAt: string | null;
  resolutionCode: string | null;
  deadlines: PrivacyDeadline[];
}
export interface PrivacyRepresentation {
  id: string;
  subjectPersonId: string;
  subjectName: string | null;
  validUntil: string | null;
  scopes: string[];
}
export interface PrivacyDocument {
  schemaVersion: string;
  subjectPersonId: string;
  [key: string]: unknown;
}
export interface PrivacyResponse {
  raw: string;
  document: PrivacyDocument;
}
const object = (v: unknown): v is Record<string, unknown> =>
  typeof v === 'object' && v !== null && !Array.isArray(v);
const text = (v: unknown): v is string => typeof v === 'string' && v.length > 0;
const uuid = (v: unknown): v is string =>
  typeof v === 'string' && /^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(v);
const nullable = (v: unknown) => v === null || typeof v === 'string';
function deadline(v: unknown): boolean {
  return (
    object(v) &&
    [v.code, v.triggeredAt, v.calendarType, v.dueAt, v.effectiveDueAt].every(text) &&
    [v.extendedDueAt, v.satisfiedAt].every(nullable) &&
    typeof v.overdue === 'boolean'
  );
}
export function isOwnPrivacyRequest(v: unknown): v is OwnPrivacyRequest {
  return (
    object(v) &&
    uuid(v.id) &&
    uuid(v.subjectPersonId) &&
    (v.representationId === null || uuid(v.representationId)) &&
    (v.intakeChannel === undefined || v.intakeChannel === null || ['paper', 'email', 'signed_electronic_document'].includes(String(v.intakeChannel))) &&
    [v.requestType, v.status, v.submittedAt].every(text) &&
    [v.completedAt, v.resolutionCode].every(nullable) &&
    Array.isArray(v.deadlines) &&
    v.deadlines.every(deadline)
  );
}
function representation(v: unknown): v is PrivacyRepresentation {
  return (
    object(v) &&
    uuid(v.id) &&
    uuid(v.subjectPersonId) &&
    nullable(v.subjectName) &&
    nullable(v.validUntil) &&
    Array.isArray(v.scopes) &&
    v.scopes.every(text)
  );
}
function checked<T>(v: unknown, valid: (v: unknown) => boolean, path: string): T {
  if (!valid(v)) throw new ApiResponseError(path);
  return v as T;
}
// A stalled connection must release the form. Issuance is never replayed on timeout.
export async function bounded<T>(action: (signal: AbortSignal) => Promise<T>, parent?: AbortSignal, timeoutMs = 15_000): Promise<T> {
  const controller = new AbortController();
  const abort = () => controller.abort();
  parent?.addEventListener('abort', abort, { once: true });
  if (parent?.aborted) abort();
  let timedOut = false;
  const timer = setTimeout(() => { timedOut = true; abort(); }, timeoutMs);
  try {
    return await action(controller.signal);
  } catch (error) {
    if (timedOut) throw new NetworkError();
    throw error;
  } finally {
    clearTimeout(timer);
    parent?.removeEventListener('abort', abort);
  }
}
export const requestScope: Record<PrivacyRequestType, string> = {
  access: 'privacy.request.submit',
  processing_information: 'privacy.request.submit',
  export: 'privacy.export.request',
  correction: 'privacy.correction.request',
  restriction: 'privacy.processing_restriction.request',
  processing_termination: 'privacy.processing_termination.request',
  erasure: 'privacy.erasure.request',
  consent_withdrawal: 'privacy.consent.withdraw',
};
export function responseKind(row: OwnPrivacyRequest): 'data' | 'processing-information' | null {
  return row.requestType === 'access' || row.requestType === 'export'
    ? 'data'
    : row.requestType === 'processing_information'
      ? 'processing-information'
      : null;
}
export function responsePath(row: OwnPrivacyRequest, ownPersonId: string): string {
  const kind = responseKind(row);
  if (!kind) throw new ApiResponseError('/auth/me/privacy/requests');
  if (row.subjectPersonId === ownPersonId)
    return `/auth/me/privacy/requests/${encodeURIComponent(row.id)}/${kind}`;
  if (!row.representationId) throw new ApiResponseError('/auth/me/privacy/requests');
  return `/auth/me/privacy/representations/${encodeURIComponent(row.representationId)}/requests/${encodeURIComponent(row.id)}/${kind}`;
}
export function parsePrivacyResponse(raw: string, row: OwnPrivacyRequest): PrivacyResponse {
  const path = '/privacy/requests/response';
  let document: unknown;
  try { document = JSON.parse(raw); } catch { throw new ApiResponseError(path); }
  const data = responseKind(row) === 'data';
  const expected = data ? '1' : 'privacy.processing_information.v1';
  checked(document, v => object(v) && v.schemaVersion === expected && v.subjectPersonId === row.subjectPersonId
    && Array.isArray(v[data ? 'sections' : 'actualPersonalData'])
    && (data ? text(v.generatedAt) : Array.isArray(v.purposes)), path);
  return { raw, document: document as PrivacyDocument };
}
export const corePrivacy = {
  async list(offset = 0, limit = 26): Promise<OwnPrivacyRequest[]> {
    const path = `/auth/me/privacy/requests?${new URLSearchParams({ limit: String(limit), offset: String(offset) })}`;
    return checked(
      await bounded(signal => coreAdminHttp.get(path, signal)),
      (v) => Array.isArray(v) && v.every(isOwnPrivacyRequest),
      path,
    );
  },
  async get(id: string): Promise<OwnPrivacyRequest> {
    const path = `/auth/me/privacy/requests/${encodeURIComponent(id)}`;
    return checked(await bounded(signal => coreAdminHttp.get(path, signal)), isOwnPrivacyRequest, path);
  },
  async representations(): Promise<PrivacyRepresentation[]> {
    const path = '/auth/me/privacy/representations';
    return checked<{ items: PrivacyRepresentation[] }>(
      await bounded(signal => coreAdminHttp.get(path, signal)),
      (v) => object(v) && Array.isArray(v.items) && v.items.every(representation),
      path,
    ).items;
  },
  async submit(type: PrivacyRequestType, authority?: PrivacyRepresentation): Promise<string> {
    const path = authority
      ? `/auth/me/privacy/representations/${encodeURIComponent(authority.id)}/requests`
      : '/auth/me/privacy/requests';
    return checked<{ id: string }>(
      await bounded(signal => coreAdminHttp.post(
        path,
        authority
          ? { requestType: type, subjectPersonId: authority.subjectPersonId }
          : { requestType: type },
        signal,
      )),
      (v) => object(v) && uuid(v.id),
      path,
    ).id;
  },
  async response(
    row: OwnPrivacyRequest,
    ownPersonId: string,
    signal: AbortSignal,
  ): Promise<PrivacyResponse> {
    const path = responsePath(row, ownPersonId);
    const raw = await bounded(active => coreAdminHttp.document(path, active), signal, 60_000);
    return parsePrivacyResponse(raw, row);
  },
};
