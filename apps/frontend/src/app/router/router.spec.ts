import { beforeEach, describe, expect, it } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory } from 'vue-router';
import { createAppRouter } from './index';
import { useAuthStore } from '@/core/auth/authStore';
import { beginSession, endSession } from '@/core/auth/session';

const accountId = '018f0000-0000-7000-8000-000000000001';

describe('router account guard', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    endSession();
  });

  it('redirects unauthenticated account routes to login', async () => {
    const router = createAppRouter(createMemoryHistory());
    await router.push('/accounts/' + accountId);
    await router.isReady();

    expect(router.currentRoute.value.name).toBe('login');
    expect(router.currentRoute.value.query.redirect).toBe('/accounts/' + accountId);
  });

  it('opens a direct account URL when authenticated', async () => {
    beginSession({ access_token: 'access-a', refresh_token: 'refresh-a' });
    const auth = useAuthStore();
    auth.status = 'authenticated';

    const router = createAppRouter(createMemoryHistory());
    await router.push('/accounts/' + accountId);
    await router.isReady();

    expect(router.currentRoute.value.name).toBe('account-overview');
    expect(router.currentRoute.value.params.accountId).toBe(accountId);
  });

  it('keeps /cabinet and / routes working', async () => {
    beginSession({ access_token: 'access-a', refresh_token: 'refresh-a' });
    const auth = useAuthStore();
    auth.status = 'authenticated';

    const router = createAppRouter(createMemoryHistory());
    await router.push('/cabinet');
    expect(router.currentRoute.value.name).toBe('cabinet');

    await router.push('/');
    expect(router.currentRoute.value.name).toBe('catalog');
  });
});
