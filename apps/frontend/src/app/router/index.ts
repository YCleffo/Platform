import { createRouter, createWebHistory, type RouterHistory } from 'vue-router';
import AppLayout from '@/app/layouts/AppLayout.vue';
import AuthLayout from '@/app/layouts/AuthLayout.vue';
import { useAuthStore } from '@/core/auth/authStore';
import { useAdminAuthStore } from '@/core/admin/adminAuthStore';

const CatalogView = () => import('@/modules/commercial/catalog/CatalogView.vue');
const AccountCabinetView = () => import('@/modules/commercial/account/AccountCabinetView.vue');
const AccountLayout = () => import('@/modules/commercial/account/AccountLayout.vue');
const AccountOverviewView = () => import('@/modules/commercial/account/AccountOverviewView.vue');
const AccountMembersView = () => import('@/modules/commercial/account/AccountMembersView.vue');
const AccountBillingView = () => import('@/modules/commercial/billing/AccountBillingView.vue');
const AccountUsageView = () => import('@/modules/commercial/usage/AccountUsageView.vue');
const AccountAnalyticsView = () => import('@/modules/commercial/analytics/AccountAnalyticsView.vue');
const AccountAuditView = () => import('@/modules/commercial/audit/AccountAuditView.vue');
const SecuritySettingsView = () => import('@/modules/commercial/security/SecuritySettingsView.vue');
const InvitationsView = () => import('@/modules/commercial/invitations/InvitationsView.vue');
const InvitationAcceptView = () => import('@/modules/commercial/invitations/InvitationAcceptView.vue');
const LoginView = () => import('@/modules/commercial/auth/LoginView.vue');
const RegisterView = () => import('@/modules/commercial/onboarding/RegisterView.vue');
const VerifyEmailView = () => import('@/modules/commercial/onboarding/VerifyEmailView.vue');
const MfaChallengeView = () => import('@/modules/commercial/auth/MfaChallengeView.vue');
const PasswordResetRequestView = () => import('@/modules/commercial/auth/PasswordResetRequestView.vue');
const PasswordResetConfirmView = () => import('@/modules/commercial/auth/PasswordResetConfirmView.vue');
const ResendVerificationView = () => import('@/modules/commercial/onboarding/ResendVerificationView.vue');
const NotFoundView = () => import('@/app/views/NotFoundView.vue');
const SettingsLayout = () => import('@/modules/settings/SettingsLayout.vue');
const AppearanceSettingsView = () => import('@/modules/settings/AppearanceSettingsView.vue');
const ComponentsSettingsView = () => import('@/modules/settings/ComponentsSettingsView.vue');
const PrivacySettingsView = () => import('@/modules/settings/PrivacySettingsView.vue');

export function createAppRouter(history: RouterHistory = createWebHistory(import.meta.env.BASE_URL)) {
  const router = createRouter({
    history,
    routes: [
      {
        path: '/',
        component: AppLayout,
        children: [
          { path: 'operator', name: 'operator-console', component: () => import('@/modules/commercial/operator/OperatorConsole.vue') },
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
          { path: 'modules', name: 'admin-modules', component: () => import('@/modules/admin/AdminModulesView.vue') },
          { path: 'settings', name: 'admin-settings', component: () => import('@/modules/admin/AdminSettingsView.vue') },
          { path: 'profile', component: () => import('@/modules/admin/profile/CoreProfileLayout.vue'), children: [
            { path: '', name: 'admin-profile', component: () => import('@/modules/admin/profile/CorePreferencesView.vue') },
            { path: 'consents', name: 'admin-profile-consents', component: () => import('@/modules/admin/profile/CoreConsentsView.vue') },
            { path: 'privacy', name: 'admin-profile-privacy', component: () => import('@/modules/admin/profile/CorePrivacyRequestsView.vue') },
            { path: 'privacy/:id', name: 'admin-profile-privacy-request', component: () => import('@/modules/admin/profile/CorePrivacyRequestView.vue') },
          ] },
          { path: 'privacy', name: 'admin-privacy', component: () => import('@/modules/admin/AdminPrivacyView.vue') },
          { path: 'privacy/requests/:id', name: 'admin-privacy-request', component: () => import('@/modules/admin/privacy/PrivacyOperatorRequestView.vue') },
          { path: 'audit', name: 'admin-audit', component: () => import('@/modules/admin/AdminResourcePageView.vue'), meta: { adminResource: 'audit' } },
          { path: 'security', component: () => import('@/modules/admin/AdminSecurityLayout.vue'), children: [
            { path: '', name: 'admin-security', component: () => import('@/modules/admin/AdminSecurityView.vue') },
            { path: 'mfa', name: 'admin-security-mfa', component: () => import('@/modules/admin/AdminMfaView.vue') },
            { path: 'sessions', name: 'admin-security-sessions', component: () => import('@/modules/admin/AdminSessionsView.vue') },
            { path: 'passkeys', name: 'admin-security-passkeys', component: () => import('@/modules/admin/AdminPasskeysView.vue') },
            { path: 'identities', name: 'admin-security-identities', component: () => import('@/modules/admin/AdminIdentitiesView.vue') },
          ] },
          { path: 'finance', name: 'admin-finance', component: () => import('@/modules/admin/AdminFinanceView.vue') },
        ],
      },
      ...[
        { path: '/activate', name: 'core-activation', kind: 'activation' },
        { path: '/password-reset', name: 'core-password-reset', kind: 'password' },
        { path: '/mfa-recovery', name: 'core-mfa-recovery', kind: 'mfa' },
      ].map(({ path, name, kind }) => ({
        path, component: AuthLayout,
        children: [{ path: '', name, component: () => import('@/modules/admin/auth/CoreAuthLinkView.vue'), props: { kind } }],
      })),
      {
        path: '/auth/callback/:provider', component: AuthLayout,
        children: [{ path: '', name: 'core-recovery-callback', component: () => import('@/modules/admin/auth/CoreRecoveryCallbackView.vue') }],
      },
      ...[
        { path: '/admin/forgot-password', name: 'core-password-reset-request', kind: 'password' },
        { path: '/admin/mfa-recovery', name: 'core-mfa-recovery-request', kind: 'mfa' },
      ].map(({ path, name, kind }) => ({
        path, component: AuthLayout,
        children: [{ path: '', name, component: () => import('@/modules/admin/auth/CoreRecoveryRequestView.vue'), props: { kind } }],
      })),
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
