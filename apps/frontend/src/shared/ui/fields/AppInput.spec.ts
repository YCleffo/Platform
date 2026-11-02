import { describe, expect, it } from 'vitest';
import { defineComponent, nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import AppInput from './AppInput.vue';

const flushReset = () => new Promise(resolve => setTimeout(resolve, 0));

describe('AppInput', () => {
  it('associates the label and error with the control', () => {
    const wrapper = mount(AppInput, {
      props: { id: 'email', label: 'Email', error: 'Required', modelValue: '' },
    });

    const input = wrapper.get('input');
    expect(wrapper.get('label').attributes('for')).toBe('email');
    expect(input.attributes('id')).toBe('email');
    expect(input.attributes('aria-invalid')).toBe('true');
    expect(input.attributes('aria-describedby')).toBe('email-error');
    expect(wrapper.text()).toContain('Required');
  });

  it('describes the hint when there is no error', () => {
    const wrapper = mount(AppInput, {
      props: { id: 'code', label: 'Code', hint: 'Lowercase only', modelValue: '' },
    });

    const input = wrapper.get('input');
    expect(input.attributes('aria-invalid')).toBe('false');
    expect(input.attributes('aria-describedby')).toBe('code-hint');
    expect(wrapper.text()).toContain('Lowercase only');
  });

  it('emits model updates when typing', async () => {
    const wrapper = mount(AppInput, { props: { id: 'name', modelValue: '' } });
    await wrapper.get('input').setValue('hello');
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['hello']);
  });

  it('marks the control invalid and disabled', () => {
    const wrapper = mount(AppInput, {
      props: { id: 'x', modelValue: '', error: 'Bad', disabled: true },
    });
    expect(wrapper.get('input').attributes('disabled')).toBeDefined();
    expect(wrapper.get('.app-input__control').classes()).toContain('app-input__control--error');
  });

  it('focuses the native input when the control is clicked', async () => {
    const wrapper = mount(AppInput, {
      props: { id: 'focusable', modelValue: '' },
      attachTo: document.body,
    });
    try {
      await wrapper.get('.app-input__control').trigger('click');
      expect(document.activeElement).toBe(wrapper.get('input').element);
    } finally {
      wrapper.unmount();
    }
  });

  it('keeps native form attributes on the input and synchronizes a native reset', async () => {
    const Host = defineComponent({
      components: { AppInput },
      data: () => ({ value: 'Changed' }),
      template: '<form><AppInput id="username" v-model="value" name="username" /></form>',
    });
    const wrapper = mount(Host);
    try {
      const input = wrapper.get('input');
      const form = wrapper.get('form').element as HTMLFormElement;
      expect(input.attributes('name')).toBe('username');
      expect(new FormData(form).get('username')).toBe('Changed');

      form.reset();
      await nextTick();
      await flushReset();

      expect(wrapper.vm.value).toBe('');
      expect(input.element.value).toBe('');
      expect(new FormData(form).get('username')).toBe('');
    } finally {
      wrapper.unmount();
    }
  });

  it('does not sync a canceled native reset', async () => {
    const Host = defineComponent({
      components: { AppInput },
      data: () => ({ value: 'Initial' }),
      template: '<form @reset.prevent><AppInput id="username" v-model="value" name="username" /></form>',
    });
    const wrapper = mount(Host);
    try {
      const input = wrapper.get('input');
      const form = wrapper.get('form').element as HTMLFormElement;

      const reset = new Event('reset', { bubbles: true, cancelable: true });
      form.dispatchEvent(reset);
      expect(reset.defaultPrevented).toBe(true);
      await nextTick();
      await flushReset();

      expect(wrapper.vm.value).toBe('Initial');
      expect(input.element.value).toBe('Initial');
      expect(new FormData(form).get('username')).toBe('Initial');
    } finally {
      wrapper.unmount();
    }
  });
});
