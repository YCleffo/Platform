import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { useAuthStore } from '@/core/auth/authStore';
import { i18n } from '@/shared/i18n';
import MfaChallengeView from './MfaChallengeView.vue';

describe('MfaChallengeView', () => {
  let router: ReturnType<typeof createRouter>;

  beforeEach(() => {
    setActivePinia(createPinia());
    router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/mfa', name: 'mfa', component: MfaChallengeView },
        { path: '/login', name: 'login', component: { template: '<div>login</div>' } },
        { path: '/cabinet', name: 'cabinet', component: { template: '<div>cabinet</div>' } },
      ],
    });
  });

  it('redirects to login if there is no active MFA challenge', async () => {
    const auth = useAuthStore();
    auth.mfaChallenge = null;

    mount(MfaChallengeView, {
      global: {
        plugins: [router, i18n],
      },
    });

    await router.isReady();
    expect(router.currentRoute.value.name).toBe('login');
  });

  it('renders 6-cell code input by default and toggles to recovery mode and back', async () => {
    const auth = useAuthStore();
    auth.mfaChallenge = {
      status: 'mfa_required',
      challenge_token: 'test-challenge',
      expires_in: 300,
    };

    const wrapper = mount(MfaChallengeView, {
      global: {
        plugins: [router, i18n],
      },
    });

    // 6-cell code input is present by default
    const codeInput = wrapper.find('#mfa-code');
    expect(codeInput.exists()).toBe(true);
    const cells = wrapper.findAll('.mfa-pin__cell');
    expect(cells.length).toBe(6);

    // Recovery input is not visible by default
    expect(wrapper.find('#mfa-recovery').exists()).toBe(false);

    // Type digits into the code input
    await codeInput.setValue('12345');
    await codeInput.trigger('input');

    const digits = wrapper.findAll('.mfa-pin__digit');
    expect(digits.map((d) => d.text())).toEqual(['1', '2', '3', '4', '5', '']);

    // Click link to switch to recovery mode
    const toggleRecovery = wrapper.find('#mfa-toggle-recovery');
    expect(toggleRecovery.exists()).toBe(true);
    await toggleRecovery.trigger('click');

    // Now recovery code input is visible, 6-cell input is gone
    expect(wrapper.find('#mfa-recovery').exists()).toBe(true);
    expect(wrapper.find('#mfa-code').exists()).toBe(false);

    // Click link to switch back to authenticator code
    const toggleCode = wrapper.find('#mfa-toggle-code');
    expect(toggleCode.exists()).toBe(true);
    await toggleCode.trigger('click');

    expect(wrapper.find('#mfa-code').exists()).toBe(true);
    expect(wrapper.find('#mfa-recovery').exists()).toBe(false);
  });

  it('validates and submits 6-digit code in code mode', async () => {
    const auth = useAuthStore();
    auth.mfaChallenge = {
      status: 'mfa_required',
      challenge_token: 'test-challenge',
      expires_in: 300,
    };
    const verifySpy = vi.spyOn(auth, 'verifyMfa').mockResolvedValue(undefined);

    const wrapper = mount(MfaChallengeView, {
      global: {
        plugins: [router, i18n],
      },
    });

    // Reject if less than 6 digits
    await wrapper.find('#mfa-code').setValue('123');
    await wrapper.find('form').trigger('submit');
    expect(verifySpy).not.toHaveBeenCalled();

    // Submit valid 6 digits
    await wrapper.find('#mfa-code').setValue('654321');
    expect(verifySpy).toHaveBeenCalledWith('654321', undefined, expect.any(AbortSignal));
  });

  it('automatically verifies when 6 digits are typed', async () => {
    const auth = useAuthStore();
    auth.mfaChallenge = {
      status: 'mfa_required',
      challenge_token: 'test-challenge',
      expires_in: 300,
    };
    const verifySpy = vi.spyOn(auth, 'verifyMfa').mockResolvedValue(undefined);

    const wrapper = mount(MfaChallengeView, {
      global: {
        plugins: [router, i18n],
      },
    });

    const codeInput = wrapper.find('#mfa-code');
    await codeInput.setValue('123456');

    expect(verifySpy).toHaveBeenCalledWith('123456', undefined, expect.any(AbortSignal));
  });

  it('validates and submits recovery code in recovery mode', async () => {
    const auth = useAuthStore();
    auth.mfaChallenge = {
      status: 'mfa_required',
      challenge_token: 'test-challenge',
      expires_in: 300,
    };
    const verifySpy = vi.spyOn(auth, 'verifyMfa').mockResolvedValue(undefined);

    const wrapper = mount(MfaChallengeView, {
      global: {
        plugins: [router, i18n],
      },
    });

    // Switch to recovery mode
    await wrapper.find('#mfa-toggle-recovery').trigger('click');

    // Reject if empty
    await wrapper.find('form').trigger('submit');
    expect(verifySpy).not.toHaveBeenCalled();

    // Submit valid recovery code
    await wrapper.find('#mfa-recovery').setValue('rec-code-1234');
    await wrapper.find('form').trigger('submit');
    expect(verifySpy).toHaveBeenCalledWith(undefined, 'rec-code-1234', expect.any(AbortSignal));
  });
});
