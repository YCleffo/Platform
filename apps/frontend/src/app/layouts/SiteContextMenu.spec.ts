import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { i18n } from '@/shared/i18n';
import SiteContextMenu from './SiteContextMenu.vue';

const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', name: 'catalog', component: { template: '<div>Catalog</div>' } },
    { path: '/settings', name: 'settings', component: { template: '<div>Settings</div>' } },
  ],
});

async function flush() {
  await nextTick();
  await new Promise((resolve) => setTimeout(resolve, 30));
}

describe('SiteContextMenu', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('renders slot content and prepares context items with clipboard and share actions', async () => {
    const wrapper = mount(SiteContextMenu, {
      attachTo: document.body,
      slots: {
        default: '<div id="content-area">Right-click here</div>',
      },
      global: {
        plugins: [router, i18n],
      },
    });

    try {
      const area = document.querySelector<HTMLElement>('#content-area')!;
      const event = new KeyboardEvent('keydown', { key: 'F10', shiftKey: true, bubbles: true, cancelable: true });
      area.dispatchEvent(event);
      await flush();

      // Check context menu is open
      const copyBtn = document.querySelector<HTMLButtonElement>('[data-menu-id="copy"]');
      const pasteBtn = document.querySelector<HTMLButtonElement>('[data-menu-id="paste"]');
      const shareBtn = document.querySelector<HTMLButtonElement>('[data-menu-id="share"]');
      const reloadBtn = document.querySelector<HTMLButtonElement>('[data-menu-id="reload"]');

      expect(copyBtn).not.toBeNull();
      expect(pasteBtn).not.toBeNull();
      expect(shareBtn).not.toBeNull();
      expect(reloadBtn).not.toBeNull();

      // Paste should be disabled when clipboard is empty
      expect(pasteBtn?.getAttribute('aria-disabled')).toBe('true');
    } finally {
      wrapper.unmount();
    }
  });

  it('enables paste when clipboard contains text', async () => {
    // Mock navigator.clipboard
    Object.defineProperty(navigator, 'clipboard', {
      value: {
        readText: vi.fn().mockResolvedValue('Copied secret text'),
        writeText: vi.fn().mockResolvedValue(undefined),
      },
      configurable: true,
    });

    const wrapper = mount(SiteContextMenu, {
      attachTo: document.body,
      slots: {
        default: '<div id="content-area">Right-click here</div>',
      },
      global: {
        plugins: [router, i18n],
      },
    });

    try {
      const area = document.querySelector<HTMLElement>('#content-area')!;
      const event = new KeyboardEvent('keydown', { key: 'F10', shiftKey: true, bubbles: true, cancelable: true });
      area.dispatchEvent(event);
      await flush();

      const pasteBtn = document.querySelector<HTMLButtonElement>('[data-menu-id="paste"]');
      expect(pasteBtn?.getAttribute('aria-disabled')).toBeNull();
    } finally {
      wrapper.unmount();
    }
  });

  it('opens the QR code share dialog when share is selected', async () => {
    const wrapper = mount(SiteContextMenu, {
      attachTo: document.body,
      slots: {
        default: '<div id="content-area"><a id="resource-link" href="https://example.com/test-resource">Link to resource</a></div>',
      },
      global: {
        plugins: [router, i18n],
      },
    });

    try {
      const area = document.querySelector<HTMLElement>('#content-area')!;
      const event = new KeyboardEvent('keydown', { key: 'F10', shiftKey: true, bubbles: true, cancelable: true });
      area.dispatchEvent(event);
      await flush();

      const shareBtn = document.querySelector<HTMLButtonElement>('[data-menu-id="share"]');
      expect(shareBtn).not.toBeNull();
      shareBtn?.click();
      await flush();

      // Dialog should be opened with QR code
      const dialog = document.querySelector('[role="dialog"]');
      expect(dialog).not.toBeNull();

      const qrSvg = dialog?.querySelector('.share-dialog__qr-container svg');
      expect(qrSvg).not.toBeNull();
    } finally {
      wrapper.unmount();
    }
  });
});
