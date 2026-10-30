import { describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { ApiError } from '@/shared/api/errors';
import { useSensitiveAction } from './useSensitiveAction';

function createState() {
  const scope = effectScope();
  const state = scope.run(() => useSensitiveAction());
  if (state === undefined) {
    throw new Error('Effect scope failed.');
  }
  return { scope, state };
}

describe('useSensitiveAction', () => {
  it('requests step-up once and retries the action', async () => {
    let attempts = 0;
    const action = vi.fn(async () => {
      attempts += 1;
      if (attempts === 1) {
        throw new ApiError(403, 'recent_authentication_required', 'step up');
      }
    });
    const { scope, state } = createState();

    const run = state.run(action);
    await vi.waitFor(() => expect(state.reauthOpen.value).toBe(true));
    expect(state.busy.value).toBe(true);

    await state.resume();
    await expect(run).resolves.toBe(true);
    expect(action).toHaveBeenCalledTimes(2);
    expect(state.busy.value).toBe(false);
    scope.stop();
  });

  it('resolves a cancelled step-up without retrying', async () => {
    const action = vi.fn(async () => {
      throw new ApiError(403, 'recent_mfa_required', 'step up');
    });
    const { scope, state } = createState();

    const run = state.run(action);
    await vi.waitFor(() => expect(state.reauthOpen.value).toBe(true));
    state.cancel();

    await expect(run).resolves.toBe(false);
    expect(action).toHaveBeenCalledTimes(1);
    expect(state.reauthOpen.value).toBe(false);
    scope.stop();
  });

  it('surfaces the MFA enrollment requirement without looping', async () => {
    const action = vi.fn(async () => {
      throw new ApiError(403, 'mfa_enrollment_required', 'enroll');
    });
    const { scope, state } = createState();

    await expect(state.run(action)).resolves.toBe(false);
    expect(state.needsMfaEnrollment.value).toBe(true);
    expect(state.error.value).toBeInstanceOf(ApiError);
    expect(state.reauthOpen.value).toBe(false);
    scope.stop();
  });

  it('does not loop step-up when the retried action fails again', async () => {
    const action = vi.fn(async () => {
      throw new ApiError(403, 'recent_authentication_required', 'step up');
    });
    const { scope, state } = createState();

    const run = state.run(action);
    await vi.waitFor(() => expect(state.reauthOpen.value).toBe(true));
    await state.resume();

    await expect(run).resolves.toBe(false);
    expect(action).toHaveBeenCalledTimes(2);
    expect(state.reauthOpen.value).toBe(false);
    expect(state.error.value).toBeInstanceOf(ApiError);
    scope.stop();
  });

  it('reports other failures without a step-up', async () => {
    const action = vi.fn(async () => {
      throw new ApiError(409, 'operation_rejected', 'rejected');
    });
    const { scope, state } = createState();

    await expect(state.run(action)).resolves.toBe(false);
    expect(state.error.value).toBeInstanceOf(ApiError);
    expect(state.reauthOpen.value).toBe(false);
    scope.stop();
  });
});
