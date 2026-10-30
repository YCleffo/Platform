/**
 * Display-only money formatting. Amounts are stored as integer minor units.
 * Business calculations must never go through JavaScript floating point.
 */
export function formatMoney(amountMinor: number, currency: string, locale?: string): string {
  if (!Number.isFinite(amountMinor)) {
    return '—';
  }
  try {
    const digits =
      new Intl.NumberFormat(locale, { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2;
    return new Intl.NumberFormat(locale, {
      style: 'currency',
      currency,
      maximumFractionDigits: digits,
    }).format(amountMinor / 10 ** digits);
  } catch {
    return `${amountMinor} ${currency}`;
  }
}
