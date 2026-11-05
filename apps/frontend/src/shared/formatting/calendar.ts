/** Calendar dates are timezone-free; instants are formatted in the account timezone. */
export function addCalendarDays(date: string, days: number): string {
  const value = new Date(`${date}T12:00:00Z`);
  value.setUTCDate(value.getUTCDate() + days);
  return value.toISOString().slice(0, 10);
}

export function calendarWeek(date: string): string[] {
  const day = new Date(`${date}T12:00:00Z`).getUTCDay();
  const monday = addCalendarDays(date, -((day + 6) % 7));
  return Array.from({ length: 7 }, (_, index) => addCalendarDays(monday, index));
}

export function formatCalendarDate(date: string, locale: string, options: Intl.DateTimeFormatOptions = {}): string {
  return new Intl.DateTimeFormat(locale, { timeZone: 'UTC', ...options }).format(new Date(`${date}T12:00:00Z`));
}

export function formatZonedTime(instant: string, locale: string, timezone: string): string {
  return new Intl.DateTimeFormat(locale, { timeZone: timezone, hour: '2-digit', minute: '2-digit' }).format(new Date(instant));
}

/** Reject missing and repeated wall-clock times instead of silently moving a lesson. */
export function zonedDateTimeToIso(local: string, timezone: string): string {
  if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(local)) throw new RangeError('Invalid local date/time');
  const naive = Date.parse(`${local}:00Z`);
  if (!Number.isFinite(naive)) throw new RangeError('Invalid local date/time');
  const formatter = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
  function wall(value: number): string {
    const parts = Object.fromEntries(formatter.formatToParts(value).map(part => [part.type, part.value]));
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:${parts.second}`;
  }
  const candidates = new Set<string>();
  for (let hour = -36; hour <= 36; hour += 6) {
    const sample = naive + hour * 3600000;
    const offset = Date.parse(`${wall(sample)}Z`) - sample;
    const candidate = naive - offset;
    if (wall(candidate) === `${local}:00`) candidates.add(new Date(candidate).toISOString());
  }
  if (candidates.size !== 1) throw new RangeError('Ambiguous or nonexistent local time');
  return [...candidates][0]!;
}
