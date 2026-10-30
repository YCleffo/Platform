import { createRouter, createWebHistory, type RouterHistory } from 'vue-router';
import AppLayout from '@/app/layouts/AppLayout.vue';
import AuthLayout from '@/app/layouts/AuthLayout.vue';
import CatalogView from '@/modules/commercial/catalog/CatalogView.vue';
import AccountCabinetView from '@/modules/commercial/account/AccountCabinetView.vue';
import AccountLayout from '@/modules/commercial/account/AccountLayout.vue';
import AccountOverviewView from '@/modules/commercial/account/AccountOverviewView.vue';
import AccountMembersView from '@/modules/commercial/account/AccountMembersView.vue';
import SecuritySettingsView from '@/modules/commercial/security/SecuritySettingsView.vue';
import InvitationsView from '@/modules/commercial/invitations/InvitationsView.vue';
import InvitationAcceptView from '@/modules/commercial/invitations/InvitationAcceptView.vue';
import LoginView from '@/modules/commercial/auth/LoginView.vue';
import RegisterView from '@/modules/commercial/onboarding/RegisterView.vue';
import VerifyEmailView from '@/modules/commercial/onboarding/VerifyEmailView.vue';
import MfaChallengeView from '@/modules/commercial/auth/MfaChallengeView.vue';
import { useAuthStore } from '@/core/auth/authStore';

export function createAppRouter(history: RouterHistory = createWebHistory(import.meta.env.BASE_URL)) {
  const router = createRouter({
    history,
    routes: [
      {
        path: '/',
        component: AppLayout,
        children: [
          { path: '', name: 'catalog', component: CatalogView },
          { path: 'cabinet', name: 'cabinet', component: AccountCabinetView, meta: { requiresAuth: true } },
          {
            path: 'settings/security',
            name: 'security',
            component: SecuritySettingsView,
            meta: { requiresAuth: true },
          },
          {
            path: 'accounts/:accountId',
            component: AccountLayout,
            meta: { requiresAuth: true },
            children: [
              {
                path: '',
                name: 'account-overview',
                component: AccountOverviewView,
                meta: { requiresAuth: true },
              },
              {
                path: 'members',
                name: 'account-members',
                component: AccountMembersView,
                meta: { requiresAuth: true },
              },
              {
                path: 'invitations',
                name: 'account-invitations',
                component: InvitationsView,
                meta: { requiresAuth: true },
              },
            ],
          },
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
      {
        path: '/invitation',
        component: AuthLayout,
        children: [{ path: '', name: 'invitation', component: InvitationAcceptView }],
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
    if (to.name === 'mfa' && auth.status !== 'mfa_required') {
      return { name: 'login' };
    }
    return true;
  });

  return router;
}

export const router = createAppRouter();
