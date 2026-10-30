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
