import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AppRange from './AppRange.vue';
import { i18n } from '@/shared/i18n';

const props = { id: 'size', label: 'Font size', min: .9, max: 1.4, step: .05, defaultValue: 1, modelValue: .95, multiplier: 100, suffix: '%' };
describe('AppRange', () => {
  it('allows precise decimal steps, exposes readable values and respects boundaries', async () => {
    const wrapper = mount(AppRange, { props, global: { plugins: [i18n] } });
    expect(wrapper.find('input').attributes('aria-valuetext')).toBe('95%');
    await wrapper.find('[aria-label="Increase Font size"]').trigger('click');
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([1]);
    await wrapper.setProps({ modelValue: 1.4 });
    expect(wrapper.find<HTMLButtonElement>('[aria-label="Increase Font size"]').element.disabled).toBe(true);
    await wrapper.setProps({ modelValue: .9 });
    expect(wrapper.find<HTMLButtonElement>('[aria-label="Decrease Font size"]').element.disabled).toBe(true);
    wrapper.unmount();
  });
  it('emits an edit boundary for a drag and resets only its own value', async () => {
    const wrapper = mount(AppRange, { props, global: { plugins: [i18n] } });
    await wrapper.find('input').trigger('pointerdown', { button: 0 });
    await wrapper.find('input').setValue('1.25');
    expect(wrapper.emitted('start')).toHaveLength(1);
    expect(wrapper.emitted('end')).toBeTruthy();
    await wrapper.find('[aria-label="Reset to default: Font size"]').trigger('click');
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([1]);
    wrapper.unmount();
  });
  it('disables every way of changing a system-restricted setting', () => {
    const wrapper = mount(AppRange, { props: { ...props, disabled: true }, global: { plugins: [i18n] } });
    expect(wrapper.find<HTMLInputElement>('input').element.disabled).toBe(true);
    expect(wrapper.findAll('button').every(button => (button.element as HTMLButtonElement).disabled)).toBe(true);
    wrapper.unmount();
  });
  it('does not start an edit on Tab and clears the pressed state after cancellation or disabling', async () => {
    const wrapper = mount(AppRange, { props, global: { plugins: [i18n] } });
    const input = wrapper.get('input');
    await input.trigger('keydown', { key: 'Tab' });
    expect(wrapper.emitted('start')).toBeUndefined();
    await input.trigger('pointerdown', { button: 0 });
    expect(wrapper.classes()).toContain('app-range--pressed');
    await input.trigger('pointercancel');
    expect(wrapper.classes()).not.toContain('app-range--pressed');
    await input.trigger('pointerdown', { button: 0 });
    await wrapper.setProps({ disabled: true });
    expect(wrapper.classes()).not.toContain('app-range--pressed');
    expect(wrapper.emitted('end')).toHaveLength(2);
    await wrapper.setProps({ disabled: false });
    await input.trigger('keydown', { key: 'ArrowRight' });
    expect(wrapper.emitted('start')).toHaveLength(3);
    await input.trigger('blur');
    expect(wrapper.classes()).not.toContain('app-range--pressed');
    wrapper.unmount();
  });
});
