import type {
  AccountInvitation,
  AccountInvitationList,
  AccountAnalytics,
  AccountUsage,
  AuditEvent,
  AuditEventPage,
  AutoRenewalDisabled,
  BillingProfileResult,
  CatalogReviewed,
  CheckoutResult,
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
  EffectiveEntitlements,
  EntitlementSubscription,
  InvitationAcceptResult,
  InvitationPreview,
  Invoice,
  InvoiceLine,
  LoginResult,
  MfaChallenge,
  MfaStatus,
  Payment,
  PaymentAttempt,
  PersonalDataExport,
  Plan,
  PlanCatalogResponse,
  PlanChange,
  PlanChangeCancelled,
  PlanChangeRequested,
  PlanPrice,
  Receipt,
  ReceiptItem,
  RecoveryCodesResult,
  Refund,
  RefundItem,
  RefundList,
  RefundSummary,
  RegisterResult,
  SavedPaymentMethod,
  SavedPaymentMethodList,
  SessionTokens,
  StatusResult,
  Subscription,
  SubscriptionCancelled,
  SubscriptionCreated,
  SubscriptionItem,
  SubscriptionRenewal,
  TotpSetup,
  UsageCounter,
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

export function isAcceptedStatus(value: unknown): value is StatusResult {
  return isRecord(value) && value.status === 'accepted';
}

export function isVerifiedStatus(value: unknown): value is StatusResult {
  return isRecord(value) && value.status === 'verified';
}

export function isPasswordChangedStatus(value: unknown): value is StatusResult {
  return isRecord(value) && value.status === 'password_changed';
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
    isNullableString(value.last_used_at) &&
    isString(value.expires_at) &&
    isNullableString(value.revoked_at) &&
    isNullableString(value.revoke_reason) &&
    isNullableString(value.user_agent) &&
    (value.authentication_method === 'password' ||
      value.authentication_method === 'password_totp' ||
      value.authentication_method === 'password_recovery') &&
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

export function isCatalogReviewed(value: unknown): value is CatalogReviewed {
  return isRecord(value) && value.onboarding_state === 'ready_for_subscription';
}

export function isBillingProfileResult(value: unknown): value is BillingProfileResult {
  return isRecord(value) && isString(value.id);
}

function isSubscriptionItem(value: unknown): value is SubscriptionItem {
  return isRecord(value)
    && isString(value.id)
    && isNumber(value.quantity)
    && isString(value.valid_from)
    && isNullableString(value.valid_until)
    && isRecord(value.plan) && isString(value.plan.id) && isString(value.plan.code)
    && isNumber(value.plan.version) && isString(value.plan.name)
    && isRecord(value.price) && isString(value.price.id) && isString(value.price.currency)
    && isNumber(value.price.amount_minor) && isString(value.price.billing_period);
}

function isSubscriptionRenewal(value: unknown): value is SubscriptionRenewal {
  return isRecord(value)
    && isString(value.invoice_id)
    && (value.invoice_status === 'issued' || value.invoice_status === 'paid')
    && isNullableString(value.payment_id)
    && (value.payment_status === null || ['pending', 'waiting_for_capture', 'succeeded', 'cancelled'].includes(String(value.payment_status)))
    && isString(value.service_period_start)
    && isString(value.service_period_end);
}

export function isSubscription(value: unknown): value is Subscription {
  return isRecord(value)
    && isString(value.id)
    && ['draft', 'active', 'cancelled', 'expired'].includes(String(value.status))
    && (value.billing_status === 'current' || value.billing_status === 'past_due')
    && isNullableString(value.past_due_since)
    && isNullableString(value.grace_until)
    && isBoolean(value.auto_renew_enabled)
    && isBoolean(value.renewal_required)
    && isString(value.commercial_account_id)
    && isString(value.billing_profile_id)
    && isNullableString(value.current_period_start)
    && isNullableString(value.current_period_end)
    && (value.status !== 'active' || (isString(value.current_period_start) && isString(value.current_period_end)))
    && isString(value.created_at)
    && isString(value.updated_at)
    && Array.isArray(value.items) && value.items.every(isSubscriptionItem)
    && (value.renewal === null || isSubscriptionRenewal(value.renewal));
}

export function isSubscriptionCreated(value: unknown): value is SubscriptionCreated {
  return isRecord(value) && isString(value.id) && value.status === 'draft';
}

export function isAutoRenewalDisabled(value: unknown): value is AutoRenewalDisabled {
  return isRecord(value) && isString(value.id) && value.auto_renew_enabled === false;
}

export function isSubscriptionCancelled(value: unknown): value is SubscriptionCancelled {
  return isRecord(value) && isString(value.id) && value.status === 'cancelled';
}

export function isPlanChangeRequested(value: unknown): value is PlanChangeRequested {
  return isRecord(value)
    && isString(value.id)
    && ['scheduled', 'payment_required', 'applied'].includes(String(value.status))
    && (value.change_kind === 'upgrade' || value.change_kind === 'downgrade')
    && isString(value.source_subscription_id)
    && isString(value.target_subscription_id)
    && isNullableString(value.invoice_id)
    && isNumber(value.amount_due_minor)
    && isString(value.currency);
}

export function isPlanChange(value: unknown): value is PlanChange {
  return isRecord(value)
    && isString(value.id)
    && isString(value.source_subscription_id)
    && isString(value.target_subscription_id)
    && isString(value.old_plan_price_id)
    && isString(value.new_plan_price_id)
    && isNumber(value.old_quantity)
    && isNumber(value.new_quantity)
    && (value.change_kind === 'upgrade' || value.change_kind === 'downgrade')
    && ['scheduled', 'payment_required', 'applied', 'cancelled', 'paid_unapplied', 'refunded'].includes(String(value.status))
    && (value.compensation_status === null || ['pending', 'recovery_required', 'succeeded'].includes(String(value.compensation_status)))
    && isNullableString(value.compensation_reason)
    && (value.compensation_origin === null || value.compensation_origin === 'provider' || value.compensation_origin === 'external')
    && isRecord(value.calculation)
    && isString(value.currency)
    && isNumber(value.proration_net_minor)
    && isNumber(value.amount_due_minor)
    && isString(value.period_start)
    && isString(value.period_end)
    && isNullableString(value.invoice_id)
    && isNullableString(value.applied_at)
    && isNullableString(value.invoice_status)
    && isNullableString(value.payment_id)
    && isNullableString(value.payment_status);
}

export function isPlanChangeCancelled(value: unknown): value is PlanChangeCancelled {
  return isRecord(value) && isString(value.id) && value.status === 'cancelled';
}

function isInvoiceLine(value: unknown): value is InvoiceLine {
  return isRecord(value)
    && isString(value.id)
    && isString(value.subscription_item_id)
    && isString(value.plan_price_id)
    && isString(value.plan_code)
    && isNumber(value.plan_version)
    && isString(value.plan_name)
    && isString(value.billing_period)
    && isString(value.description)
    && isNumber(value.quantity)
    && isNumber(value.unit_amount_minor)
    && isNumber(value.total_minor)
    && isString(value.created_at);
}

export function isInvoice(value: unknown): value is Invoice {
  return isRecord(value)
    && isString(value.id)
    && isString(value.subscription_id)
    && isString(value.billing_profile_id)
    && (value.billing_reason === 'subscription_create' || value.billing_reason === 'subscription_renewal')
    && isNullableString(value.service_period_start)
    && isNullableString(value.service_period_end)
    && ['issued', 'paid', 'void'].includes(String(value.status))
    && isString(value.currency)
    && isNumber(value.total_minor)
    && isNullableString(value.issued_at)
    && isNullableString(value.paid_at)
    && isNullableString(value.voided_at)
    && isString(value.created_at)
    && isRecord(value.bill_to)
    && isString(value.bill_to.legal_name)
    && isString(value.bill_to.country_code)
    && isNullableString(value.bill_to.contact_name)
    && isNullableString(value.bill_to.contact_email)
    && isNullableString(value.bill_to.tax_identifier)
    && isRecord(value.bill_to.address)
    && Array.isArray(value.lines) && value.lines.every(isInvoiceLine);
}

function isPaymentAttempt(value: unknown): value is PaymentAttempt {
  return isRecord(value)
    && isString(value.id)
    && isString(value.provider_account_id)
    && isString(value.method_type)
    && isNullableString(value.provider_payment_id)
    && isString(value.status)
    && isNullableString(value.failure_code)
    && isString(value.created_at)
    && isNullableString(value.completed_at);
}

export function isPayment(value: unknown): value is Payment {
  return isRecord(value)
    && isString(value.id)
    && isString(value.invoice_id)
    && ['pending', 'waiting_for_capture', 'succeeded', 'cancelled'].includes(String(value.status))
    && isString(value.currency)
    && isNumber(value.amount_minor)
    && isNumber(value.refunded_minor)
    && isNumber(value.refundable_minor)
    && isString(value.created_at)
    && isNullableString(value.succeeded_at)
    && isNullableString(value.cancelled_at)
    && Array.isArray(value.attempts) && value.attempts.every(isPaymentAttempt);
}

export function isCheckoutResult(value: unknown): value is CheckoutResult {
  return isRecord(value)
    && isString(value.payment_id)
    && isString(value.attempt_id)
    && value.provider === 'yookassa'
    && (value.method === 'sbp' || value.method === 'bank_card')
    && ['pending', 'waiting_for_capture', 'succeeded', 'cancelled'].includes(String(value.status))
    && (value.confirmation === null || (isRecord(value.confirmation) && value.confirmation.type === 'redirect' && isString(value.confirmation.url)));
}

export function isSavedPaymentMethod(value: unknown): value is SavedPaymentMethod {
  return isRecord(value)
    && isString(value.id)
    && isString(value.method_type)
    && (value.status === 'active' || value.status === 'disabled')
    && isNullableString(value.title)
    && isNullableString(value.card_brand)
    && isNullableString(value.last4)
    && (value.expiry_month === null || isNumber(value.expiry_month))
    && (value.expiry_year === null || isNumber(value.expiry_year))
    && isBoolean(value.is_default)
    && isString(value.created_at)
    && isNullableString(value.last_used_at)
    && isNullableString(value.disabled_at);
}

export function isSavedPaymentMethodList(value: unknown): value is SavedPaymentMethodList {
  return isRecord(value) && Array.isArray(value.items) && value.items.every(isSavedPaymentMethod);
}

function isReceiptItem(value: unknown): value is ReceiptItem {
  return isRecord(value)
    && isString(value.description)
    && isNumber(value.quantity)
    && isNumber(value.unit_amount_minor)
    && isNumber(value.total_minor)
    && isString(value.currency)
    && isNumber(value.vat_code)
    && isString(value.payment_subject)
    && isString(value.payment_mode);
}

export function isReceipt(value: unknown): value is Receipt {
  return isRecord(value)
    && isString(value.id)
    && isString(value.payment_id)
    && (value.type === 'payment' || value.type === 'refund')
    && isString(value.status)
    && isNullableString(value.external_receipt_id)
    && isNumber(value.amount_minor)
    && isString(value.currency)
    && isString(value.created_at)
    && isNullableString(value.completed_at)
    && Array.isArray(value.items) && value.items.every(isReceiptItem);
}

function isRefundSummary(value: unknown): value is RefundSummary {
  return isRecord(value)
    && isString(value.id)
    && isString(value.payment_id)
    && isNumber(value.amount_minor)
    && isString(value.currency)
    && isString(value.status)
    && isNullableString(value.provider_refund_id)
    && isString(value.created_at)
    && isNullableString(value.last_attempted_at)
    && isNullableString(value.completed_at);
}

function isRefundItem(value: unknown): value is RefundItem {
  return isRecord(value)
    && isString(value.invoice_line_id)
    && isString(value.description)
    && isNumber(value.quantity)
    && isNumber(value.unit_amount_minor)
    && isNumber(value.total_minor)
    && isString(value.currency)
    && isNumber(value.vat_code)
    && isString(value.payment_subject)
    && isString(value.payment_mode);
}

export function isRefund(value: unknown): value is Refund {
  if (!isRefundSummary(value)) {
    return false;
  }
  const detail = value as RefundSummary & { items?: unknown; receipt?: unknown };
  return Array.isArray(detail.items) && detail.items.every(isRefundItem)
    && (detail.receipt === null || (isRecord(detail.receipt) && isString(detail.receipt.id) && isString(detail.receipt.status)));
}

export function isRefundList(value: unknown): value is RefundList {
  return isRecord(value) && Array.isArray(value.items) && value.items.every(isRefundSummary);
}

function isEntitlementSubscription(value: unknown): value is EntitlementSubscription {
  return isRecord(value)
    && isString(value.id)
    && isString(value.current_period_start)
    && isString(value.current_period_end)
    && (value.billing_status === 'current' || value.billing_status === 'past_due')
    && isNullableString(value.past_due_since)
    && isNullableString(value.grace_until);
}

export function isEffectiveEntitlements(value: unknown): value is EffectiveEntitlements {
  return isRecord(value)
    && isString(value.commercial_account_id)
    && (value.subscription === null || isEntitlementSubscription(value.subscription))
    && isRecord(value.features);
}

function isUsageCounter(value: unknown): value is UsageCounter {
  return isRecord(value)
    && (value.mode === 'counter' || value.mode === 'gauge')
    && isNumber(value.used)
    && isNumber(value.limit)
    && isNumber(value.remaining)
    && isBoolean(value.exhausted);
}

export function isAccountUsage(value: unknown): value is AccountUsage {
  return isRecord(value)
    && isString(value.commercial_account_id)
    && (value.subscription === null || (isRecord(value.subscription) && isString(value.subscription.id)
      && isString(value.subscription.current_period_start) && isString(value.subscription.current_period_end)))
    && (value.period === null || (isRecord(value.period) && isString(value.period.id)
      && isString(value.period.starts_at) && isString(value.period.ends_at) && isString(value.period.status)))
    && isRecord(value.counters)
    && Object.values(value.counters).every(isUsageCounter);
}

export function isPersonalDataExport(value: unknown): value is PersonalDataExport {
  return isRecord(value)
    && isNumber(value.schema_version)
    && isString(value.subject_id)
    && isRecord(value.data);
}

function isAuditEvent(value: unknown): value is AuditEvent {
  return isRecord(value)
    && isString(value.id)
    && isString(value.occurred_at)
    && (value.actor_type === 'control_user' || value.actor_type === 'system')
    && isNullableString(value.actor_id)
    && isString(value.action)
    && isString(value.resource_type)
    && isString(value.resource_id)
    && isNullableString(value.correlation_id)
    && ['success', 'failure', 'denied'].includes(String(value.outcome))
    && isRecord(value.metadata);
}

export function isAuditEventPage(value: unknown): value is AuditEventPage {
  return isRecord(value)
    && Array.isArray(value.items) && value.items.every(isAuditEvent)
    && isNullableString(value.next_cursor);
}

export function isAccountAnalytics(value: unknown): value is AccountAnalytics {
  return isRecord(value)
    && isString(value.account_id)
    && isNumber(value.days)
    && isString(value.generated_at)
    && isRecord(value.members)
    && isNumber(value.members.total)
    && isRecord(value.members.by_role)
    && Object.values(value.members.by_role).every(isNumber)
    && isNumber(value.members.pending_invitations)
    && isRecord(value.billing)
    && isNullableString(value.billing.subscription_status)
    && isNullableString(value.billing.billing_status)
    && isNullableString(value.billing.current_period_start)
    && isNullableString(value.billing.current_period_end)
    && isNullableString(value.billing.currency)
    && isNumber(value.billing.mrr_minor)
    && isNumber(value.billing.paid_minor)
    && isNumber(value.billing.payments_count)
    && isNumber(value.billing.outstanding_minor)
    && isNumber(value.billing.invoices_count)
    && isRecord(value.audit)
    && isNumber(value.audit.events)
    && isRecord(value.audit.by_outcome)
    && Object.values(value.audit.by_outcome).every(isNumber)
    && Array.isArray(value.audit.by_day)
    && value.audit.by_day.every(day => isRecord(day) && isString(day.date) && isNumber(day.count))
    && Array.isArray(value.audit.by_action)
    && value.audit.by_action.every(action => isRecord(action) && isString(action.action) && isNumber(action.count))
    && isAccountUsage(value.usage);
}
