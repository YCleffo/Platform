import type { HttpClient } from '../http';
import {
  isAccountInvitation,
  isAccountInvitationList,
  isInvitationAcceptResult,
  isInvitationPreview,
} from '../guards';
import type {
  AccountInvitation,
  AccountInvitationList,
  CreateInvitationPayload,
  InvitationAcceptResult,
  InvitationDeclineResult,
  InvitationPreview,
} from '@/shared/types';

export interface CommercialInvitationApi {
  list(accountId: string, limit?: number, offset?: number): Promise<AccountInvitationList>;
  create(accountId: string, payload: CreateInvitationPayload, idempotencyKey: string): Promise<AccountInvitation>;
  revoke(accountId: string, invitationId: string): Promise<void>;
  resend(accountId: string, invitationId: string): Promise<AccountInvitation>;
  preview(token: string): Promise<InvitationPreview>;
  accept(token: string): Promise<InvitationAcceptResult>;
  decline(token: string): Promise<InvitationDeclineResult>;
}

export function createCommercialInvitationApi(http: HttpClient): CommercialInvitationApi {
  return {
    list: (accountId, limit = 50, offset = 0) =>
      http.get<AccountInvitationList>(`/accounts/${accountId}/invitations`, {
        query: { limit, offset },
        validate: isAccountInvitationList,
      }),
    create: (accountId, payload, idempotencyKey) =>
      http.post<AccountInvitation>(`/accounts/${accountId}/invitations`, payload, {
        idempotencyKey,
        validate: isAccountInvitation,
      }),
    revoke: (accountId, invitationId) =>
      http.delete<void>(`/accounts/${accountId}/invitations/${invitationId}`),
    resend: (accountId, invitationId) =>
      http.post<AccountInvitation>(`/accounts/${accountId}/invitations/${invitationId}/resend`, undefined, {
        validate: isAccountInvitation,
      }),
    preview: (token) =>
      http.post<InvitationPreview>('/invitations/preview', { token }, { validate: isInvitationPreview }),
    accept: (token) =>
      http.post<InvitationAcceptResult>('/invitations/accept', { token }, { validate: isInvitationAcceptResult }),
    decline: (token) =>
      http.post<InvitationDeclineResult>('/invitations/decline', { token }),
  };
}
