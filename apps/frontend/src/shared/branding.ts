import { reactive, readonly } from 'vue';
import { i18n } from './i18n';
import { ApiResponseError } from './api/errors';

export interface PlatformBranding {
  name: string;
  favicon: { url: string; mime: string; sha256: string } | null;
}

const state = reactive<PlatformBranding>({ name: 'Platform', favicon: null });
export const platformBranding = readonly(state);

export function isPlatformBranding(value: unknown): value is PlatformBranding {
  if (typeof value !== 'object' || value === null) return false;
  const item = value as Record<string, unknown>;
  if (typeof item.name !== 'string' || !item.name.trim() || item.name.length > 200) return false;
  if (item.favicon === null) return true;
  if (typeof item.favicon !== 'object' || item.favicon === null) return false;
  const icon = item.favicon as Record<string, unknown>;
  return typeof icon.url === 'string'
    && /^\/api\/branding\/favicon\/[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(icon.url)
    && ['image/png', 'image/vnd.microsoft.icon'].includes(String(icon.mime))
    && typeof icon.sha256 === 'string' && /^[a-f0-9]{64}$/.test(icon.sha256);
}

export function applyPlatformBranding(value: unknown): PlatformBranding {
  if (!isPlatformBranding(value)) throw new ApiResponseError('/branding');
  state.name = value.name;
  state.favicon = value.favicon;
  i18n.global.mergeLocaleMessage('en', { app: { name: value.name } });
  i18n.global.mergeLocaleMessage('ru', { app: { name: value.name } });
  document.title = value.name;
  const link = document.querySelector<HTMLLinkElement>('link[rel="icon"]') ?? document.createElement('link');
  link.rel = 'icon';
  link.href = value.favicon?.url ?? '/favicon.svg';
  link.type = value.favicon?.mime ?? 'image/svg+xml';
  if (!link.isConnected) document.head.appendChild(link);
  return value;
}

export async function loadPlatformBranding(signal?: AbortSignal): Promise<PlatformBranding> {
  const response = await fetch('/api/branding', { signal, cache: 'no-store', credentials: 'same-origin' });
  if (!response.ok) throw new Error('Branding is unavailable');
  const envelope: unknown = await response.json();
  if (typeof envelope !== 'object' || envelope === null || !('data' in envelope)) {
    throw new ApiResponseError('/branding');
  }
  return applyPlatformBranding(envelope.data);
}
