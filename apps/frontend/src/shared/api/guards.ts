import type {
  AccountInvitation,
  AccountInvitationList,
  CommercialAccountDetail,
  CommercialAccountList,
  CommercialAccountPermissions,
  CommercialAccountSummary,
  CommercialMembership,
  CommercialMembershipList,
  ControlSession,
  ControlSessionList,
  CreateAccountResult,
  CurrentUser,
  InvitationAcceptResult,
  InvitationPreview,
  LoginResult,
  MfaChallenge,
  MfaStatus,
  Plan,
  PlanCatalogResponse,
  PlanPrice,
  RecoveryCodesResult,
  RegisterResult,
  SessionTokens,
  StatusResult,
  TotpSetup,
} from '@/shared/types';

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function isString(value: unknown): value is string {
  return typeof value === 'string';
}

export function isNumber(value: unknown): value is number {
  return typeof value === 'number' && Number.isFinite(value);
}

export function isBoolean(value: unknown): value is boolean {
  return typeof value === 'boolean';
}

export function isNullableString(value: unknown): value is string | null {
  return value === null || typeof value === 'string';
}

export function isSessionTokens(value: unknown): value is SessionTokens {
  if (!isRecord(value) || !isString(value.access_token) || !isString(value.refresh_token)) {
    return false;
  }
  if (value.access_expires_in !== undefined && !isNumber(value.access_expires_in)) {
    return false;
  }
  if (value.session_expires_in !== undefined && !isNumber(value.session_expires_in)) {
    return false;
  }
  return true;
}

export function isMfaChallenge(value: unknown): value is MfaChallenge {
  return (
    isRecord(value) &&
    value.status === 'mfa_required' &&
    isString(value.challenge_token) &&
    isNumber(value.expires_in)
  );
}

export function isLoginResult(value: unknown): value is LoginResult {
  return isSessionTokens(value) || isMfaChallenge(value);
}

export function isRegisterResult(value: unknown): value is RegisterResult {
  return isRecord(value) && value.status === 'verification_required' && isString(value.user_id);
}

export function isStatusResult(value: unknown): value is StatusResult {
  return isRecord(value) && isString(value.status);
}

export function isCurrentUser(value: unknown): value is CurrentUser {
  return (
    isRecord(value) &&
    isString(value.id) &&
    isString(value.email) &&
    isString(value.status) &&
    isNullableString(value.email_verified_at) &&
    isNullableString(value.last_login_at) &&
    isString(value.created_at) &&
    isString(value.updated_at)
  );
}

export function isCommercialAccountSummary(value: unknown): value is CommercialAccountSummary {
  return (
    isRecord(value) &&
    isString(value.id) &&
    isString(value.code) &&
    isString(value.display_name) &&
    isNullableString(value.legal_name) &&
    isString(value.status) &&
    isString(value.onboarding_state) &&
    isString(value.role) &&
    isString(value.created_at) &&
    isString(value.updated_at)
  );
}

export function isCommercialAccountList(value: unknown): value is CommercialAccountList {
  if (!isRecord(value) || !Array.isArray(value.items)) {
    return false;
  }
  return (
    value.items.every(isCommercialAccountSummary) &&
    isNumber(value.total) &&
    isNumber(value.limit) &&
    isNumber(value.offset)
  );
}

export function isCommercialAccountPermissions(value: unknown): value is CommercialAccountPermissions {
  return (
    isRecord(value) &&
    isBoolean(value.read_account) &&
    isBoolean(value.view_members) &&
    isBoolean(value.manage_members) &&
    isBoolean(value.manage_ownership) &&
    isBoolean(value.manage_billing) &&
    isBoolean(value.manage_technical)
  );
}

export function isCommercialAccountDetail(value: unknown): value is CommercialAccountDetail {
  return (
    isCommercialAccountSummary(value) &&
    isCommercialAccountPermissions((value as { permissions?: unknown }).permissions)
  );
}

export function isCommercialMembership(value: unknown): value is CommercialMembership {
  return (
    isRecord(value) &&
    isString(value.id) &&
    isString(value.user_id) &&
    isString(value.email) &&
    isString(value.role) &&
    isString(value.created_at)
  );
}

export function isCommercialMembershipList(value: unknown): value is CommercialMembershipList {
  return (
    isRecord(value) &&
    Array.isArray(value.items) &&
    value.items.every(isCommercialMembership) &&
    isNumber(value.total) &&
    isNumber(value.limit) &&
    isNumber(value.offset)
  );
}

export function isMfaStatus(value: unknown): value is MfaStatus {
  return (
    isRecord(value) &&
    isBoolean(value.enabled) &&
    (value.factor_id === null || isString(value.factor_id)) &&
    isNumber(value.recovery_codes_remaining)
  );
}

export function isTotpSetup(value: unknown): value is TotpSetup {
  return (
    isRecord(value) &&
    isString(value.factor_id) &&
    isString(value.secret) &&
    isString(value.otpauth_uri)
  );
}

export function isRecoveryCodesResult(value: unknown): value is RecoveryCodesResult {
  return isRecord(value) && Array.isArray(value.recovery_codes) && value.recovery_codes.every(isString);
}

export function isControlSession(value: unknown): value is ControlSession {
  return (
    isRecord(value) &&
    isString(value.id) &&
    isString(value.issued_at) &&
    isString(value.last_used_at) &&
    isString(value.expires_at) &&
    isNullableString(value.revoked_at) &&
    isNullableString(value.revoke_reason) &&
    isString(value.user_agent) &&
    isString(value.authentication_method) &&
    isString(value.primary_authenticated_at) &&
    isNullableString(value.mfa_authenticated_at) &&
    isBoolean(value.current)
  );
}

export function isControlSessionList(value: unknown): value is ControlSessionList {
  return isRecord(value) && Array.isArray(value.items) && value.items.every(isControlSession);
}

export function isAccountInvitation(value: unknown): value is AccountInvitation {
  return (
    isRecord(value) &&
    isString(value.id) &&
    isString(value.email) &&
    isString(value.role) &&
    isString(value.status) &&
    isString(value.created_at) &&
    isString(value.expires_at) &&
    isString(value.invited_by)
  );
}

export function isAccountInvitationList(value: unknown): value is AccountInvitationList {
  return (
    isRecord(value) &&
    Array.isArray(value.items) &&
    value.items.every(isAccountInvitation) &&
    isNumber(value.total) &&
    isNumber(value.limit) &&
    isNumber(value.offset)
  );
}

export function isInvitationPreview(value: unknown): value is InvitationPreview {
  return (
    isRecord(value) &&
    isString(value.status) &&
    isString(value.organization_name) &&
    isString(value.role) &&
    isString(value.expires_at) &&
    isString(value.email)
  );
}

export function isInvitationAcceptResult(value: unknown): value is InvitationAcceptResult {
  return isRecord(value) && isString(value.account_id) && isString(value.role) && value.status === 'accepted';
}

export function isCreateAccountResult(value: unknown): value is CreateAccountResult {
  return isRecord(value) && isString(value.id);
}

function isPlanPrice(value: unknown): value is PlanPrice {
  return (
    isRecord(value) &&
    isString(value.id) &&
    isString(value.currency) &&
    isNumber(value.amount_minor) &&
    isString(value.billing_period)
  );
}

function isPlan(value: unknown): value is Plan {
  return (
    isRecord(value) &&
    isString(value.id) &&
    isString(value.code) &&
    isNumber(value.version) &&
    isString(value.name) &&
    Array.isArray(value.prices) &&
    value.prices.every(isPlanPrice) &&
    isRecord(value.features)
  );
}

export function isPlanCatalogResponse(value: unknown): value is PlanCatalogResponse {
  return isRecord(value) && Array.isArray(value.plans) && value.plans.every(isPlan);
}
