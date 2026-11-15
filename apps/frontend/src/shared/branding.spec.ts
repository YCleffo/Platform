import { beforeEach, describe, expect, it } from 'vitest';
import { applyPlatformBranding, isPlatformBranding, platformBranding } from './branding';
import { i18n } from './i18n';

const icon = '/api/branding/favicon/01900000-0000-7000-8000-000000000001';
describe('installation branding', () => {
  beforeEach(() => { document.head.innerHTML = ''; applyPlatformBranding({ name: 'Platform', favicon: null }); });

  it('updates both languages, the browser title and the validated favicon', () => {
    applyPlatformBranding({ name: 'School Example', favicon: { url: icon, mime: 'image/png', sha256: 'a'.repeat(64) } });
    expect(platformBranding.name).toBe('School Example');
    expect(i18n.global.t('app.name', {}, { locale: 'ru' })).toBe('School Example');
    expect(i18n.global.t('app.name', {}, { locale: 'en' })).toBe('School Example');
    expect(document.title).toBe('School Example');
    expect(document.querySelector('link[rel="icon"]')?.getAttribute('href')).toBe(icon);
  });

  it('rejects foreign and executable icon URLs without changing the current brand', () => {
    for (const url of ['https://outside.example/icon.png', 'javascript:alert(1)', '/api/storage/file?ticket=value']) {
      const value = { name: 'Invalid', favicon: { url, mime: 'image/png', sha256: 'a'.repeat(64) } };
      expect(isPlatformBranding(value)).toBe(false);
      expect(() => applyPlatformBranding(value)).toThrow();
    }
    expect(platformBranding.name).toBe('Platform');
  });

  it('restores the bundled icon when a customized icon is removed', () => {
    applyPlatformBranding({ name: 'Example', favicon: { url: icon, mime: 'image/png', sha256: 'b'.repeat(64) } });
    applyPlatformBranding({ name: 'Example', favicon: null });
    expect(document.querySelector('link[rel="icon"]')?.getAttribute('href')).toBe('/favicon.svg');
    expect(document.querySelector('link[rel="icon"]')?.getAttribute('type')).toBe('image/svg+xml');
  });
});
