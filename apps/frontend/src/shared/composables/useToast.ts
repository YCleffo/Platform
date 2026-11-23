import { readonly, ref } from 'vue';

export type ToastKind = 'info' | 'success' | 'warning' | 'error';

export interface Toast {
  id: number;
  message: string;
  kind: ToastKind;
}

const MAX_VISIBLE = 4;
const toasts = ref<Toast[]>([]);
let nextId = 1;
const timers = new Map<number, ReturnType<typeof setTimeout>>();

function clearTimer(id: number): void {
  const timer = timers.get(id);
  if (timer !== undefined) {
    clearTimeout(timer);
    timers.delete(id);
  }
}

function dismiss(id: number): void {
  clearTimer(id);
  toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

/** Clears every queued notification (e.g. on logout / user switch). */
export function clearToasts(): void {
  for (const timer of timers.values()) {
    clearTimeout(timer);
  }
  timers.clear();
  toasts.value = [];
}

export function useToast() {
  function show(message: string, kind: ToastKind = 'info', timeoutMs = 5000): number {
    const id = nextId++;
    toasts.value = [...toasts.value, { id, message, kind }];
    while (toasts.value.length > MAX_VISIBLE) {
      const oldest = toasts.value[0];
      if (oldest === undefined) {
        break;
      }
      dismiss(oldest.id);
    }
    if (timeoutMs > 0) {
      timers.set(
        id,
        setTimeout(() => dismiss(id), timeoutMs),
      );
    }
    return id;
  }

  function success(message: string, timeoutMs = 5000): number {
    return show(message, 'success', timeoutMs);
  }

  function error(message: string, timeoutMs = 5000): number {
    return show(message, 'error', timeoutMs);
  }

  function info(message: string, timeoutMs = 5000): number {
    return show(message, 'info', timeoutMs);
  }

  function warning(message: string, timeoutMs = 5000): number {
    return show(message, 'warning', timeoutMs);
  }

  return { toasts: readonly(toasts), show, dismiss, success, error, info, warning };
}
