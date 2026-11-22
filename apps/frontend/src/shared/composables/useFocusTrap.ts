import { onBeforeUnmount, type Ref } from 'vue';

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

const activeTraps: Array<() => void> = [];

export function useFocusTrap(container: Ref<HTMLElement | null>) {
  let lastActive: HTMLElement | null = null;

  function isActive(): boolean {
    return activeTraps.at(-1) === focusFirst;
  }

  function focusFirst(): void {
    const el = container.value;
    if (el === null) {
      return;
    }
    const first = el.querySelector<HTMLElement>(FOCUSABLE);
    if (first !== null) {
      first.focus();
    } else {
      el.focus();
    }
  }

  function onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Tab' || event.defaultPrevented || !isActive()) {
      return;
    }
    const el = container.value;
    if (el === null) {
      return;
    }
    const items = Array.from(el.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
      (node) => node.offsetParent !== null || node === document.activeElement,
    );
    if (items.length === 0) {
      event.preventDefault();
      el.focus();
      return;
    }
    const first = items[0];
    const last = items[items.length - 1];
    if (first === undefined || last === undefined) {
      return;
    }
    if (!el.contains(document.activeElement)) {
      event.preventDefault();
      (event.shiftKey ? last : first).focus();
    } else if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function activate(): void {
    if (container.value === null || activeTraps.includes(focusFirst)) return;
    lastActive = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    activeTraps.push(focusFirst);
    document.addEventListener('keydown', onKeydown);
    focusFirst();
  }

  function deactivate(): void {
    const position = activeTraps.indexOf(focusFirst);
    if (position === -1) return;
    const restoreFocus = isActive();
    activeTraps.splice(position, 1);
    document.removeEventListener('keydown', onKeydown);
    if (restoreFocus) {
      if (lastActive?.isConnected && !lastActive.closest('[inert]')) lastActive.focus();
      else activeTraps.at(-1)?.();
    }
    lastActive = null;
  }

  onBeforeUnmount(deactivate);

  return { activate, deactivate, focusFirst, isActive };
}
