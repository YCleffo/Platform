import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import MembershipList from './MembershipList.vue';
import { i18n } from '@/shared/i18n';
import type { AccountRole } from '@/shared/types';

const member = (id: string, email: string, role: AccountRole) => ({
  id,
  user_id: `user-${id}`,
  email,
  role,
  created_at: '2026-01-01T00:00:00Z',
});

describe('MembershipList', () => {
  it('renders member emails and role labels', () => {
    const wrapper = mount(MembershipList, {
      props: {
        memberships: [
          member('1', 'owner@example.test', 'owner'),
          member('2', 'tech@example.test', 'technical'),
        ],
        manageableIds: [],
        removableIds: [],
      },
      global: { plugins: [i18n] },
    });

    expect(wrapper.text()).toContain('owner@example.test');
    expect(wrapper.text()).toContain('tech@example.test');
    expect(wrapper.text()).toContain('Owner');
    expect(wrapper.text()).toContain('Technical');
  });

  it('renders no rows for an empty list', () => {
    const wrapper = mount(MembershipList, {
      props: { memberships: [], manageableIds: [], removableIds: [] },
      global: { plugins: [i18n] },
    });

    expect(wrapper.findAll('li')).toHaveLength(0);
  });
});
