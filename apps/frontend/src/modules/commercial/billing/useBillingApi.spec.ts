import { defineComponent, ref } from 'vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { endSession } from '@/core/auth/session';
import type { CommercialBillingApi } from '@/shared/api/commercial/billing';
import { SupersededOperationError } from '@/shared/api/errors';
import { useBillingApi } from './useBillingApi';

describe('billing operation identity boundary', () => {
  function setup() {
    const account = ref('a');
    let resolve!: (value: unknown) => void;
    const state = vi.fn(() => new Promise(done => { resolve = done; }));
    let scoped!: CommercialBillingApi;
    const wrapper = mount(defineComponent({ setup() { scoped = useBillingApi({ state } as unknown as CommercialBillingApi, () => account.value); return () => null; } }));
    return { account, state, wrapper, api: scoped, resolve: (value: unknown) => resolve(value) };
  }
  it('rejects late A responses even after switching A to B to A', async () => {
    const test = setup();
    const result = test.api.state('a');
    test.account.value = 'b'; test.account.value = 'a'; test.resolve({ private: 'a' });
    await expect(result).rejects.toBeInstanceOf(SupersededOperationError);
    test.wrapper.unmount();
  });
  it('does not start a chained mutation for another account', async () => {
    const test = setup();
    await expect(test.api.state('b')).rejects.toBeInstanceOf(SupersededOperationError);
    expect(test.state).not.toHaveBeenCalled(); test.wrapper.unmount();
  });
  it('rejects a settlement after unmount', async () => {
    const test = setup(); const result = test.api.state('a'); test.wrapper.unmount(); test.resolve({});
    await expect(result).rejects.toBeInstanceOf(SupersededOperationError);
  });
  it('rejects a settlement after session replacement', async () => {
    const test = setup(); const result = test.api.state('a'); endSession(); test.resolve({});
    await expect(result).rejects.toBeInstanceOf(SupersededOperationError); test.wrapper.unmount();
  });
});
