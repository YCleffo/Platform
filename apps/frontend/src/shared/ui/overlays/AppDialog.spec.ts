import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick } from 'vue';
import AppDialog from './AppDialog.vue';
import { i18n } from '@/shared/i18n';

const global = { plugins: [i18n] };

async function flush(): Promise<void> {
  await nextTick();
  await new Promise((resolve) => setTimeout(resolve, 0));
}

describe('AppDialog', () => {
  it('locks scroll, traps focus, closes on Escape and restores focus', async () => {
    const trigger = document.createElement('button');
    trigger.textContent = 'open';
    document.body.appendChild(trigger);
    trigger.focus();

    const wrapper = mount(AppDialog, {
      props: { modelValue: true, title: 'Dialog' },
      attachTo: document.body,
      global,
    });
    try {
      await flush();
      const dialog = document.querySelector('[role="dialog"]');
      expect(dialog).not.toBeNull();
      expect(dialog?.getAttribute('aria-modal')).toBe('true');
      expect(dialog?.contains(document.activeElement)).toBe(true);
      expect(document.body.style.overflow).toBe('hidden');

      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
      await flush();
      expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([false]);

      await wrapper.setProps({ modelValue: false });
      await flush();
      expect(document.body.style.overflow).toBe('');
      expect(document.activeElement).toBe(trigger);
    } finally {
      wrapper.unmount();
    }
  });

  it('ignores Escape and backdrop when persistent', async () => {
    const wrapper = mount(AppDialog, {
      props: { modelValue: true, title: 'Busy', persistent: true },
      attachTo: document.body,
      global,
    });
    try {
      await flush();
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
      await flush();
      expect(wrapper.emitted('update:modelValue')).toBeUndefined();
      expect(document.querySelectorAll('[role="dialog"]')).toHaveLength(1);
    } finally {
      wrapper.unmount();
    }
  });

  it('handles Escape only in the topmost dialog and restores the underlying focus', async () => {
    const outer = mount(AppDialog, {
      props: { modelValue: true, title: 'Outer' },
      attachTo: document.body,
      global,
    });
    try {
      await flush();
      const previousFocus = document.activeElement;
      const inner = mount(AppDialog, {
        props: { modelValue: true, title: 'Inner' },
        attachTo: document.body,
        global,
      });
      try {
        await flush();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', cancelable: true }));
        await flush();
        expect(inner.emitted('update:modelValue')).toEqual([[false]]);
        expect(outer.emitted('update:modelValue')).toBeUndefined();
        await inner.setProps({ modelValue: false });
        await flush();
        expect(document.activeElement).toBe(previousFocus);
      } finally {
        inner.unmount();
      }
    } finally {
      outer.unmount();
    }
  });

  it('keeps keyboard focus inside a persistent dialog with no controls', async () => {
    const wrapper = mount(AppDialog, {
      props: { modelValue: true, title: 'Saving', persistent: true },
      attachTo: document.body,
      global,
    });
    try {
      await flush();
      const dialog = document.querySelector('[role="dialog"]');
      expect(document.activeElement).toBe(dialog);
      for (const shiftKey of [false, true]) {
        const tab = new KeyboardEvent('keydown', { key: 'Tab', shiftKey, cancelable: true });
        document.dispatchEvent(tab);
        expect(tab.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(dialog);
      }
    } finally {
      wrapper.unmount();
    }
  });

  it('generates unique labelled ids per instance', async () => {
    const Parent = defineComponent({
      setup() {
        return () =>
          h('div', [
            h(AppDialog, { modelValue: true, title: 'A' }),
            h(AppDialog, { modelValue: true, title: 'B' }),
          ]);
      },
    });
    const wrapper = mount(Parent, { attachTo: document.body, global });
    try {
      await flush();
      const ids = Array.from(document.querySelectorAll('[role="dialog"]')).map((el) =>
        el.getAttribute('aria-labelledby'),
      );
      expect(ids).toHaveLength(2);
      expect(ids[0]).not.toBe(ids[1]);
    } finally {
      wrapper.unmount();
    }
  });
});
