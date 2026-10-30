export interface ApiErrorEnvelope {
  error: {
    code: string;
    message: string;
  };
}

export interface SessionTokens {
  access_token: string;
  refresh_token: string;
  access_expires_in?: number;
  session_expires_in?: number;
}

export interface MfaChallenge {
  status: 'mfa_required';
  challenge_token: string;
  expires_in: number;
}

export type LoginResult = SessionTokens | MfaChallenge;

export interface RegisterResult {
  status: 'verification_required';
  user_id: string;
}

export interface StatusResult {
  status: string;
}

export type ControlUserStatus = 'pending_verification' | 'active' | 'suspended' | 'disabled';

export interface CurrentUser {
  id: string;
  email: string;
  status: ControlUserStatus;
  email_verified_at: string | null;
  last_login_at: string | null;
  created_at: string;
  updated_at: string;
}

export type AccountRole = 'owner' | 'admin' | 'billing' | 'technical';
export type AccountStatus = 'draft' | 'active' | 'suspended' | 'closed';
export type OnboardingState = 'profile_required' | 'catalog_selection_pending' | 'ready_for_subscription';

export interface CommercialAccountSummary {
  id: string;
  code: string;
  display_name: string;
  legal_name: string | null;
  status: AccountStatus;
  onboarding_state: OnboardingState;
  role: AccountRole;
  created_at: string;
  updated_at: string;
}

export interface CommercialAccountList {
  items: CommercialAccountSummary[];
  total: number;
  limit: number;
  offset: number;
}

export interface CommercialAccountPermissions {
  read_account: boolean;
  view_members: boolean;
  manage_members: boolean;
  manage_ownership: boolean;
  manage_billing: boolean;
  manage_technical: boolean;
}

export interface CommercialAccountDetail extends CommercialAccountSummary {
  permissions: CommercialAccountPermissions;
}

export interface CommercialMembership {
  id: string;
  user_id: string;
  email: string;
  role: AccountRole;
  created_at: string;
}

export interface CommercialMembershipList {
  items: CommercialMembership[];
  total: number;
  limit: number;
  offset: number;
}

export interface MfaStatus {
  enabled: boolean;
  factor_id: string | null;
  recovery_codes_remaining: number;
}

export interface TotpSetup {
  factor_id: string;
  secret: string;
  otpauth_uri: string;
}

export interface RecoveryCodesResult {
  recovery_codes: string[];
}

export interface ReauthenticateInput {
  password: string;
  code?: string;
  recoveryCode?: string;
}

export type AuthenticationMethod = 'password' | 'password_totp' | 'password_recovery';

export interface ControlSession {
  id: string;
  issued_at: string;
  last_used_at: string;
  expires_at: string;
  revoked_at: string | null;
  revoke_reason: string | null;
  user_agent: string;
  authentication_method: AuthenticationMethod;
  primary_authenticated_at: string;
  mfa_authenticated_at: string | null;
  current: boolean;
}

export interface ControlSessionList {
  items: ControlSession[];
}

export type InvitationStatus = 'pending' | 'accepted' | 'declined' | 'revoked' | 'expired';
export type InvitationRole = 'admin' | 'billing' | 'technical';

export interface AccountInvitation {
  id: string;
  email: string;
  role: InvitationRole;
  status: InvitationStatus;
  created_at: string;
  expires_at: string;
  accepted_at?: string | null;
  declined_at?: string | null;
  revoked_at?: string | null;
  invited_by: string;
  inviter_email?: string;
}

export interface AccountInvitationList {
  items: AccountInvitation[];
  total: number;
  limit: number;
  offset: number;
}

export interface InvitationPreview {
  status: InvitationStatus;
  organization_name: string;
  role: InvitationRole;
  expires_at: string;
  email: string;
}

export interface CreateInvitationPayload {
  email: string;
  role: InvitationRole;
}

export interface InvitationAcceptResult {
  account_id: string;
  role: AccountRole;
  status: 'accepted';
}

export interface InvitationDeclineResult {
  status: 'declined';
}



export interface PlanPrice {
  id: string;
  currency: string;
  amount_minor: number;
  billing_period: string;
}

export interface Plan {
  id: string;
  code: string;
  version: number;
  name: string;
  prices: PlanPrice[];
  features: Record<string, unknown>;
}

export interface PlanCatalogResponse {
  plans: Plan[];
}

export interface CreateAccountPayload {
  code: string;
  display_name: string;
  legal_name?: string | null;
}

export interface CreateAccountResult {
  id: string;
}
