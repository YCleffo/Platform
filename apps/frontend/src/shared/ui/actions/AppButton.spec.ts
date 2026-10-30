import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AppButton from './AppButton.vue';

describe('AppButton', () => {
  it('exposes a loading state that disables the control', () => {
    const wrapper = mount(AppButton, {
      props: { loading: true },
      slots: { default: 'Save' },
    });

    const button = wrapper.get('button');
    expect(button.attributes('disabled')).toBeDefined();
    expect(button.attributes('aria-busy')).toBe('true');
    expect(wrapper.text()).toContain('Save');
  });

  it('is clickable when idle', async () => {
    const onClick = vi.fn();
    const wrapper = mount(AppButton, {
      attrs: { onClick },
      slots: { default: 'Save' },
    });
    const button = wrapper.get('button');
    expect(button.attributes('disabled')).toBeUndefined();
    expect(button.attributes('aria-busy')).toBeUndefined();

    await button.trigger('click');
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('renders the selected variant and size classes', () => {
    const wrapper = mount(AppButton, { props: { variant: 'danger', size: 'l' } });
    const classes = wrapper.get('button').classes();
    expect(classes).toContain('app-button--danger');
    expect(classes).toContain('app-button--l');
  });
});
