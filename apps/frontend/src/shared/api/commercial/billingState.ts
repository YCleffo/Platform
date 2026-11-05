export interface BillingHistoryItem {
  kind: 'invoice' | 'payment' | 'plan_change'; id: string; status: string; created_at: string;
  currency: string; amount_minor: number;
}
export interface BillingState {
  subscription_id: string | null; pending_invoice_id: string | null; pending_payment_id: string | null;
  latest_invoice_id: string | null; latest_payment_id: string | null; plan_change_id: string | null;
  billing_profile: null | { legal_name: string; country_code: string; contact_name: string | null;
    contact_email: string | null; tax_identifier: string | null; address: Record<string, unknown> };
  history: { items: BillingHistoryItem[]; total: number; limit: number; offset: number };
}
const object = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value);
const integer = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
export function isBillingState(value: unknown): value is BillingState {
  if (!object(value) || !object(value.history) || !Array.isArray(value.history.items)) return false;
  if (!['subscription_id', 'pending_invoice_id', 'pending_payment_id', 'latest_invoice_id', 'latest_payment_id', 'plan_change_id']
    .every(key => value[key] === null || typeof value[key] === 'string' && value[key] !== '')) return false;
  const profile = value.billing_profile;
  if (profile !== null && (!object(profile) || typeof profile.legal_name !== 'string' || typeof profile.country_code !== 'string'
    || !['contact_name', 'contact_email', 'tax_identifier'].every(key => profile[key] === null || typeof profile[key] === 'string') || !object(profile.address))) return false;
  return integer(value.history.total) && integer(value.history.limit) && value.history.limit > 0 && integer(value.history.offset)
    && value.history.items.every(item => object(item) && ['invoice', 'payment', 'plan_change'].includes(String(item.kind))
      && typeof item.id === 'string' && typeof item.status === 'string' && typeof item.created_at === 'string'
      && typeof item.currency === 'string' && integer(item.amount_minor));
}
