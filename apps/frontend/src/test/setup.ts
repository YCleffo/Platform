import { beforeEach, vi } from 'vitest';
import { resetScrollLock } from '@/shared/composables/useScrollLock';

if (typeof window.matchMedia !== 'function') {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn().mockReturnValue(false),
  }));
}

beforeEach(() => {
  localStorage.clear();
  document.body.innerHTML = '';
  document.body.style.overflow = '';
  resetScrollLock();
});
