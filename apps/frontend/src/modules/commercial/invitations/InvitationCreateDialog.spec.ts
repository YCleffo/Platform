import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import InvitationCreateDialog from './InvitationCreateDialog.vue';
import { i18n } from '@/shared/i18n';

async function flush(): Promise<void> {
  await nextTick();
  await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('InvitationCreateDialog', () => {
  it('offers only non-owner roles and emits a create request', async () => {
    const wrapper = mount(InvitationCreateDialog, {
      props: { modelValue: true, busy: false, error: null },
      attachTo: document.body,
      global: { plugins: [i18n] },
    });
    try {
      await flush();
      document.querySelector<HTMLButtonElement>('#invitation-role')!.click();
      await flush();
      const options = Array.from(
        document.querySelectorAll<HTMLElement>('#invitation-role-menu [data-menu-id]'),
      ).map((option) => option.dataset.menuId);
      expect(options).toEqual(['admin', 'billing', 'technical']);
      expect(options).not.toContain('owner');
      document.querySelector<HTMLElement>('[data-menu-id="admin"]')!.click();
      await flush();

      const email = document.querySelector<HTMLInputElement>('#invitation-email');
      expect(email).not.toBeNull();
      email!.value = 'person@example.test';
      email!.dispatchEvent(new Event('input'));
      await flush();

      const form = document.querySelector<HTMLFormElement>('[role="dialog"] form');
      expect(form).not.toBeNull();
      form!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      await flush();

      const emitted = wrapper.emitted('confirm');
      expect(emitted).toBeTruthy();
      const payload = emitted![0]?.[0] as { email: string; role: string; idempotencyKey: string };
      expect(payload.email).toBe('person@example.test');
      expect(payload.role).toBe('admin');
      expect(payload.idempotencyKey.length).toBeGreaterThan(10);
    } finally {
      wrapper.unmount();
    }
  });

  it('does not submit an invalid email', async () => {
    const wrapper = mount(InvitationCreateDialog, {
      props: { modelValue: true, busy: false, error: null },
      attachTo: document.body,
      global: { plugins: [i18n] },
    });
    try {
      await flush();
      const email = document.querySelector<HTMLInputElement>('#invitation-email');
      email!.value = 'not-an-email';
      email!.dispatchEvent(new Event('input'));
      await flush();
      const form = document.querySelector<HTMLFormElement>('[role="dialog"] form');
      form!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      await flush();

      expect(wrapper.emitted('confirm')).toBeUndefined();
      expect(document.body.textContent).toContain('Enter a valid email address.');
    } finally {
      wrapper.unmount();
    }
  });
});
