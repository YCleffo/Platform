import { onBeforeUnmount, type Ref } from 'vue';

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export function useFocusTrap(container: Ref<HTMLElement | null>) {
  let lastActive: HTMLElement | null = null;

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
    if (event.key !== 'Tab') {
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
      return;
    }
    const first = items[0];
    const last = items[items.length - 1];
    if (first === undefined || last === undefined) {
      return;
    }
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function activate(): void {
    lastActive = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    document.addEventListener('keydown', onKeydown);
    focusFirst();
  }

  function deactivate(): void {
    document.removeEventListener('keydown', onKeydown);
    lastActive?.focus();
    lastActive = null;
  }

  onBeforeUnmount(deactivate);

  return { activate, deactivate, focusFirst };
}
