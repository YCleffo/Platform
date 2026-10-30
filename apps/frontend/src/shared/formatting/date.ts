/** Display-only date formatting. API values stay ISO-8601 strings. */
export function formatDateTime(value: string, locale?: string): string {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '—';
  }
  try {
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  } catch {
    return date.toISOString();
  }
}
