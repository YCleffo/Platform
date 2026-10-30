import { readonly, ref } from 'vue';

export type ToastKind = 'info' | 'success' | 'error';

export interface Toast {
  id: number;
  message: string;
  kind: ToastKind;
}

const toasts = ref<Toast[]>([]);
let nextId = 1;

function dismiss(id: number): void {
  toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

export function useToast() {
  function show(message: string, kind: ToastKind = 'info', timeoutMs = 5000): number {
    const id = nextId++;
    toasts.value.push({ id, message, kind });
    if (timeoutMs > 0) {
      window.setTimeout(() => dismiss(id), timeoutMs);
    }
    return id;
  }

  return { toasts: readonly(toasts), show, dismiss };
}
