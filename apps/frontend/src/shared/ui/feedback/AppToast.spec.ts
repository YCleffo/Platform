import { beforeEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import AppToast from './AppToast.vue';
import { clearToasts, useToast } from '@/shared/composables/useToast';
import { i18n } from '@/shared/i18n';

describe('AppToast', () => {
  beforeEach(() => {
    clearToasts();
  });

  it('renders a toast and dismisses it manually', async () => {
    const { show } = useToast();
    show('Saved', 'success', 0);

    const wrapper = mount(AppToast, { attachTo: document.body, global: { plugins: [i18n] } });
    try {
      await nextTick();
      expect(document.body.textContent).toContain('Saved');

      const dismiss = document.querySelector<HTMLButtonElement>('button[aria-label="Dismiss notification"]');
      expect(dismiss).not.toBeNull();
      dismiss?.click();
      await nextTick();
      expect(document.body.textContent).not.toContain('Saved');
    } finally {
      wrapper.unmount();
      clearToasts();
    }
  });

  it('caps the queue and clears on demand', () => {
    const { show, toasts } = useToast();
    for (let index = 0; index < 6; index += 1) {
      show(`Toast ${index}`, 'info', 0);
    }
    expect(toasts.value.length).toBeLessThanOrEqual(4);

    clearToasts();
    expect(toasts.value).toHaveLength(0);
  });
});
