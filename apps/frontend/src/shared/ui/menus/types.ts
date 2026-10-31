import type { Component } from 'vue';

/** IDs must be unique throughout a menu tree. Labels are plain text, never HTML. */
export interface MenuItem {
  id: string;
  label: string;
  kind?: 'item' | 'separator' | 'group';
  description?: string;
  shortcut?: string;
  icon?: Component;
  disabled?: boolean;
  checked?: boolean;
  checkType?: 'radio' | 'checkbox';
  children?: readonly MenuItem[];
}

export interface DropdownOption<Value extends string = string> {
  value: Value;
  label: string;
  description?: string;
  icon?: Component;
  disabled?: boolean;
  children?: readonly DropdownOption<Value>[];
}

/** Search leaves across all levels, retaining their path and inherited disabled state. */
export function searchMenu(items: readonly MenuItem[], query: string, path: string[] = [], disabled = false): MenuItem[] {
  const needle = query.trim().toLocaleLowerCase();
  return items.flatMap(item => {
    if (item.kind === 'separator' || item.kind === 'group') return [];
    if (item.children?.length) return searchMenu(item.children, query, [...path, item.label], disabled || item.disabled === true);
    const breadcrumb = path.join(' / ');
    return `${breadcrumb} ${item.label} ${item.description ?? ''}`.toLocaleLowerCase().includes(needle)
      ? [{ ...item, disabled: disabled || item.disabled, description: [breadcrumb, item.description].filter(Boolean).join(' · ') }]
      : [];
  });
}
