import type { RouteLocationRaw } from 'vue-router';
export interface Breadcrumb { label: string; to?: RouteLocationRaw }
export function breadcrumbs(name: unknown, params: Record<string, unknown>, t: (key: string) => string, fallback = ''): Breadcrumb[] {
  const route = typeof name === 'string' ? name : '';
  const item = (key: string, to?: RouteLocationRaw): Breadcrumb => ({ label: t(key), to });
  if (route.startsWith('admin-')) {
    const root = item('navigation.core', { name: 'admin-dashboard' });
    const security: Record<string, string> = { mfa: 'mfa', sessions: 'sessions', passkeys: 'passkeys', identities: 'identities' };
    const profile: Record<string, string> = { consents: 'consents', privacy: 'privacy' };
    if (route === 'admin-dashboard') return [item('admin.dashboard')];
    if (route.startsWith('admin-security-')) return [root, item('admin.security', { name: 'admin-security' }), item('navigation.' + (security[route.slice(15)] ?? 'security'))];
    if (route.startsWith('admin-profile-')) {
      const chain = [root, item('admin.profile', { name: 'admin-profile' })];
      if (route === 'admin-profile-privacy-request') return [...chain, item('navigation.privacy', { name: 'admin-profile-privacy' }), item('navigation.request')];
      return [...chain, item('navigation.' + (profile[route.slice(14)] ?? 'profile'))];
    }
    if (route === 'admin-privacy-request') return [root, item('admin.privacy', { name: 'admin-privacy' }), item('navigation.request')];
    if (route === 'admin-privacy-registry') return [root, item('admin.privacy', { name: 'admin-privacy' }), item('navigation.privacyRegistry')];
    return [root, { label: fallback || t('navigation.' + route.slice(6)) }];
  }
  if (route.startsWith('account-')) {
    const accountId = typeof params.accountId === 'string' ? params.accountId : '';
    const chain = [item('nav.cabinet', { name: 'cabinet' })];
    if (route === 'account-overview') return [...chain, item('navigation.account')];
    return [...chain, item('navigation.account', { name: 'account-overview', params: { accountId } }), item('navigation.' + route.slice(8))];
  }
  if (['security', 'privacy', 'settings-components', 'settings-developer'].includes(route)) return [item('nav.settings', { name: 'settings' }), item('navigation.' + (route === 'settings-components' ? 'components' : route === 'settings-developer' ? 'developer' : route))];
  if (route === 'operator-console') return [item('app.name', { name: 'catalog' }), item('navigation.operator')];
  if (route === 'catalog') return [item('nav.catalog')];
  if (route === 'workspace') return [item('navigation.workspace')];
  if (route === 'search') return [item('navigation.workspace', { name: 'workspace' }), item('navigation.search')];
  if (route === 'trash') return [item('navigation.workspace', { name: 'workspace' }), item('navigation.trash')];
  if (route === 'universal-notifications') return [item('navigation.workspace', { name: 'workspace' }), item('navigation.notifications')];
  if (route === 'universal-chat' || route === 'universal-chat-room') return [item('navigation.workspace', { name: 'workspace' }), item('navigation.chat')];
  if (route.startsWith('universal-activit')) return [item('navigation.workspace', { name: 'workspace' }), item('navigation.activities')];
  if (route === 'drive') return [item('nav.drive')];
  if (route === 'plans') return [item('nav.catalog', { name: 'catalog' }), item('navigation.comparePlans')];
  if (route === 'plan-detail') {
    const code = typeof params.code === 'string' ? params.code : '';
    return [item('nav.catalog', { name: 'catalog' }), item('navigation.comparePlans', { name: 'plans' }), { label: code }];
  }
  if (route === 'cabinet') return [item('nav.cabinet')];
  if (route === 'settings') return [item('nav.settings')];
  return fallback ? [{ label: fallback }] : [];
}
