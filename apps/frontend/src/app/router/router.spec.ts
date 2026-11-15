import { beforeEach, describe, expect, it } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory } from 'vue-router';
import { createAppRouter } from './index';
import { useAuthStore } from '@/core/auth/authStore';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';
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

  it('exposes public plan comparison and detail routes', async () => {
    const router = createAppRouter(createMemoryHistory());

    await router.push('/plans');
    expect(router.currentRoute.value.name).toBe('plans');

    await router.push('/plans/start');
    expect(router.currentRoute.value.name).toBe('plan-detail');
    expect(router.currentRoute.value.params.code).toBe('start');

    await router.push('/operator/catalog');
    expect(router.currentRoute.value.name).toBe('operator-catalog');
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

  it('exposes account recovery routes and a catch-all page', async () => {
    const router = createAppRouter(createMemoryHistory());

    await router.push('/forgot-password');
    expect(router.currentRoute.value.name).toBe('password-reset-request');

    await router.push('/reset-password?token=synthetic');
    expect(router.currentRoute.value.name).toBe('password-reset-confirm');
    expect(router.currentRoute.value.query.token).toBe('synthetic');

    await router.push('/resend-verification');
    expect(router.currentRoute.value.name).toBe('resend-verification');

    await router.push('/does-not-exist');
    expect(router.currentRoute.value.name).toBe('not-found');
  });

  it('keeps Education absent by default and uses Core authentication when enabled', async () => {
    const disabled = createAppRouter(createMemoryHistory(), false);
    await disabled.push('/today'); expect(disabled.currentRoute.value.name).toBe('not-found');
    const enabled = createAppRouter(createMemoryHistory(), true);
    await enabled.push('/today'); expect(enabled.currentRoute.value.name).toBe('education-login');
    expect(enabled.currentRoute.value.query.redirect).toBe('/today');
    await enabled.push('/'); expect(enabled.currentRoute.value.name).toBe('catalog');
    const educationNames = enabled.getRoutes().map(route => route.name?.toString()).filter(name => name?.startsWith('education-'));
    expect(educationNames).toEqual(expect.arrayContaining(['education-today', 'education-student-home', 'education-parent-home', 'education-parent-activate', 'education-contexts']));
    expect(new Set(educationNames).size).toBe(educationNames.length);
  });

  it('lets an authenticated Core account reach invitation activation before it has Education roles', async () => {
    const auth = useAdminAuthStore();
    auth.status = 'authenticated';
    auth.identity = { account: { id: accountId, personId: accountId }, roles: [], permissions: [],
      locale: 'en-US', timezone: 'UTC', sessionId: 'synthetic-parent-session' };
    const router = createAppRouter(createMemoryHistory(), true);
    await router.push('/education/login?redirect=/parent/activate');
    expect(router.currentRoute.value.name).toBe('education-parent-activate');
  });
});
