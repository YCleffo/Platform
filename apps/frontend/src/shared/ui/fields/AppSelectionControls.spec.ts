import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AppCheckbox from './AppCheckbox.vue';
import AppSwitch from './AppSwitch.vue';

describe('selection control contracts', () => {
  it('keeps mixed checkbox state native and forwards form attributes to the input', async () => {
    const wrapper = mount(AppCheckbox, { props: { id: 'consent', label: 'Consent', description: 'Required for this action', indeterminate: true, required: true, name: 'consent', value: 'accepted' }, attrs: { 'aria-describedby': 'external-hint' } });
    const input = wrapper.get<HTMLInputElement>('input');
    expect(input.element.indeterminate).toBe(true);
    expect(input.element.required).toBe(true);
    expect(input.element.name).toBe('consent');
    expect(input.element.value).toBe('accepted');
    expect(input.attributes('aria-describedby')).toBe('consent-description external-hint');
    await wrapper.setProps({ indeterminate: false });
    expect(input.element.indeterminate).toBe(false);
    await input.setValue(true);
    expect(wrapper.emitted('update:modelValue')).toEqual([[true]]);
    wrapper.unmount();
  });
  it('uses a switch role while retaining a native checkbox and external description', async () => {
    const wrapper = mount(AppSwitch, { props: { id: 'motion', label: 'Motion', modelValue: true, description: 'Immediate setting', name: 'motion' }, attrs: { 'aria-describedby': 'system-note' } });
    const input = wrapper.get<HTMLInputElement>('input');
    expect(input.attributes('role')).toBe('switch');
    expect(input.element.checked).toBe(true);
    expect(input.attributes('aria-describedby')).toBe('motion-description system-note');
    expect(wrapper.get('label').attributes('for')).toBe(input.element.id);
    await input.setValue(false);
    expect(wrapper.emitted('update:modelValue')).toEqual([[false]]);
    wrapper.unmount();
  });
  it.each([AppCheckbox, AppSwitch])('retains native disabled behavior and does not add another tab stop', component => {
    const wrapper = mount(component, { props: { id: 'disabled', label: 'Unavailable', disabled: true, modelValue: true } });
    expect(wrapper.get<HTMLInputElement>('input').element.disabled).toBe(true);
    expect(wrapper.findAll('button, [tabindex]')).toHaveLength(0);
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    wrapper.unmount();
  });
});
