import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { ref } from 'vue';
import EducationMessenger from './EducationMessenger.vue';
import { educationApi } from '../api';
import { parentApi } from '../parent/api';

vi.mock('../i18n', () => ({
  useEducationI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) => {
      if (params) {
        return Object.entries(params).reduce(
          (str, [k, v]) => str.replace(`{${k}}`, String(v)),
          key,
        );
      }
      return key;
    },
    locale: ref('en'),
  }),
}));

vi.mock('../api', () => ({
  educationApi: {
    threads: vi.fn(),
    messages: vi.fn(),
    sendMessage: vi.fn(),
    startThread: vi.fn(),
    readThread: vi.fn(),
    contacts: vi.fn(),
    studentThreads: vi.fn(),
    studentMessages: vi.fn(),
    studentSendMessage: vi.fn(),
    studentStartThread: vi.fn(),
    studentReadThread: vi.fn(),
    studentContacts: vi.fn(),
    toggleReaction: vi.fn(),
    studentToggleReaction: vi.fn(),
  },
}));

vi.mock('../parent/api', () => ({
  parentApi: {
    threads: vi.fn(),
    messages: vi.fn(),
    sendMessage: vi.fn(),
    startThread: vi.fn(),
    readThread: vi.fn(),
    contacts: vi.fn(),
    toggleReaction: vi.fn(),
  },
}));

describe('EducationMessenger', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders threads and displays peer role and unread badge', async () => {
    vi.mocked(educationApi.threads).mockResolvedValueOnce({
      items: [
        {
          id: 'th-1',
          subject: 'Python 101',
          peerPersonId: 'p-student-1',
          peerName: 'Alex Student',
          peerRole: 'student',
          lastMessageBody: 'Hello teacher!',
          lastMessageAt: '2026-11-24T10:00:00Z',
          unreadCount: 0,
        },
        {
          id: 'th-2',
          subject: 'Math',
          peerPersonId: 'p-student-2',
          peerName: 'Maria Parent',
          peerRole: 'parent',
          lastMessageBody: 'Question about grades',
          lastMessageAt: '2026-11-24T10:02:00Z',
          unreadCount: 3,
        },
      ],
      limit: 50,
      offset: 0,
      nextOffset: null,
    });
    vi.mocked(educationApi.messages).mockResolvedValueOnce({
      items: [
        {
          id: 'm-1',
          senderPersonId: 'p-student-1',
          senderName: 'Alex Student',
          body: 'Hello teacher! Check https://example.com for my homework.',
          createdAt: '2026-11-24T10:00:00Z',
        },
      ],
      limit: 50,
      offset: 0,
      nextOffset: null,
    });

    const wrapper = mount(EducationMessenger, {
      props: {
        role: 'teacher',
        organizationId: 'org-1',
        currentPersonId: 'p-teacher-1',
        initialThreadId: 'th-1',
      },
      global: {
        stubs: {
          EducationState: true,
        },
      },
    });

    await vi.waitFor(() => {
      expect(wrapper.text()).toContain('Alex Student');
      expect(wrapper.text()).toContain('Maria Parent');
    });

    expect(wrapper.find('.edu-messenger__role-badge').exists()).toBe(true);
    expect(wrapper.find('.edu-messenger__unread-badge').text()).toBe('3');

    // Linkification verification (safe link with target blank, no raw innerHTML)
    await vi.waitFor(() => {
      const link = wrapper.find('.edu-messenger__bubble a');
      expect(link.exists()).toBe(true);
      expect(link.attributes('href')).toBe('https://example.com');
      expect(link.attributes('target')).toBe('_blank');
      expect(link.attributes('rel')).toBe('noopener noreferrer');
    });
  });

  it('allows typing and sending a message', async () => {
    vi.mocked(educationApi.threads).mockResolvedValueOnce({
      items: [
        {
          id: 'th-1',
          subject: 'Chat',
          peerPersonId: 'p-student-1',
          peerName: 'Alex Student',
          peerRole: 'student',
          lastMessageBody: 'Hi',
          lastMessageAt: '2026-11-24T10:00:00Z',
          unreadCount: 0,
        },
      ],
      limit: 50,
      offset: 0,
      nextOffset: null,
    });
    vi.mocked(educationApi.messages).mockResolvedValueOnce({
      items: [],
      limit: 50,
      offset: 0,
      nextOffset: null,
    });
    vi.mocked(educationApi.sendMessage).mockResolvedValueOnce({
      id: 'm-new',
      senderPersonId: 'p-teacher-1',
      senderName: 'Teacher',
      body: 'Welcome to the class!',
      createdAt: '2026-11-24T10:05:00Z',
    });

    const wrapper = mount(EducationMessenger, {
      props: {
        role: 'teacher',
        organizationId: 'org-1',
        currentPersonId: 'p-teacher-1',
        initialThreadId: 'th-1',
      },
      global: {
        stubs: {
          EducationState: true,
        },
      },
    });

    await vi.waitFor(() => {
      expect(wrapper.find('.edu-messenger__composer-input').exists()).toBe(true);
    });

    const input = wrapper.find('.edu-messenger__composer-input');
    await input.setValue('Welcome to the class!');

    const form = wrapper.find('form.edu-messenger__composer');
    await form.trigger('submit.prevent');

    expect(educationApi.sendMessage).toHaveBeenCalledWith(
      'org-1',
      'th-1',
      'Welcome to the class!',
      expect.any(String),
      'text',
      null,
      null,
    );

    await vi.waitFor(() => {
      expect(wrapper.text()).toContain('Welcome to the class!');
    });
  });

  it('renders rich media, replies, and handles emoji reactions', async () => {
    vi.mocked(educationApi.threads).mockResolvedValueOnce({
      items: [
        {
          id: 'th-media',
          subject: 'Voice & Media Thread',
          peerPersonId: 'p-student-1',
          peerName: 'Alex Student',
          peerRole: 'student',
          lastMessageBody: 'Voice message',
          lastMessageAt: '2026-11-24T10:00:00Z',
          unreadCount: 0,
        },
      ],
      limit: 50,
      offset: 0,
      nextOffset: null,
    });
    vi.mocked(educationApi.messages).mockResolvedValueOnce({
      items: [
        {
          id: 'm-voice',
          senderPersonId: 'p-student-1',
          senderName: 'Alex Student',
          body: null,
          kind: 'voice',
          metadata: { durationSeconds: 15 },
          createdAt: '2026-11-24T10:00:00Z',
          reactions: [{ emoji: '👍', count: 1, reacted: false }],
        },
        {
          id: 'm-reply',
          senderPersonId: 'p-teacher-1',
          senderName: 'Teacher',
          body: 'Got your voice note!',
          kind: 'text',
          replyTo: { id: 'm-voice', body: 'Voice message' },
          createdAt: '2026-11-24T10:01:00Z',
          reactions: [{ emoji: '❤️', count: 2, reacted: true }],
        },
      ],
      limit: 50,
      offset: 0,
      nextOffset: null,
    });
    vi.mocked(educationApi.toggleReaction).mockResolvedValueOnce({
      reacted: true,
      reactions: [{ emoji: '👍', count: 2, reacted: true }],
    });

    const wrapper = mount(EducationMessenger, {
      props: {
        role: 'teacher',
        organizationId: 'org-1',
        currentPersonId: 'p-teacher-1',
        initialThreadId: 'th-media',
      },
      global: {
        stubs: {
          EducationState: true,
          AudioPlayer: { template: '<div class="stubbed-audio-player">Audio Player</div>' },
          AudioRecorder: true,
          VideoNotePlayer: true,
          FileAttachment: true,
        },
      },
    });

    await vi.waitFor(() => {
      expect(wrapper.find('.stubbed-audio-player').exists()).toBe(true);
      expect(wrapper.find('.edu-messenger__reply-quote').exists()).toBe(true);
      expect(wrapper.text()).toContain('Got your voice note!');
    });

    // Toggle reaction
    const reactionPill = wrapper.find('.edu-messenger__reaction-pill');
    expect(reactionPill.exists()).toBe(true);
    await reactionPill.trigger('click');

    expect(educationApi.toggleReaction).toHaveBeenCalledWith('org-1', 'th-media', 'm-voice', '👍');
  });

  it('toggles mobile view between list and chat', async () => {
    vi.mocked(educationApi.threads).mockResolvedValueOnce({
      items: [
        {
          id: 'th-1',
          subject: 'General',
          peerPersonId: 'p-teacher-2',
          peerName: 'Elena Teacher',
          peerRole: 'teacher',
          lastMessageBody: 'Notes',
          lastMessageAt: '2026-11-24T10:00:00Z',
          unreadCount: 0,
        },
      ],
      limit: 50,
      offset: 0,
      nextOffset: null,
    });
    vi.mocked(educationApi.messages).mockResolvedValue({
      items: [],
      limit: 50,
      offset: 0,
      nextOffset: null,
    });

    const wrapper = mount(EducationMessenger, {
      props: {
        role: 'teacher',
        organizationId: 'org-1',
        currentPersonId: 'p-teacher-1',
        initialThreadId: '',
      },
      global: {
        stubs: {
          EducationState: true,
        },
      },
    });

    await vi.waitFor(() => {
      expect(wrapper.classes()).toContain('edu-messenger--view-list');
      expect(wrapper.find('.edu-messenger__thread-item').exists()).toBe(true);
    });

    // Click thread item to drill down
    const threadBtn = wrapper.find('.edu-messenger__thread-item');
    await threadBtn.trigger('click');

    expect(wrapper.classes()).toContain('edu-messenger--view-chat');

    // Click back button to return to list
    const backBtn = wrapper.find('.edu-messenger__mobile-back');
    await backBtn.trigger('click');

    expect(wrapper.classes()).toContain('edu-messenger--view-list');
  });

  it('works in student role calling student APIs', async () => {
    vi.mocked(educationApi.studentThreads).mockResolvedValueOnce({
      items: [
        {
          id: 'th-student',
          subject: 'Homework questions',
          peerPersonId: 'p-teacher-1',
          peerName: 'Mr. Smith',
          peerRole: 'teacher',
          lastMessageBody: 'Check chapter 3',
          lastMessageAt: '2026-11-24T10:00:00Z',
          unreadCount: 0,
        },
      ],
      limit: 50,
      offset: 0,
      nextOffset: null,
    });
    vi.mocked(educationApi.studentMessages).mockResolvedValueOnce({
      items: [],
      limit: 50,
      offset: 0,
      nextOffset: null,
    });

    mount(EducationMessenger, {
      props: {
        role: 'student',
        organizationId: 'org-1',
        currentPersonId: 'p-student-1',
        initialThreadId: 'th-student',
      },
      global: {
        stubs: {
          EducationState: true,
        },
      },
    });

    await vi.waitFor(() => {
      expect(educationApi.studentThreads).toHaveBeenCalledWith('org-1', 0);
      expect(educationApi.studentMessages).toHaveBeenCalledWith('org-1', 'th-student', 0);
    });
  });

  it('works in parent role calling parent APIs', async () => {
    const parentChild = {
      id: 'student-child-1',
      personId: 'p-child-1',
      name: 'Timmy Child',
      organizationId: 'org-1',
      organizationName: 'School',
      groups: [{ id: 'g-1', name: 'Group 1' }],
    };

    vi.mocked(parentApi.threads).mockResolvedValueOnce({
      items: [
        {
          id: 'th-parent',
          subject: 'Student Progress',
          peerPersonId: 'p-teacher-1',
          peerName: 'Mrs. Davis',
          peerRole: 'teacher',
          lastMessageBody: 'Great job today!',
          lastMessageAt: '2026-11-24T10:00:00Z',
          unreadCount: 0,
        },
      ],
      limit: 50,
      offset: 0,
      nextOffset: null,
    });
    vi.mocked(parentApi.messages).mockResolvedValueOnce({
      items: [],
      limit: 50,
      offset: 0,
      nextOffset: null,
    });

    mount(EducationMessenger, {
      props: {
        role: 'parent',
        organizationId: 'org-1',
        currentPersonId: 'p-parent-1',
        parentChild,
        initialThreadId: 'th-parent',
      },
      global: {
        stubs: {
          EducationState: true,
        },
      },
    });

    await vi.waitFor(() => {
      expect(parentApi.threads).toHaveBeenCalledWith(parentChild, 0);
      expect(parentApi.messages).toHaveBeenCalledWith(parentChild, 'th-parent', 0);
    });
  });
});
