import type { HttpClient } from '@/shared/api/http';
import type {
  AutoRenewalDisabled,
  BillingProfilePayload,
  BillingProfileResult,
  CheckoutResult,
  EffectiveEntitlements,
  Invoice,
  Payment,
  PlanChange,
  PlanChangeCancelled,
  PlanChangeRequested,
  Receipt,
  Refund,
  RefundItemInput,
  RefundList,
  SavedPaymentMethod,
  SavedPaymentMethodList,
  Subscription,
  SubscriptionCancelled,
  SubscriptionCreated,
} from '@/shared/types';
import {
  isAutoRenewalDisabled,
  isBillingProfileResult,
  isCheckoutResult,
  isEffectiveEntitlements,
  isInvoice,
  isPayment,
  isPlanChange,
  isPlanChangeCancelled,
  isPlanChangeRequested,
  isReceipt,
  isRefund,
  isRefundList,
  isSavedPaymentMethod,
  isSavedPaymentMethodList,
  isSubscription,
  isSubscriptionCancelled,
  isSubscriptionCreated,
} from '../guards';

export interface CommercialBillingApi {
  saveBillingProfile(accountId: string, payload: BillingProfilePayload): Promise<BillingProfileResult>;
  entitlements(accountId: string): Promise<EffectiveEntitlements>;
  createSubscription(accountId: string, planPriceId: string, quantity: number, idempotencyKey: string): Promise<SubscriptionCreated>;
  subscription(accountId: string, subscriptionId: string): Promise<Subscription>;
  cancelSubscription(accountId: string, subscriptionId: string, idempotencyKey: string): Promise<SubscriptionCancelled>;
  disableAutoRenewal(accountId: string, subscriptionId: string, idempotencyKey: string): Promise<AutoRenewalDisabled>;
  issueInvoice(accountId: string, subscriptionId: string, idempotencyKey: string): Promise<Invoice>;
  invoice(accountId: string, invoiceId: string): Promise<Invoice>;
  createPayment(accountId: string, invoiceId: string, idempotencyKey: string): Promise<Payment>;
  payment(accountId: string, paymentId: string): Promise<Payment>;
  checkout(accountId: string, paymentId: string, method: 'sbp' | 'bank_card', savePaymentMethod: boolean, idempotencyKey: string): Promise<CheckoutResult>;
  paymentMethods(accountId: string): Promise<SavedPaymentMethodList>;
  setDefaultPaymentMethod(accountId: string, methodId: string, idempotencyKey: string): Promise<SavedPaymentMethod>;
  disablePaymentMethod(accountId: string, methodId: string, idempotencyKey: string): Promise<void>;
  receipt(accountId: string, paymentId: string): Promise<Receipt>;
  refunds(accountId: string, paymentId: string): Promise<RefundList>;
  createRefund(accountId: string, paymentId: string, items: RefundItemInput[], idempotencyKey: string): Promise<Refund>;
  refund(accountId: string, refundId: string): Promise<Refund>;
  requestPlanChange(accountId: string, subscriptionId: string, planPriceId: string, quantity: number, idempotencyKey: string): Promise<PlanChangeRequested>;
  planChange(accountId: string, changeId: string): Promise<PlanChange>;
  cancelPlanChange(accountId: string, changeId: string, idempotencyKey: string): Promise<PlanChangeCancelled>;
}

const accountBase = (accountId: string) => `/accounts/${encodeURIComponent(accountId)}`;

export function createCommercialBillingApi(http: HttpClient): CommercialBillingApi {
  return {
    saveBillingProfile: (accountId, payload) =>
    http.put<BillingProfileResult>(`${accountBase(accountId)}/billing-profile`, payload, { validate: isBillingProfileResult }),
  entitlements: (accountId) =>
    http.get<EffectiveEntitlements>(`${accountBase(accountId)}/entitlements`, { validate: isEffectiveEntitlements }),
  createSubscription: (accountId, planPriceId, quantity, idempotencyKey) =>
    http.post<SubscriptionCreated>(
      `${accountBase(accountId)}/subscriptions`,
      { plan_price_id: planPriceId, quantity },
      { idempotencyKey, validate: isSubscriptionCreated },
    ),
  subscription: (accountId, subscriptionId) =>
    http.get<Subscription>(`${accountBase(accountId)}/subscriptions/${encodeURIComponent(subscriptionId)}`, { validate: isSubscription }),
  cancelSubscription: (accountId, subscriptionId, idempotencyKey) =>
    http.post<SubscriptionCancelled>(
      `${accountBase(accountId)}/subscriptions/${encodeURIComponent(subscriptionId)}/cancel`,
      {},
      { idempotencyKey, validate: isSubscriptionCancelled },
    ),
  disableAutoRenewal: (accountId, subscriptionId, idempotencyKey) =>
    http.post<AutoRenewalDisabled>(
      `${accountBase(accountId)}/subscriptions/${encodeURIComponent(subscriptionId)}/auto-renew/cancel`,
      {},
      { idempotencyKey, validate: isAutoRenewalDisabled },
    ),
  issueInvoice: (accountId, subscriptionId, idempotencyKey) =>
    http.post<Invoice>(
      `${accountBase(accountId)}/subscriptions/${encodeURIComponent(subscriptionId)}/invoice`,
      {},
      { idempotencyKey, validate: isInvoice },
    ),
  invoice: (accountId, invoiceId) =>
    http.get<Invoice>(`${accountBase(accountId)}/invoices/${encodeURIComponent(invoiceId)}`, { validate: isInvoice }),
  createPayment: (accountId, invoiceId, idempotencyKey) =>
    http.post<Payment>(
      `${accountBase(accountId)}/invoices/${encodeURIComponent(invoiceId)}/payment`,
      {},
      { idempotencyKey, validate: isPayment },
    ),
  payment: (accountId, paymentId) =>
    http.get<Payment>(`${accountBase(accountId)}/payments/${encodeURIComponent(paymentId)}`, { validate: isPayment }),
  checkout: (accountId, paymentId, method, savePaymentMethod, idempotencyKey) =>
    http.post<CheckoutResult>(
      `${accountBase(accountId)}/payments/${encodeURIComponent(paymentId)}/checkout`,
      { provider: 'yookassa', method, save_payment_method: savePaymentMethod },
      { idempotencyKey, validate: isCheckoutResult },
    ),
  paymentMethods: (accountId) =>
    http.get<SavedPaymentMethodList>(`${accountBase(accountId)}/payment-methods`, { validate: isSavedPaymentMethodList }),
  setDefaultPaymentMethod: (accountId, methodId, idempotencyKey) =>
    http.post<SavedPaymentMethod>(
      `${accountBase(accountId)}/payment-methods/${encodeURIComponent(methodId)}/default`,
      {},
      { idempotencyKey, validate: isSavedPaymentMethod },
    ),
  disablePaymentMethod: (accountId, methodId, idempotencyKey) =>
    http.request<void>(`${accountBase(accountId)}/payment-methods/${encodeURIComponent(methodId)}`, {
      method: 'DELETE',
      body: {},
      idempotencyKey,
    }),
  receipt: (accountId, paymentId) =>
    http.get<Receipt>(`${accountBase(accountId)}/payments/${encodeURIComponent(paymentId)}/receipt`, { validate: isReceipt }),
  refunds: (accountId, paymentId) =>
    http.get<RefundList>(`${accountBase(accountId)}/payments/${encodeURIComponent(paymentId)}/refunds`, { validate: isRefundList }),
  createRefund: (accountId, paymentId, items, idempotencyKey) =>
    http.post<Refund>(
      `${accountBase(accountId)}/payments/${encodeURIComponent(paymentId)}/refunds`,
      { items },
      { idempotencyKey, validate: isRefund },
    ),
  refund: (accountId, refundId) =>
    http.get<Refund>(`${accountBase(accountId)}/refunds/${encodeURIComponent(refundId)}`, { validate: isRefund }),
  requestPlanChange: (accountId, subscriptionId, planPriceId, quantity, idempotencyKey) =>
    http.post<PlanChangeRequested>(
      `${accountBase(accountId)}/subscriptions/${encodeURIComponent(subscriptionId)}/plan-changes`,
      { plan_price_id: planPriceId, quantity },
      { idempotencyKey, validate: isPlanChangeRequested },
    ),
  planChange: (accountId, changeId) =>
    http.get<PlanChange>(`${accountBase(accountId)}/plan-changes/${encodeURIComponent(changeId)}`, { validate: isPlanChange }),
    cancelPlanChange: (accountId, changeId, idempotencyKey) =>
      http.post<PlanChangeCancelled>(
        `${accountBase(accountId)}/plan-changes/${encodeURIComponent(changeId)}/cancel`,
        {},
        { idempotencyKey, validate: isPlanChangeCancelled },
      ),
  };
}
