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
  last_used_at: string | null;
  expires_at: string;
  revoked_at: string | null;
  revoke_reason: string | null;
  user_agent: string | null;
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

export interface CatalogReviewed {
  onboarding_state: 'ready_for_subscription';
}

export interface BillingAddress {
  [key: string]: string;
}

export interface BillingProfilePayload {
  legal_name: string;
  country_code: string;
  contact_name?: string | null;
  contact_email?: string | null;
  tax_identifier?: string | null;
  address?: BillingAddress;
}

export interface BillingProfileResult {
  id: string;
}

export interface SubscriptionPrice {
  id: string;
  currency: string;
  amount_minor: number;
  billing_period: string;
}

export interface SubscriptionPlan {
  id: string;
  code: string;
  version: number;
  name: string;
}

export interface SubscriptionItem {
  id: string;
  quantity: number;
  valid_from: string;
  valid_until: string | null;
  plan: SubscriptionPlan;
  price: SubscriptionPrice;
}

export interface SubscriptionRenewal {
  invoice_id: string;
  invoice_status: 'issued' | 'paid';
  payment_id: string | null;
  payment_status: 'pending' | 'waiting_for_capture' | 'succeeded' | 'cancelled' | null;
  service_period_start: string;
  service_period_end: string;
}

export interface Subscription {
  id: string;
  status: 'draft' | 'active' | 'cancelled' | 'expired';
  billing_status: 'current' | 'past_due';
  past_due_since: string | null;
  grace_until: string | null;
  auto_renew_enabled: boolean;
  renewal_required: boolean;
  commercial_account_id: string;
  billing_profile_id: string;
  current_period_start: string;
  current_period_end: string;
  created_at: string;
  updated_at: string;
  items: SubscriptionItem[];
  renewal: SubscriptionRenewal | null;
}

export interface SubscriptionCreated {
  id: string;
  status: 'draft';
}

export interface AutoRenewalDisabled {
  id: string;
  auto_renew_enabled: false;
}

export interface SubscriptionCancelled {
  id: string;
  status: 'cancelled';
}

export interface PlanChangeRequested {
  id: string;
  status: 'scheduled' | 'payment_required' | 'applied';
  change_kind: 'upgrade' | 'downgrade';
  source_subscription_id: string;
  target_subscription_id: string;
  invoice_id: string | null;
  amount_due_minor: number;
  currency: string;
}

export interface PlanChange {
  id: string;
  source_subscription_id: string;
  target_subscription_id: string;
  old_plan_price_id: string;
  new_plan_price_id: string;
  old_quantity: number;
  new_quantity: number;
  change_kind: 'upgrade' | 'downgrade';
  status: 'scheduled' | 'payment_required' | 'applied' | 'cancelled' | 'paid_unapplied' | 'refunded';
  compensation_status: 'pending' | 'recovery_required' | 'succeeded' | null;
  compensation_reason: string | null;
  compensation_origin: 'provider' | 'external' | null;
  calculation: unknown;
  currency: string;
  proration_net_minor: number;
  amount_due_minor: number;
  period_start: string;
  period_end: string;
  invoice_id: string | null;
  applied_at: string | null;
  invoice_status: string | null;
  payment_id: string | null;
  payment_status: string | null;
}

export interface PlanChangeCancelled {
  id: string;
  status: 'cancelled';
}

export interface InvoiceLine {
  id: string;
  subscription_item_id: string;
  plan_price_id: string;
  plan_code: string;
  plan_version: number;
  plan_name: string;
  billing_period: string;
  description: string;
  quantity: number;
  unit_amount_minor: number;
  total_minor: number;
  created_at: string;
}

export interface InvoiceBillTo {
  legal_name: string;
  country_code: string;
  contact_name: string | null;
  contact_email: string | null;
  tax_identifier: string | null;
  address: Record<string, unknown>;
}

export interface Invoice {
  id: string;
  subscription_id: string;
  billing_profile_id: string;
  billing_reason: 'subscription_create' | 'subscription_renewal';
  service_period_start: string;
  service_period_end: string;
  status: 'issued' | 'paid' | 'void';
  currency: string;
  total_minor: number;
  issued_at: string | null;
  paid_at: string | null;
  voided_at: string | null;
  created_at: string;
  bill_to: InvoiceBillTo;
  lines: InvoiceLine[];
}

export interface PaymentAttempt {
  id: string;
  provider_account_id: string;
  method_type: string;
  provider_payment_id: string | null;
  status: string;
  failure_code: string | null;
  created_at: string;
  completed_at: string | null;
}

export interface Payment {
  id: string;
  invoice_id: string;
  status: 'pending' | 'waiting_for_capture' | 'succeeded' | 'cancelled';
  currency: string;
  amount_minor: number;
  refunded_minor: number;
  refundable_minor: number;
  created_at: string;
  succeeded_at: string | null;
  cancelled_at: string | null;
  attempts: PaymentAttempt[];
}

export interface CheckoutResult {
  payment_id: string;
  attempt_id: string;
  provider: 'yookassa';
  method: 'sbp' | 'bank_card';
  status: 'pending' | 'waiting_for_capture' | 'succeeded' | 'cancelled';
  confirmation: { type: 'redirect'; url: string } | null;
}

export interface SavedPaymentMethod {
  id: string;
  method_type: string;
  status: 'active' | 'disabled';
  title: string;
  card_brand: string | null;
  last4: string | null;
  expiry_month: number | null;
  expiry_year: number | null;
  is_default: boolean;
  created_at: string;
  last_used_at: string | null;
  disabled_at: string | null;
}

export interface SavedPaymentMethodList {
  items: SavedPaymentMethod[];
}

export interface ReceiptItem {
  description: string;
  quantity: number;
  unit_amount_minor: number;
  total_minor: number;
  currency: string;
  vat_code: number;
  payment_subject: string;
  payment_mode: string;
}

export interface Receipt {
  id: string;
  payment_id: string;
  type: 'payment' | 'refund';
  status: 'pending' | 'succeeded' | 'canceled';
  external_receipt_id: string | null;
  amount_minor: number;
  currency: string;
  created_at: string;
  completed_at: string | null;
  items: ReceiptItem[];
}

export interface RefundItemInput {
  invoice_line_id: string;
  quantity: number;
  unit_amount_minor: number;
}

export interface RefundItem extends RefundItemInput {
  description: string;
  total_minor: number;
  currency: string;
  vat_code: number;
  payment_subject: string;
  payment_mode: string;
}

export interface RefundSummary {
  id: string;
  payment_id: string;
  amount_minor: number;
  currency: string;
  status: 'pending' | 'succeeded' | 'canceled';
  provider_refund_id: string | null;
  created_at: string;
  last_attempted_at: string | null;
  completed_at: string | null;
}

export interface RefundReceipt {
  id: string;
  status: string;
  external_receipt_id: string | null;
  created_at: string;
  completed_at: string | null;
}

export interface Refund extends RefundSummary {
  items: RefundItem[];
  receipt: RefundReceipt | null;
}

export interface RefundList {
  items: RefundSummary[];
}

export interface EntitlementSubscription {
  id: string;
  current_period_start: string;
  current_period_end: string;
  billing_status: 'current' | 'past_due';
  past_due_since: string | null;
  grace_until: string | null;
}

export interface EffectiveEntitlements {
  commercial_account_id: string;
  subscription: EntitlementSubscription | null;
  features: Record<string, unknown>;
}

export interface UsageCounter {
  mode: 'counter' | 'gauge';
  used: number;
  limit: number;
  remaining: number;
  exhausted: boolean;
}

export interface UsageSubscription {
  id: string;
  current_period_start: string;
  current_period_end: string;
}

export interface UsagePeriod {
  id: string;
  starts_at: string;
  ends_at: string;
  status: string;
}

export interface AccountUsage {
  commercial_account_id: string;
  subscription: UsageSubscription | null;
  period: UsagePeriod | null;
  counters: Record<string, UsageCounter>;
}

export interface PersonalDataExport {
  schema_version: number;
  subject_id: string;
  data: Record<string, unknown>;
}

export interface AuditEvent {
  id: string;
  occurred_at: string;
  actor_type: 'control_user' | 'system';
  actor_id: string | null;
  action: string;
  resource_type: string;
  resource_id: string;
  correlation_id: string | null;
  outcome: 'success' | 'failure' | 'denied';
  metadata: Record<string, unknown>;
}

export interface AuditEventPage {
  items: AuditEvent[];
  next_cursor: string | null;
}

export interface AccountAnalyticsBilling {
  subscription_status: string | null;
  billing_status: string | null;
  current_period_start: string | null;
  current_period_end: string | null;
  currency: string | null;
  mrr_minor: number;
  paid_minor: number;
  payments_count: number;
  outstanding_minor: number;
  invoices_count: number;
}

export interface AccountAnalyticsAuditDay {
  date: string;
  count: number;
}

export interface AccountAnalyticsAuditAction {
  action: string;
  count: number;
}

export interface AccountAnalytics {
  account_id: string;
  days: number;
  generated_at: string;
  members: {
    total: number;
    by_role: Record<string, number>;
    pending_invitations: number;
  };
  billing: AccountAnalyticsBilling;
  audit: {
    events: number;
    by_outcome: Record<string, number>;
    by_day: AccountAnalyticsAuditDay[];
    by_action: AccountAnalyticsAuditAction[];
  };
  usage: AccountUsage;
}
