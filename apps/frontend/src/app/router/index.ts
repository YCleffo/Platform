import { createRouter, createWebHistory } from 'vue-router';
import AppLayout from '@/app/layouts/AppLayout.vue';
import AuthLayout from '@/app/layouts/AuthLayout.vue';
import CatalogView from '@/modules/commercial/catalog/CatalogView.vue';
import AccountCabinetView from '@/modules/commercial/account/AccountCabinetView.vue';
import LoginView from '@/modules/commercial/auth/LoginView.vue';
import RegisterView from '@/modules/commercial/onboarding/RegisterView.vue';
import VerifyEmailView from '@/modules/commercial/onboarding/VerifyEmailView.vue';
import MfaChallengeView from '@/modules/commercial/auth/MfaChallengeView.vue';
import { useAuthStore } from '@/core/auth/authStore';

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      component: AppLayout,
      children: [
        { path: '', name: 'catalog', component: CatalogView },
        { path: 'cabinet', name: 'cabinet', component: AccountCabinetView, meta: { requiresAuth: true } },
      ],
    },
    {
      path: '/login',
      component: AuthLayout,
      children: [{ path: '', name: 'login', component: LoginView }],
    },
    {
      path: '/register',
      component: AuthLayout,
      children: [{ path: '', name: 'register', component: RegisterView }],
    },
    {
      path: '/verify-email',
      component: AuthLayout,
      children: [{ path: '', name: 'verify-email', component: VerifyEmailView }],
    },
    {
      path: '/mfa',
      component: AuthLayout,
      children: [{ path: '', name: 'mfa', component: MfaChallengeView }],
    },
  ],
});

router.beforeEach((to) => {
  const auth = useAuthStore();
  if (to.meta.requiresAuth === true && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } };
  }
  if (to.name === 'login' && auth.isAuthenticated) {
    return { name: 'cabinet' };
  }
  return true;
});
