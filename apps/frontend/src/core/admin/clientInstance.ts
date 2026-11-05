// Per-page identity lets logout remove only this client's ephemeral presence.
// It is never persisted and contains no account or device data.
const instance = globalThis.crypto?.randomUUID?.() ?? null;
export function coreClientHeaders(): Record<string,string> {
  return instance === null ? {} : { 'X-Client-Instance-ID': instance };
}
