import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import AppDropdown from '../fields/AppDropdown.vue';
import AppContextMenu from './AppContextMenu.vue';
import AppMenu from './AppMenu.vue';
import { searchMenu, type MenuItem } from './types';
import { i18n } from '@/shared/i18n';

const global = { plugins: [i18n] };
async function flush() { await nextTick(); await new Promise(resolve => setTimeout(resolve, 20)); }
const options = [{ value: 'one', label: 'One' }, { value: 'disabled', label: 'Disabled', disabled: true }, { value: 'two', label: 'Two' }];
function click(selector: string) { document.querySelector<HTMLButtonElement>(selector)!.click(); }
function key(key: string) { document.activeElement!.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true })); }

describe('shared menus', () => {
  it('emits a mobile selection after returning from the menu history entry', async () => {
    vi.stubGlobal('innerWidth', 393);
    const wrapper = mount(AppDropdown, {
      attachTo: document.body,
      props: { id: 'history-unit', label: 'Choice', modelValue: 'one', options },
      global,
    });
    try {
      const originalState = window.history.state;
      click('#history-unit');
      await flush();
      expect(window.history.state.appMenu).toBe('history-unit-menu');
      const selectionAtReturn = new Promise((resolve) => {
        window.addEventListener('popstate', () => resolve(wrapper.emitted('update:modelValue')), {
          once: true,
        });
      });

      click('#history-unit-menu [data-menu-id="two"]');

      expect(await selectionAtReturn).toBeUndefined();
      expect(window.history.state).toEqual(originalState);
      expect(wrapper.emitted('update:modelValue')).toEqual([['two']]);
    } finally {
      wrapper.unmount();
      vi.unstubAllGlobals();
    }
  });

  it('returns focus to search after its clear button disappears', async () => {
    const wrapper = mount(AppDropdown, { attachTo: document.body, props: { id: 'clear-search-unit', label: 'Choice', modelValue: 'two', options, searchable: true }, global });
    try {
      click('#clear-search-unit'); await flush();
      const input = document.querySelector<HTMLInputElement>('.app-menu input')!;
      input.value = 'one'; input.dispatchEvent(new Event('input', { bubbles: true })); await flush();
      const button = document.querySelector<HTMLButtonElement>('.app-menu__search button')!;
      button.focus(); button.click(); await flush();
      expect(document.activeElement).toBe(document.querySelector('.app-menu input'));
      expect(document.querySelectorAll('[data-menu-id]')).toHaveLength(3);
    } finally { wrapper.unmount(); }
  });
  it('focuses the selected item when a non-searchable dropdown is reopened', async () => {
    const wrapper = mount(AppDropdown, { attachTo: document.body, props: { id: 'selected-unit', label: 'Choice', modelValue: 'two', options }, global });
    try {
      click('#selected-unit'); await flush();
      expect(document.activeElement?.getAttribute('data-menu-id')).toBe('two');
    } finally { wrapper.unmount(); }
  });
  it('searches nested labels and descriptions, carrying inherited restrictions', () => {
    const items: MenuItem[] = [{ id: 'folder', label: 'Design', disabled: true, children: [{ id: 'leaf', label: 'Color', description: 'HEX' }] }];
    expect(searchMenu(items, 'hex')[0]).toMatchObject({ id: 'leaf', disabled: true, description: 'Design · HEX' });
    expect(searchMenu(items, 'design')).toHaveLength(1);
    expect(searchMenu(items, 'absent')).toEqual([]);
  });

  it('opens from the keyboard, skips structural entries, protects disabled choices and restores focus', async () => {
    const wrapper = mount(AppDropdown, { attachTo: document.body, props: { id: 'unit', label: 'Choice', modelValue: 'one', options }, global });
    try {
      const trigger = document.querySelector<HTMLButtonElement>('#unit')!; trigger.focus();
      key('ArrowDown'); await flush();
      expect(document.activeElement?.getAttribute('data-menu-id')).toBe('one');
      key('ArrowDown'); await flush();
      expect(document.activeElement?.getAttribute('aria-disabled')).toBe('true');
      click('[data-menu-id="disabled"]'); await flush();
      expect(wrapper.emitted('update:modelValue')).toBeUndefined();
      key('End'); await flush();
      expect(document.activeElement?.getAttribute('data-menu-id')).toBe('two');
      click('[data-menu-id="two"]'); await flush();
      expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['two']);
      expect(document.activeElement).toBe(trigger);
      expect(trigger.getAttribute('aria-expanded')).toBe('false');
    } finally { wrapper.unmount(); }
  });

  it('searches with an empty state and closes on Escape without leaking to a dialog', async () => {
    const wrapper = mount(AppDropdown, { attachTo: document.body, props: { id: 'search-unit', label: 'Choice', modelValue: 'one', options, searchable: true }, global });
    let escapes = 0;
    const listener = (event: KeyboardEvent) => { if (event.key === 'Escape') escapes++; };
    document.addEventListener('keydown', listener);
    try {
      click('#search-unit'); await flush();
      const input = document.querySelector<HTMLInputElement>('.app-menu input')!;
      expect(document.activeElement).toBe(input);
      input.value = 'missing'; input.dispatchEvent(new Event('input', { bubbles: true })); await flush();
      expect(document.querySelector('.app-menu__empty')).not.toBeNull();
      key('Escape'); await flush();
      expect(escapes).toBe(0);
      expect(document.querySelector('.app-menu')).toBeNull();
    } finally { wrapper.unmount(); document.removeEventListener('keydown', listener); }
  });

  it('keeps multiple selection open and clears without submitting a form', async () => {
    const wrapper = mount(AppDropdown, { attachTo: document.body, props: { id: 'multi-unit', label: 'Choice', modelValue: ['one'], options, multiple: true, clearable: true }, global });
    try {
      click('#multi-unit'); await flush(); click('[data-menu-id="two"]'); await flush();
      expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['one', 'two']]);
      expect(document.querySelector('.app-menu')).not.toBeNull();
      key('Escape'); await flush();
      expect(document.querySelector('.app-dropdown__clear')?.getAttribute('type')).toBe('button');
      click('.app-dropdown__clear'); await flush();
      expect(wrapper.emitted('update:modelValue')?.[1]).toEqual([[]]);
      expect(document.querySelector('.app-dropdown__clear')).toBeNull();
    } finally { wrapper.unmount(); }
  });

  it('opens a submenu with ArrowRight and returns to its parent with ArrowLeft', async () => {
    const wrapper = mount(AppMenu, { attachTo: document.body, props: { modelValue: true, label: 'Tree', items: [{ id: 'parent', label: 'Folder', children: [{ id: 'child', label: 'Child' }] }] }, global });
    try {
      await flush(); key('ArrowRight'); await flush();
      expect(document.activeElement?.getAttribute('data-menu-id')).toBe('child');
      expect(document.querySelector('[data-menu-depth="1"]')).not.toBeNull();
      key('ArrowLeft'); await flush();
      expect(document.activeElement?.getAttribute('data-menu-id')).toBe('parent');
    } finally { wrapper.unmount(); }
  });

  it('closes on outside pointerdown and disables an open dropdown', async () => {
    const wrapper = mount(AppDropdown, { attachTo: document.body, props: { id: 'outside-unit', label: 'Choice', modelValue: 'one', options }, global });
    try {
      click('#outside-unit'); await flush();
      document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true })); await flush();
      expect(document.querySelector('.app-menu')).toBeNull();
      click('#outside-unit'); await flush(); await wrapper.setProps({ disabled: true }); await flush();
      expect(document.querySelector('.app-menu')).toBeNull();
      expect(document.querySelector<HTMLButtonElement>('#outside-unit')?.disabled).toBe(true);
    } finally { wrapper.unmount(); }
  });

  it('preserves native editing/link context menus and permits the browser escape hatch', async () => {
    const wrapper = mount(AppContextMenu, { attachTo: document.body, props: { items: [{ id: 'act', label: 'Action' }], label: 'Context' }, slots: { default: '<div id="area" tabindex="0">Area<input id="native" /><a id="link" href="/">Link</a></div>' }, global });
    try {
      for (const selector of ['#native', '#link']) {
        const event = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
        document.querySelector(selector)!.dispatchEvent(event); expect(event.defaultPrevented).toBe(false);
      }
      const escape = new MouseEvent('contextmenu', { bubbles: true, cancelable: true, shiftKey: true });
      document.querySelector('#area')!.dispatchEvent(escape); expect(escape.defaultPrevented).toBe(false);
      const event = new KeyboardEvent('keydown', { key: 'F10', shiftKey: true, bubbles: true, cancelable: true });
      document.querySelector('#area')!.dispatchEvent(event); await flush();
      expect(event.defaultPrevented).toBe(true);
      expect(document.querySelector('[data-menu-id="act"]')).not.toBeNull();
    } finally { wrapper.unmount(); }
  });

  it('renders a visible label and supports sizes s, m, l on dropdown', async () => {
    const wrapper = mount(AppDropdown, {
      props: { id: 'label-unit', label: 'Plan label', modelValue: 'one', options, showLabel: true, size: 'l' },
      global,
    });
    expect(wrapper.get('.app-dropdown__label').text()).toBe('Plan label');
    expect(wrapper.get('.app-dropdown').classes()).toContain('app-dropdown--l');
    expect(wrapper.get('.app-dropdown').classes()).toContain('app-dropdown--with-label');
  });

  it('matches dropdown menu panel width to anchor trigger width', async () => {
    const anchor = document.createElement('button');
    anchor.getBoundingClientRect = () => ({
      width: 550,
      height: 44,
      top: 100,
      bottom: 144,
      left: 50,
      right: 600,
      x: 50,
      y: 100,
      toJSON: () => {},
    });
    document.body.appendChild(anchor);
    const wrapper = mount(AppMenu, {
      attachTo: document.body,
      props: { modelValue: true, label: 'Wide menu', anchor, items: [{ id: 'opt1', label: 'Option 1' }] },
      global,
    });
    try {
      await flush();
      const panel = document.querySelector<HTMLElement>('.app-menu__panel')!;
      expect(panel).not.toBeNull();
      expect(panel.style.width).toBe('550px');
    } finally {
      wrapper.unmount();
      anchor.remove();
    }
  });
});
