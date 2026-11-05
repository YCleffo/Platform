export type TemporalMode = 'date' | 'time' | 'datetime-local';
export function validDate(value: string): boolean {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!match || Number(match[1]) < 1) return false;
  const date = new Date(value + 'T12:00:00Z');
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value;
}
function time(value: string): string | null {
  const match = /^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/.exec(value);
  if (!match || Number(match[1]) > 23 || Number(match[2]) > 59 || Number(match[3] ?? 0) > 59) return null;
  return match.slice(1, match[3] === undefined ? 3 : 4).map((v) => v!.padStart(2, '0')).join(':');
}
export function normalizeTemporal(value: string, mode: TemporalMode, locale: string): string | null {
  const raw = value.trim();
  if (!raw) return '';
  if (mode === 'time') return time(raw);
  const parts = raw.split(/[T ]/);
  let date = parts[0] ?? '';
  if (!validDate(date)) {
    const local = /^(\d{1,2})[./](\d{1,2})[./](\d{4})$/.exec(date);
    if (!local) return null;
    const ru = locale.startsWith('ru');
    date = `${local[3]}-${local[ru ? 2 : 1]!.padStart(2, '0')}-${local[ru ? 1 : 2]!.padStart(2, '0')}`;
  }
  if (!validDate(date)) return null;
  if (mode === 'date') return parts.length === 1 ? date : null;
  const clock = parts.length === 2 ? time(parts[1] ?? '') : null;
  if (!clock) return null;
  const wall = new Date(`${date}T${clock}`);
  const [h, m, s = '00'] = clock.split(':');
  if (!Number.isFinite(wall.getTime()) || isoDay(wall) !== date || wall.getHours() !== Number(h) || wall.getMinutes() !== Number(m) || wall.getSeconds() !== Number(s)) return null;
  return `${date}T${clock}`;
}
export function displayTemporal(value: string, mode: TemporalMode, locale: string): string {
  const normalized = normalizeTemporal(value, mode, locale);
  if (!normalized || mode === 'time') return normalized ?? value;
  const [date, clock] = normalized.split('T');
  const [y, m, d] = date!.split('-');
  const local = locale.startsWith('ru') ? `${d}.${m}.${y}` : `${m}/${d}/${y}`;
  return clock ? `${local} ${clock}` : local;
}
export function withinTemporal(value: string, mode: TemporalMode, min?: string, max?: string): boolean {
  const key = (v: string) => mode === 'date' ? v : (v.includes('T') ? v.padEnd(19, ':00') : v.padEnd(8, ':00'));
  const actual = key(value);
  const lower = min && normalizeTemporal(min, mode, 'en');
  const upper = max && normalizeTemporal(max, mode, 'en');
  if (mode === 'time' && lower && upper && key(lower) > key(upper)) return actual >= key(lower) || actual <= key(upper);
  return (!lower || actual >= key(lower)) && (!upper || actual <= key(upper));
}
export function isoDay(date: Date): string {
  return `${String(date.getFullYear()).padStart(4, '0')}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}
export function shiftDay(day: string, amount: number): string {
  const date = new Date(day + 'T12:00:00Z');
  date.setUTCDate(date.getUTCDate() + amount);
  return date.toISOString().slice(0, 10);
}
export function temporalStep(value: string, mode: TemporalMode, step?: number, min?: string): boolean {
  if (step !== undefined && !Number.isFinite(step)) return true;
  const interval = step && step > 0 ? step : mode === 'date' ? 1 : 60;
  const instant = (raw: string): number => mode === 'date' ? Date.parse(raw + 'T00:00:00Z') / 86400000
    : mode === 'time' ? raw.split(':').reduce((total, part, index) => total + Number(part) * [3600, 60, 1][index]!, 0)
      : Date.parse(raw + 'Z') / 1000;
  const base = min && normalizeTemporal(min, mode, 'en');
  const delta = instant(value) - (base ? instant(base) : 0);
  return Math.abs(delta / interval - Math.round(delta / interval)) < 0.000001;
}
