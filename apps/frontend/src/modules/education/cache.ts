import { watch } from 'vue';
import { coreAdminSession, coreAdminSessionEpoch } from '@/core/admin/session';
import { SupersededOperationError } from '@/shared/api/errors';

interface Entry { value: unknown; savedAt: number; tags: string[] }
const entries = new Map<string, Entry>();
const pending = new Map<string, { controller: AbortController; promise: Promise<unknown> }>();
let generation = 0;
let organization = '';
const samples: { kind: string; durationMs: number; cached: boolean }[] = [];
export const educationMeasurements = {
  record(kind: string, start: number, cached = false): void {
    samples.push({ kind, durationMs: performance.now() - start, cached });
    if (samples.length > 300) samples.shift();
  },
  snapshot: () => samples.map(sample => ({ ...sample })),
  clear: () => { samples.length = 0; },
};
export function clearEducationCache(): void {
  generation++;
  pending.forEach(item => item.controller.abort());
  pending.clear(); entries.clear(); educationMeasurements.clear();
}
watch(coreAdminSessionEpoch, clearEducationCache, { flush: 'sync' });
export function scopeEducationCache(org: string): void {
  if (organization !== org) { clearEducationCache(); organization = org; }
}
export function invalidateEducationCache(tags: string[] = []): void {
  generation++;
  for (const [key, entry] of entries) {
    if (!tags.length || entry.tags.some(tag => tags.includes(tag))) entries.delete(key);
  }
  // A read started before a write must never overwrite a post-write read.
  pending.forEach(item => item.controller.abort()); pending.clear();
}
export function educationTags(path: string): string[] {
  if (/^\/(student\/)?homework/.test(path)) return ['homework', 'student', 'students', 'groups', 'lessons', 'messages', 'header'];
  if (/^\/today\/header/.test(path)) return ['header', 'lessons'];
  if (/^\/profile/.test(path)) return ['profile', 'header', 'lessons'];
  if (/^\/files/.test(path)) return ['files', 'lessons', 'profile', 'header'];
  if (/^\/student\//.test(path)) return ['student', 'programs', 'lessons', 'students'];
  if (/^\/(today|schedule|timeline|attention|events|lessons)/.test(path)) return ['lessons', 'programs', 'students', 'header'];
  if (/^\/(programs|curriculum)/.test(path)) return ['programs'];
  if (/^\/groups/.test(path)) return ['groups', 'lessons', 'programs', 'students'];
  if (/^\/(students|people)/.test(path)) return ['students', 'groups', 'lessons'];
  if (/^\/(threads|notifications)/.test(path)) return ['messages', 'header', 'homework'];
  return [path.split('/')[1] ?? 'context'];
}
export async function educationRead<T>(org: string | null, path: string, load: (signal: AbortSignal) => Promise<T>, signal?: AbortSignal): Promise<T> {
  if (!coreAdminSession.accessToken()) return load(signal ?? new AbortController().signal);
  if (org && !organization) scopeEducationCache(org);
  if (org && org !== organization) throw new SupersededOperationError();
  const key = `${coreAdminSession.epoch()}:${org ?? ''}:${path}`;
  const started = performance.now();
  const cached = entries.get(key);
  if (cached && performance.now() - cached.savedAt < 20_000) {
    if (signal?.aborted) throw new SupersededOperationError();
    educationMeasurements.record('read', started, true);
    return cached.value as T;
  }
  let item = pending.get(key);
  if (!item) {
    const version = generation; const epoch = coreAdminSession.epoch();
    const controller = new AbortController();
    const promise = load(controller.signal).then(value => {
      if (version !== generation || epoch !== coreAdminSession.epoch() || controller.signal.aborted) throw new SupersededOperationError();
      entries.set(key, { value, savedAt: performance.now(), tags: educationTags(path) });
      if (entries.size > 160) entries.delete(entries.keys().next().value!);
      return value;
    }).finally(() => { if (pending.get(key)?.promise === promise) pending.delete(key); });
    item = { controller, promise }; pending.set(key, item);
  }
  try {
    const value = await item.promise as T;
    if (signal?.aborted) throw new SupersededOperationError();
    return value;
  } finally { educationMeasurements.record('read', started); }
}
