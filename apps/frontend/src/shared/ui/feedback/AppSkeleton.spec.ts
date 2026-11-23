import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AppSkeleton from './AppSkeleton.vue';

describe('AppSkeleton', () => {
  it('renders default text variant with configurable lines', () => {
    const wrapper = mount(AppSkeleton, {
      props: { lines: 5 },
    });
    expect(wrapper.classes()).toContain('app-skeleton--text');
    expect(wrapper.attributes('aria-hidden')).toBe('true');
    expect(wrapper.findAll('.app-skeleton__line')).toHaveLength(5);
  });

  it('renders card variant', () => {
    const wrapper = mount(AppSkeleton, {
      props: { variant: 'card' },
    });
    expect(wrapper.classes()).toContain('app-skeleton--card');
    expect(wrapper.find('.app-skeleton__block--tall').exists()).toBe(true);
    expect(wrapper.findAll('.app-skeleton__line')).toHaveLength(2);
  });

  it('renders list variant with row items', () => {
    const wrapper = mount(AppSkeleton, {
      props: { variant: 'list', lines: 4 },
    });
    expect(wrapper.classes()).toContain('app-skeleton--list');
    expect(wrapper.findAll('.app-skeleton__row')).toHaveLength(4);
  });

  it('renders table variant with header and configurable rows and columns', () => {
    const wrapper = mount(AppSkeleton, {
      props: { variant: 'table', rows: 5, columns: 4 },
    });
    expect(wrapper.classes()).toContain('app-skeleton--table');
    expect(wrapper.find('.app-skeleton__table').exists()).toBe(true);
    expect(wrapper.findAll('.app-skeleton__table-th')).toHaveLength(4);
    expect(wrapper.findAll('.app-skeleton__table-row')).toHaveLength(5);
    expect(wrapper.findAll('.app-skeleton__table-cell')).toHaveLength(20);
  });

  it('applies custom minHeight when height prop is provided', () => {
    const wrapper = mount(AppSkeleton, {
      props: { height: '320px' },
    });
    expect(wrapper.attributes('style')).toContain('min-height: 320px');
  });
});
