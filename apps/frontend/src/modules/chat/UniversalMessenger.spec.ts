import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UniversalMessenger from './UniversalMessenger.vue';
import { chatApi } from './chatApi';

vi.mock('./chatApi', () => ({
  chatApi: {
    listRooms: vi.fn(),
    getRoom: vi.fn(),
    listMessages: vi.fn(),
    sendMessage: vi.fn(),
    editMessage: vi.fn(),
    deleteMessage: vi.fn(),
    toggleReaction: vi.fn(),
    markRead: vi.fn(),
    pinMessage: vi.fn(),
    saveDraft: vi.fn(),
    updatePreferences: vi.fn(),
    searchMessages: vi.fn(),
    listContacts: vi.fn(),
    createRoom: vi.fn(),
    addMember: vi.fn(),
    removeMember: vi.fn(),
    leaveRoom: vi.fn(),
  },
}));

vi.mock('@/shared/realtime/realtimeClient', () => ({
  realtimeClient: {
    getConnectionStatus: () => 'connected',
    onStatusChange: (cb: (st: 'connected') => void) => {
      cb('connected');
      return () => undefined;
    },
    connect: vi.fn().mockResolvedValue(undefined),
    subscribe: vi.fn().mockReturnValue(() => undefined),
    sendTyping: vi.fn(),
  },
}));

vi.mock('@/modules/workspace/useOperationsCenter', () => ({
  useOperationsCenter: () => ({
    registerUpload: vi.fn(),
    cancelUpload: vi.fn(),
  }),
}));

describe('UniversalMessenger', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(chatApi.listRooms).mockResolvedValue({
      items: [
        {
          id: 'room-1',
          roomType: 'group',
          title: 'Math Group',
          subject: 'Math Group',
          description: '',
          contextType: null,
          contextId: null,
          pinnedMessageId: null,
          lastActivityAt: '2026-11-27T10:00:00Z',
          createdAt: '2026-11-27T09:00:00Z',
          myRole: 'owner',
          canWrite: true,
          mutedUntil: null,
          archivedAt: null,
          pinnedOrder: null,
          draftText: null,
          draftReplyToId: null,
          memberCount: 2,
          peerPersonId: null,
          peerName: null,
          peerPresence: null,
          lastMessageBody: 'Hello',
          lastMessageKind: 'text',
          lastMessageAt: '2026-11-27T10:00:00Z',
          lastMessageSenderId: 'person-2',
          unreadCount: 1,
        },
      ],
      hasMore: false,
      offset: 0,
    });
    vi.mocked(chatApi.getRoom).mockResolvedValue({
      id: 'room-1',
      roomType: 'group',
      title: 'Math Group',
      subject: 'Math Group',
      description: '',
      contextType: null,
      contextId: null,
      pinnedMessageId: null,
      lastActivityAt: '2026-11-27T10:00:00Z',
      createdAt: '2026-11-27T09:00:00Z',
      myRole: 'owner',
      canWrite: true,
      mutedUntil: null,
      archivedAt: null,
      pinnedOrder: null,
      draftText: null,
      draftReplyToId: null,
      memberCount: 2,
      peerPersonId: null,
      peerName: null,
      peerPresence: null,
      lastMessageBody: 'Hello',
      lastMessageKind: 'text',
      lastMessageAt: '2026-11-27T10:00:00Z',
      lastMessageSenderId: 'person-2',
      unreadCount: 0,
      members: [
        {
          personId: 'person-1',
          name: 'Teacher One',
          role: 'owner',
          canWrite: true,
          lastReadAt: null,
          joinedAt: '2026-11-27T09:00:00Z',
        },
        {
          personId: 'person-2',
          name: 'Student One',
          role: 'member',
          canWrite: true,
          lastReadAt: null,
          joinedAt: '2026-11-27T09:00:00Z',
        },
      ],
      pinnedMessage: null,
    });
    vi.mocked(chatApi.listMessages).mockResolvedValue({
      items: [
        {
          id: 'msg-1',
          roomId: 'room-1',
          seq: 10,
          senderPersonId: 'person-2',
          senderName: 'Student One',
          body: 'Hello teacher',
          kind: 'text',
          mediaAssetId: null,
          replyToId: null,
          forwardedFromMessageId: null,
          forwardedFromRoomId: null,
          metadata: {},
          editedAt: null,
          deletedAt: null,
          deletedForAll: false,
          pinnedAt: null,
          createdAt: '2026-11-27T10:00:00Z',
          media: null,
          replyTo: null,
          isReadByPeer: false,
          reactions: [],
        },
      ],
      hasMore: true,
      offset: 0,
    });
    vi.mocked(chatApi.markRead).mockResolvedValue({ read: true });
    vi.mocked(chatApi.listContacts).mockResolvedValue({
      items: [{ id: 'c-3', personId: 'person-3', name: 'Student Two', role: 'student' }],
    });
  });

  it('loads rooms, opens a room, supports pagination and member management', async () => {
    const wrapper = mount(UniversalMessenger, {
      props: {
        organizationId: 'org-1',
        currentPersonId: 'person-1',
        initialRoomId: 'room-1',
      },
      global: {
        stubs: { RouterLink: true },
      },
    });

    await flushPromises();

    expect(chatApi.listRooms).toHaveBeenCalledWith('org-1', expect.objectContaining({ tab: 'all' }));
    expect(chatApi.getRoom).toHaveBeenCalledWith('org-1', 'room-1');
    expect(wrapper.text()).toContain('Math Group');
    expect(wrapper.text()).toContain('Hello teacher');
    expect(wrapper.find('.uchat__load-older').exists()).toBe(true);

    // Open participants modal
    const membersBtn = wrapper.findAll('button').find((b) => b.text().includes('Participants') || b.text().includes('Участники'));
    expect(membersBtn).toBeTruthy();
    await membersBtn!.trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('Teacher One');
    expect(wrapper.text()).toContain('Student One');
  });
});
