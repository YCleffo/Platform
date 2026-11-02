import { createRouter, createWebHistory, type RouterHistory } from 'vue-router';
import AppLayout from '@/app/layouts/AppLayout.vue';
import AuthLayout from '@/app/layouts/AuthLayout.vue';
import CatalogView from '@/modules/commercial/catalog/CatalogView.vue';
import AccountCabinetView from '@/modules/commercial/account/AccountCabinetView.vue';
import AccountLayout from '@/modules/commercial/account/AccountLayout.vue';
import AccountOverviewView from '@/modules/commercial/account/AccountOverviewView.vue';
import AccountMembersView from '@/modules/commercial/account/AccountMembersView.vue';
import AccountBillingView from '@/modules/commercial/billing/AccountBillingView.vue';
import AccountUsageView from '@/modules/commercial/usage/AccountUsageView.vue';
import AccountAnalyticsView from '@/modules/commercial/analytics/AccountAnalyticsView.vue';
import AccountAuditView from '@/modules/commercial/audit/AccountAuditView.vue';
import SecuritySettingsView from '@/modules/commercial/security/SecuritySettingsView.vue';
import InvitationsView from '@/modules/commercial/invitations/InvitationsView.vue';
import InvitationAcceptView from '@/modules/commercial/invitations/InvitationAcceptView.vue';
import LoginView from '@/modules/commercial/auth/LoginView.vue';
import RegisterView from '@/modules/commercial/onboarding/RegisterView.vue';
import VerifyEmailView from '@/modules/commercial/onboarding/VerifyEmailView.vue';
import MfaChallengeView from '@/modules/commercial/auth/MfaChallengeView.vue';
import PasswordResetRequestView from '@/modules/commercial/auth/PasswordResetRequestView.vue';
import PasswordResetConfirmView from '@/modules/commercial/auth/PasswordResetConfirmView.vue';
import ResendVerificationView from '@/modules/commercial/onboarding/ResendVerificationView.vue';
import NotFoundView from '@/app/views/NotFoundView.vue';
import SettingsLayout from '@/modules/settings/SettingsLayout.vue';
import AppearanceSettingsView from '@/modules/settings/AppearanceSettingsView.vue';
import ComponentsSettingsView from '@/modules/settings/ComponentsSettingsView.vue';
import PrivacySettingsView from '@/modules/settings/PrivacySettingsView.vue';
import { useAuthStore } from '@/core/auth/authStore';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';

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
            path: 'settings', component: SettingsLayout, meta: { primaryNavigation: 'settings' },
            children: [
              { path: '', name: 'settings', component: AppearanceSettingsView },
              { path: 'components', name: 'settings-components', component: ComponentsSettingsView },
              { path: 'security', name: 'security', component: SecuritySettingsView, meta: { requiresAuth: true } },
              { path: 'privacy', name: 'privacy', component: PrivacySettingsView, meta: { requiresAuth: true } },
            ],
          },
          {
            path: 'accounts/:accountId',
            component: AccountLayout,
            meta: { requiresAuth: true, primaryNavigation: 'cabinet' },
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
                path: 'billing',
                name: 'account-billing',
                component: AccountBillingView,
                meta: { requiresAuth: true },
              },
              {
                path: 'usage',
                name: 'account-usage',
                component: AccountUsageView,
                meta: { requiresAuth: true },
              },
              {
                path: 'analytics',
                name: 'account-analytics',
                component: AccountAnalyticsView,
                meta: { requiresAuth: true },
              },
              {
                path: 'audit',
                name: 'account-audit',
                component: AccountAuditView,
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
          { path: ':pathMatch(.*)*', name: 'not-found', component: NotFoundView },
        ],
      },
      {
        path: '/login',
        component: AuthLayout,
        children: [{ path: '', name: 'login', component: LoginView }],
      },
      {
        path: '/admin/login',
        component: AuthLayout,
        children: [{ path: '', name: 'admin-login', component: () => import('@/modules/admin/AdminLoginView.vue') }],
      },
      {
        path: '/admin',
        component: () => import('@/modules/admin/AdminLayout.vue'),
        meta: { requiresAdminAuth: true },
        children: [
          { path: '', name: 'admin-dashboard', component: () => import('@/modules/admin/AdminDashboardView.vue') },
          { path: 'organizations', name: 'admin-organizations', component: () => import('@/modules/admin/AdminResourcePageView.vue'), meta: { adminResource: 'organizations' } },
          { path: 'persons', name: 'admin-persons', component: () => import('@/modules/admin/AdminResourcePageView.vue'), meta: { adminResource: 'persons' } },
          { path: 'accounts', name: 'admin-accounts', component: () => import('@/modules/admin/AdminResourcePageView.vue'), meta: { adminResource: 'accounts' } },
          { path: 'rbac', name: 'admin-rbac', component: () => import('@/modules/admin/AdminRbacView.vue') },
          { path: 'modules', name: 'admin-modules', component: () => import('@/modules/admin/AdminResourcePageView.vue'), meta: { adminResource: 'modules' } },
          { path: 'settings', name: 'admin-settings', component: () => import('@/modules/admin/AdminSettingsView.vue') },
          { path: 'privacy', name: 'admin-privacy', component: () => import('@/modules/admin/AdminPrivacyView.vue') },
          { path: 'audit', name: 'admin-audit', component: () => import('@/modules/admin/AdminResourcePageView.vue'), meta: { adminResource: 'audit' } },
          { path: 'security', name: 'admin-security', component: () => import('@/modules/admin/AdminSecurityView.vue') },
          { path: 'finance', name: 'admin-finance', component: () => import('@/modules/admin/AdminFinanceView.vue') },
        ],
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
        path: '/resend-verification',
        component: AuthLayout,
        children: [{ path: '', name: 'resend-verification', component: ResendVerificationView }],
      },
      {
        path: '/forgot-password',
        component: AuthLayout,
        children: [{ path: '', name: 'password-reset-request', component: PasswordResetRequestView }],
      },
      {
        path: '/reset-password',
        component: AuthLayout,
        children: [{ path: '', name: 'password-reset-confirm', component: PasswordResetConfirmView }],
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
    const adminAuth = useAdminAuthStore();
    if (to.matched.some(record => record.meta.requiresAdminAuth === true) && !adminAuth.isAuthenticated) {
      return { name: 'admin-login', query: { redirect: to.fullPath } };
    }
    if (to.name === 'admin-login' && adminAuth.isAuthenticated) {
      return { name: 'admin-dashboard' };
    }

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
