let lockCount = 0;
let previousOverflow = '';

/** Nested-safe scroll lock for overlays; restores the previous overflow value. */
export function lockScroll(): void {
  if (lockCount === 0) {
    previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
  }
  lockCount += 1;
}

export function unlockScroll(): void {
  if (lockCount === 0) {
    return;
  }
  lockCount -= 1;
  if (lockCount === 0) {
    document.body.style.overflow = previousOverflow;
    previousOverflow = '';
  }
}

/** Testing helper: clears nested-lock bookkeeping between isolated mounts. */
export function resetScrollLock(): void {
  lockCount = 0;
  previousOverflow = '';
}
