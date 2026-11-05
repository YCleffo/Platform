import { nextTick, reactive } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { i18n } from '@/shared/i18n';
import { endSession } from '@/core/auth/session';
import type { AccountAnalytics, AuditEvent, AuditEventPage } from '@/shared/types';
import Analytics from '../analytics/AccountAnalyticsView.vue';
import Audit from '../audit/AccountAuditView.vue';

const api = vi.hoisted(() => ({ get: vi.fn(), events: vi.fn() }));
vi.mock('@/core/api', () => ({ commercialAnalytics: { get: api.get }, commercialAudit: { events: api.events } }));
const route = reactive({ params: { accountId: 'a' } });
vi.mock('vue-router', () => ({ useRoute: () => route }));
function deferred<T>() {
  let resolve!: (value: T) => void; let reject!: (reason: Error) => void;
  const promise = new Promise<T>((done, fail) => { resolve = done; reject = fail; });
  return { promise, resolve, reject };
}
function analytics(days: number, total: number): AccountAnalytics {
  return { account_id: 'a', days, generated_at: '2026-11-03T00:00:00Z',
    members: { total, by_role: {}, pending_invitations: 0 },
    billing: { subscription_status: null, billing_status: null, current_period_start: null, current_period_end: null, currency: null, mrr_minor: 0, paid_minor: 0, payments_count: 0, outstanding_minor: 0, invoices_count: 0 },
    audit: { events: 0, by_outcome: {}, by_day: [], by_action: [] },
    usage: { counters: {} } as AccountAnalytics['usage'] };
}
const event = (id: string): AuditEvent => ({ id, occurred_at: '2026-11-03T00:00:00Z', actor_type: 'system', actor_id: null,
  action: id, resource_type: 'account', resource_id: 'a', correlation_id: null, outcome: 'success', metadata: {} });
const options = { global: { plugins: [i18n] } };
beforeEach(() => { vi.resetAllMocks(); route.params.accountId = 'a'; i18n.global.locale.value = 'en'; });

describe('account analytics request scope', () => {
  it('keeps the latest period when responses arrive backwards', async () => {
    const old = deferred<AccountAnalytics>(); const latest = deferred<AccountAnalytics>();
    api.get.mockReturnValueOnce(old.promise).mockReturnValueOnce(latest.promise);
    const wrapper = mount(Analytics, options); await flushPromises();
    wrapper.findComponent({ name: 'AppDropdown' }).vm.$emit('update:modelValue', '7'); await flushPromises();
    latest.resolve(analytics(7, 777)); await flushPromises(); old.resolve(analytics(30, 333)); await flushPromises();
    expect(wrapper.find('.analytics__kpi-value').text()).toBe('777'); wrapper.unmount();
  });
  it('does not let an old failure dismiss the new loading state', async () => {
    const old = deferred<AccountAnalytics>(); const latest = deferred<AccountAnalytics>();
    api.get.mockReturnValueOnce(old.promise).mockReturnValueOnce(latest.promise);
    const wrapper = mount(Analytics, options); await flushPromises();
    wrapper.findComponent({ name: 'AppDropdown' }).vm.$emit('update:modelValue', '90'); await flushPromises();
    old.reject(new Error('old error')); await flushPromises();
    expect(wrapper.findComponent({ name: 'AppSkeleton' }).exists()).toBe(true);
    expect(wrapper.text()).not.toContain('old error'); latest.resolve(analytics(90, 999)); await flushPromises(); wrapper.unmount();
  });
  it('rejects an earlier A result after A → B → A in one tick and after unmount', async () => {
    const old = deferred<AccountAnalytics>(); const latest = deferred<AccountAnalytics>();
    api.get.mockReturnValueOnce(old.promise).mockReturnValueOnce(latest.promise);
    const wrapper = mount(Analytics, options); await flushPromises(); route.params.accountId = 'b'; route.params.accountId = 'a'; await flushPromises();
    old.resolve(analytics(30, 123)); await flushPromises(); expect(wrapper.findComponent({ name: 'AppSkeleton' }).exists()).toBe(true);
    wrapper.unmount(); latest.resolve(analytics(30, 456)); await flushPromises();
  });
});

describe('account audit cursor and filter scope', () => {
  it('discards an old page after applying new filters and keeps loading until the new result', async () => {
    api.events.mockResolvedValueOnce({ items: [event('old-first')], next_cursor: 'old-cursor' });
    const wrapper = mount(Audit, options); await flushPromises();
    const more = deferred<AuditEventPage>(); const filtered = deferred<AuditEventPage>();
    api.events.mockReturnValueOnce(more.promise).mockReturnValueOnce(filtered.promise);
    await wrapper.find('.audit__more button').trigger('click'); await flushPromises();
    await wrapper.find('#audit-action').setValue('filtered'); await wrapper.find('.audit__filter-actions button').trigger('click'); await flushPromises();
    more.resolve({ items: [event('old-tail')], next_cursor: null }); await flushPromises();
    expect(wrapper.findComponent({ name: 'AppSkeleton' }).exists()).toBe(true);
    filtered.resolve({ items: [event('filtered-first')], next_cursor: null }); await flushPromises();
    expect(wrapper.findAll('.audit__event strong').map(node => node.text())).toEqual(['filtered-first']); wrapper.unmount();
  });
  it('paginates applied filters, deduplicates overlapping events and preserves rows on failure', async () => {
    api.events.mockResolvedValueOnce({ items: [event('first')], next_cursor: 'cursor' });
    const wrapper = mount(Audit, options); await flushPromises();
    await wrapper.find('#audit-action').setValue('not-applied'); api.events.mockRejectedValueOnce(new Error('more unavailable'));
    await wrapper.find('.audit__more button').trigger('click'); await flushPromises();
    expect(api.events).toHaveBeenLastCalledWith('a', expect.objectContaining({ cursor: 'cursor', action: '' }));
    expect(wrapper.find('.audit__event strong').text()).toBe('first'); expect(wrapper.find('.audit__more [role=alert]').exists()).toBe(true);
    api.events.mockResolvedValueOnce({ items: [event('first'), event('second')], next_cursor: null });
    await wrapper.find('.audit__more button').trigger('click'); await flushPromises();
    expect(wrapper.findAll('.audit__event strong').map(node => node.text())).toEqual(['first', 'second']); wrapper.unmount();
  });
  it('clears former identity rows before a new session response settles', async () => {
    api.events.mockResolvedValueOnce({ items: [event('private-A')], next_cursor: null });
    const wrapper = mount(Audit, options); await flushPromises(); const newer = deferred<AuditEventPage>(); api.events.mockReturnValueOnce(newer.promise);
    endSession(); await nextTick(); expect(wrapper.text()).not.toContain('private-A'); await flushPromises();
    newer.resolve({ items: [event('new-session')], next_cursor: null }); await flushPromises(); wrapper.unmount();
  });
});
