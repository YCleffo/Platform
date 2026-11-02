import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import en from '@/shared/i18n/messages/en';
import ru from '@/shared/i18n/messages/ru';
import AppJsonResponseDialog from './AppJsonResponseDialog.vue';

describe('AppJsonResponseDialog', () => {
  it('shows the response as text, translates its default title and closes with Escape', async () => {
    const i18n = createI18n({ legacy: false, locale: 'en', messages: { en, ru } });
    const data = { status: 'success', value: '<script>unsafe()</script>', items: [1, 2] };
    const wrapper = mount(AppJsonResponseDialog, {
      props: { modelValue: true, data },
      global: { plugins: [i18n] },
    });
    try {
      await nextTick();
      const dialog = document.querySelector('[role="dialog"]')!;
      expect(dialog.querySelector('h2')!.textContent).toBe('Response');
      expect(JSON.parse(dialog.querySelector('pre')!.textContent!)).toEqual(data);
      expect(dialog.querySelector('script')).toBeNull();
      i18n.global.locale.value = 'ru';
      await nextTick();
      expect(dialog.querySelector('h2')!.textContent).toBe('Ответ');
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
      expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([false]);
    } finally {
      wrapper.unmount();
    }
  });
});
