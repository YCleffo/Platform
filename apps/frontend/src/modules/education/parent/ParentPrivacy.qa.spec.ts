import { createPinia, setActivePinia } from 'pinia';
import { effectScope, nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { coreAdminSession } from '@/core/admin/session';
import { ApiError } from '@/shared/api/errors';
import { parentApi, type ParentContext } from './api';
import { useParentWorkspace } from './workspace';
import { useParentResource } from './resources';

vi.mock('./api', () => ({ parentApi: { context: vi.fn() } }));

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((accept, fail) => { resolve = accept; reject = fail; });
  return { promise, resolve, reject };
}

const first = { id: '019a0000-0000-7000-8000-000000000001', personId: '019a0000-0000-7000-8000-000000000011', name: 'First child', organizationId: '019a0000-0000-7000-8000-000000000101', organizationName: 'First school' };
const second = { id: '019a0000-0000-7000-8000-000000000002', personId: '019a0000-0000-7000-8000-000000000012', name: 'Second child', organizationId: '019a0000-0000-7000-8000-000000000102', organizationName: 'Second school' };
const context = { children: [first, second], personId: '019a0000-0000-7000-8000-000000000099', displayName: 'Parent QA', locale: 'en-US', timezone: 'UTC' } as ParentContext;
const key = (child: typeof first) => `${child.organizationId}:${child.id}`;
const tokens = { accessToken: 'parent-access', refreshToken: 'parent-refresh', accessExpiresAt: '', sessionExpiresAt: '', sessionId: 'parent-session' };
const scopes: ReturnType<typeof effectScope>[] = [];
function scope() { const active = effectScope(); scopes.push(active); return active; }
async function settle() { await nextTick(); await nextTick(); await nextTick(); }
async function ready() {
  vi.mocked(parentApi.context).mockResolvedValue(context);
  const workspace = useParentWorkspace();
  await workspace.loadContext();
  workspace.chooseChild(key(first));
  await settle();
  return workspace;
}

beforeEach(() => { setActivePinia(createPinia()); coreAdminSession.set(tokens); vi.clearAllMocks(); });
afterEach(() => { scopes.splice(0).forEach(active => active.stop()); coreAdminSession.clear(); });

describe('Parent private reads across children and sessions', () => {
  it('uses both organization and child identity when switching children', async () => {
    const workspace = await ready();
    expect(workspace.child?.id).toBe(first.id);
    workspace.chooseChild(key(second));
    expect(workspace.child?.id).toBe(second.id);
    expect(workspace.child?.organizationId).toBe(second.organizationId);
    workspace.chooseChild(`${first.organizationId}:${second.id}`);
    expect(workspace.child === null || workspace.child.organizationId === second.organizationId).toBe(true);
  });

  it('clears the first child immediately and cannot install its stale response after a cross-school switch', async () => {
    const workspace = await ready();
    const old = deferred<string>(), current = deferred<string>();
    const signals: AbortSignal[] = [];
    const load = vi.fn((child: { id: string }, signal: AbortSignal) => {
      signals.push(signal);
      return child.id === first.id ? old.promise : current.promise;
    });
    const resource = scope().run(() => useParentResource(load))!;
    workspace.chooseChild(key(second));
    expect(resource.data.value).toBeNull();
    expect(signals[0]?.aborted).toBe(true);
    await settle();
    current.resolve('Second child diary'); await settle();
    old.resolve('Private first child diary'); await settle();
    expect(resource.data.value).toBe('Second child diary');
    expect(resource.error.value).toBeNull();
  });

  it('clears loaded family data on logout and rejects a pending refresh from the previous session', async () => {
    const workspace = await ready();
    const stale = deferred<string>();
    const load = vi.fn().mockResolvedValueOnce('First child homework').mockReturnValueOnce(stale.promise);
    const resource = scope().run(() => useParentResource<string>(load))!;
    await settle();
    expect(resource.data.value).toBe('First child homework');
    const pending = resource.refresh();
    coreAdminSession.clear();
    expect(resource.data.value).toBeNull();
    await settle();
    expect(workspace.child).toBeNull();
    expect(workspace.context).toBeNull();
    stale.resolve('Old session private answer'); await pending; await settle();
    expect(resource.data.value).toBeNull();
  });

  it('does not restore family context when a slow context request finishes after logout', async () => {
    const pending = deferred<ParentContext>();
    vi.mocked(parentApi.context).mockReturnValue(pending.promise);
    const workspace = useParentWorkspace();
    const request = workspace.loadContext();
    coreAdminSession.clear();
    pending.resolve(context); await request; await settle();
    expect(workspace.context).toBeNull();
    expect(workspace.child).toBeNull();
  });

  it('clears the selected child after its confirmed relationship disappears on context reload', async () => {
    const workspace = await ready();
    const resource = scope().run(() => useParentResource(() => Promise.resolve('Private first child diary')))!;
    await settle();
    expect(resource.data.value).not.toBeNull();
    vi.mocked(parentApi.context).mockResolvedValue({ ...context, children: [] });
    await workspace.loadContext(); await settle();
    expect(workspace.child).toBeNull();
    expect(resource.data.value).toBeNull();
  });

  it('removes a child after an access denial and cannot keep its earlier successful data', async () => {
    const workspace = await ready();
    const load = vi.fn().mockResolvedValueOnce('Allowed before revoke').mockRejectedValueOnce(new ApiError(403, 'education.access_denied', 'Revoked'));
    const resource = scope().run(() => useParentResource<string>(load))!;
    await settle();
    expect(resource.data.value).toBe('Allowed before revoke');
    await resource.refresh(); await settle();
    expect(resource.data.value).toBeNull();
    expect(workspace.child?.id).not.toBe(first.id);
  });

  it('cannot overwrite a newer response when two refreshes finish out of order', async () => {
    await ready();
    const firstResponse = deferred<string>(), secondResponse = deferred<string>();
    const load = vi.fn().mockReturnValueOnce(firstResponse.promise).mockReturnValueOnce(secondResponse.promise);
    const resource = scope().run(() => useParentResource<string>(load))!;
    const refresh = resource.refresh();
    secondResponse.resolve('Newest reviewed result'); await refresh; await settle();
    firstResponse.resolve('Obsolete result'); await settle();
    expect(resource.data.value).toBe('Newest reviewed result');
  });
});
