import { onBeforeUnmount } from 'vue';
import { coreAdminSession } from '@/core/admin/session';

/** A component's data and side effects belong to its mounting session and current operation. */
export function useAdminOperation() {
  const epoch = coreAdminSession.epoch();
  let active = true;
  let generation = 0;
  onBeforeUnmount(() => { active = false; generation += 1; });
  function current(version: number): boolean {
    return active && version === generation && epoch === coreAdminSession.epoch();
  }
  return { begin: () => ++generation, current };
}
