// Per-page identity lets logout remove only this client's ephemeral presence.
// Only a pending OAuth redirect carries it across documents in its one-time marker.
let instance: string | null = globalThis.crypto?.randomUUID?.() ?? null;

export function resumeCoreClientInstance(value: unknown): boolean {
  if (typeof value !== 'string' || !/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value)) return false;
  instance = value;
  return true;
}

export function coreClientHeaders(): Record<string,string> {
  return instance === null ? {} : { 'X-Client-Instance-ID': instance };
}
