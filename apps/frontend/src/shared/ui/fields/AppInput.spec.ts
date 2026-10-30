import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AppInput from './AppInput.vue';

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
});
