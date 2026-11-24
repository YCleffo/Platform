import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref } from 'vue';
import ParentAnalyticsPanel from './ParentAnalyticsPanel.vue';
import type { AttendanceTotals, ParentHomework, ParentLesson } from './api';

vi.mock('../i18n', () => ({
  useEducationI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      if (params?.count !== undefined) return `${key}:${params.count}`;
      return key;
    },
    locale: ref('ru'),
  }),
}));

describe('ParentAnalyticsPanel', () => {
  it('calculates attendance percentage correctly and renders progress bar', () => {
    const attendance: AttendanceTotals = {
      present: 18,
      absent: 2,
      pending: 0,
      total: 20,
    };

    const wrapper = mount(ParentAnalyticsPanel, {
      props: { attendance },
    });

    expect(wrapper.find('.edu-parent-analytics__score-pill').text()).toBe('90%');
    expect(wrapper.text()).toContain('education.present: 18');
    expect(wrapper.text()).toContain('education.absentStatus: 2');
  });

  it('calculates academic score average and renders trend dots for graded lessons', () => {
    const results: ParentLesson[] = [
      {
        id: 'les-1',
        groupId: 'grp-1',
        groupName: 'Python 101',
        title: 'Урок 1: Введение',
        startsAt: '2026-11-01T10:00:00Z',
        endsAt: '2026-11-01T11:00:00Z',
        location: 'Online',
        status: 'completed',
        resultStatus: 'excellent',
        resultComment: 'Отличная работа на занятии',
      },
      {
        id: 'les-2',
        groupId: 'grp-1',
        groupName: 'Python 101',
        title: 'Урок 2: Переменные',
        startsAt: '2026-11-08T10:00:00Z',
        endsAt: '2026-11-08T11:00:00Z',
        location: 'Online',
        status: 'completed',
        resultStatus: 'good',
        resultComment: 'Хорошо усвоил материал',
      },
    ];

    const wrapper = mount(ParentAnalyticsPanel, {
      props: { results },
    });

    // Average of 5 and 4 = 4.5
    expect(wrapper.text()).toContain('★ 4.5 / 5.0');
    expect(wrapper.findAll('.edu-parent-analytics__chart-dot').length).toBe(2);
    expect(wrapper.text()).toContain('«Отличная работа на занятии»');
  });

  it('identifies overdue homework debts and displays alert banner', () => {
    const homework: ParentHomework[] = [
      {
        id: 'hw-1',
        title: 'Домашнее задание: Списки',
        description: '...',
        instructions: '...',
        type: 'text',
        dueAt: '2026-11-01T12:00:00Z', // past date
        status: 'PUBLISHED',
        submissionState: 'NOT_STARTED',
        submittedAt: null,
        review: null,
      },
    ];

    const wrapper = mount(ParentAnalyticsPanel, {
      props: { homework },
    });

    expect(wrapper.find('.edu-parent-analytics__debt-banner--alert').exists()).toBe(true);
    expect(wrapper.text()).toContain('Домашнее задание: Списки');
    expect(wrapper.text()).toContain('education.parentHomeworkDebtsTitle:1');
  });

  it('displays clean status banner when there are no homework debts', () => {
    const homework: ParentHomework[] = [
      {
        id: 'hw-done',
        title: 'Сданное задание',
        description: '...',
        instructions: '...',
        type: 'text',
        dueAt: '2026-11-01T12:00:00Z',
        status: 'PUBLISHED',
        submissionState: 'REVIEWED',
        submittedAt: '2026-10-31T12:00:00Z',
        review: {
          decision: 'accepted',
          resultStatus: 'excellent',
          comment: 'Всё верно!',
          createdAt: '2026-11-01T10:00:00Z',
        },
      },
    ];

    const wrapper = mount(ParentAnalyticsPanel, {
      props: { homework },
    });

    expect(wrapper.find('.edu-parent-analytics__debt-banner--ok').exists()).toBe(true);
    expect(wrapper.text()).toContain('education.parentNoDebtsTitle');
  });
});
