import { describe, expect, it } from 'vitest';
import { breadcrumbs } from './breadcrumbs';
const t = (key: string) => key;
describe('header breadcrumbs', () => {
  it('keeps actual parents for a deep privacy request', () => {
    const trail = breadcrumbs('admin-profile-privacy-request', { id: 'private-record-id' }, t);
    expect(trail.map((item) => item.label)).toEqual(['navigation.core', 'admin.profile', 'navigation.privacy', 'navigation.request']);
    expect(trail.some((item) => item.label.includes('private-record-id'))).toBe(false);
  });
  it('uses account IDs only in route parameters, never as labels', () => {
    const trail = breadcrumbs('account-billing', { accountId: 'account-id' }, t);
    expect(trail.map((item) => item.label)).toEqual(['nav.cabinet', 'navigation.account', 'navigation.billing']);
    expect(trail[1]?.to).toEqual({ name: 'account-overview', params: { accountId: 'account-id' } });
  });
  it('uses the actual security leaf and leaves the root unlinked', () => {
    expect(breadcrumbs('admin-security-mfa', {}, t).at(-1)?.label).toBe('navigation.mfa');
    expect(breadcrumbs('admin-dashboard', {}, t)).toEqual([{ label: 'admin.dashboard' }]);
  });
});
