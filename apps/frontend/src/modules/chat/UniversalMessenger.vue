<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { realtimeClient, type RealtimeEventEnvelope } from '@/shared/realtime/realtimeClient';
import { computeBlobSha256, mediaApi } from '@/shared/api/mediaApi';
import {
  useOperationsCenter,
  type UploadTrackerHandle,
} from '@/modules/workspace/useOperationsCenter';
import AppButton from '@/shared/ui/actions/AppButton.vue';
import AppFileDropzone from '@/shared/ui/fields/AppFileDropzone.vue';
import AppInput from '@/shared/ui/fields/AppInput.vue';
import AppBadge from '@/shared/ui/feedback/AppBadge.vue';
import AudioPlayer from '@/shared/ui/media/AudioPlayer.vue';
import VoiceMessagePlayer from '@/shared/ui/media/VoiceMessagePlayer.vue';
import AudioRecorder from '@/shared/ui/media/AudioRecorder.vue';
import VideoPlayer from '@/shared/ui/media/VideoPlayer.vue';
import VideoNotePlayer from '@/shared/ui/media/VideoNotePlayer.vue';
import VideoNoteRecorder from '@/shared/ui/media/VideoNoteRecorder.vue';
import FileAttachment from '@/shared/ui/media/FileAttachment.vue';
import MediaPlayer from '@/shared/ui/media/MediaPlayer.vue';
import {
  chatApi,
  type ChatContact,
  type ChatMember,
  type ChatMessage,
  type ChatRoomSummary,
} from './chatApi';
import { chatT } from './chatI18n';
import './chat.css';

const props = withDefaults(
  defineProps<{
    organizationId: string;
    currentPersonId: string;
    initialRoomId?: string;
    contextType?: string;
    contextId?: string;
    timezone?: string;
  }>(),
  {
    initialRoomId: '',
    contextType: '',
    contextId: '',
    timezone: 'UTC',
  },
);

const emit = defineEmits<{
  'select-room': [roomId: string];
}>();

const wsStatus = ref<'disconnected' | 'connecting' | 'connected'>(
  realtimeClient.getConnectionStatus(),
);
const rooms = ref<ChatRoomSummary[]>([]);
const activeRoomId = ref<string>(props.initialRoomId);
const roomDetails = ref<
  | (ChatRoomSummary & { members: ChatMember[]; pinnedMessage: Record<string, unknown> | null })
  | null
>(null);
const messages = ref<ChatMessage[]>([]);
const searchResults = ref<
  Array<{
    id: string;
    roomId: string;
    roomTitle: string;
    senderName: string;
    body: string;
    createdAt: string;
  }>
>([]);

const tab = ref<'all' | 'unread' | 'group' | 'archived'>('all');
const searchQuery = ref('');
const composerText = ref('');
const editingMessage = ref<ChatMessage | null>(null);
const replyingTo = ref<ChatMessage | null>(null);
const forwardingMessage = ref<ChatMessage | null>(null);

const isRecordingVoice = ref(false);
const isRecordingVideo = ref(false);
const isAttachingFile = ref(false);
const uploadMediaBusy = ref(false);
const isNewChatOpen = ref(false);
const newRoomType = ref<'direct' | 'group' | 'organization'>('direct');
const newRoomTitle = ref('');
const newRoomFirstBody = ref('');
const selectedContactIds = ref<string[]>([]);
const contacts = ref<ChatContact[]>([]);

const typingUsers = ref<Record<string, { displayName: string; expiresAt: number }>>({});
const playbackUrls = ref<Record<string, string>>({});
const messagesBox = ref<HTMLElement | null>(null);
const errorBanner = ref('');
const hasMoreMessages = ref(false);
const loadingOlder = ref(false);
const highlightedMessageId = ref('');
const isMembersOpen = ref(false);
const selectedAddPersonId = ref('');
const contextMenu = ref<{ x: number; y: number; message: ChatMessage } | null>(null);
const operations = useOperationsCenter();
const uploadMediaProgress = ref<number | null>(null);
let mediaUploadController: AbortController | null = null;
let mediaUploadHandle: UploadTrackerHandle | null = null;

// Client-side offline outbound queue
interface OutboundQueueItem {
  roomId: string;
  body: string;
  clientKey: string;
  replyToId?: string;
}
const offlineQueue = ref<OutboundQueueItem[]>([]);

let unsubStatus: (() => void) | null = null;
let unsubRoom: (() => void) | null = null;
let unsubUser: (() => void) | null = null;
let unsubOrg: (() => void) | null = null;
let draftTimer: ReturnType<typeof setTimeout> | null = null;
let typingStopTimer: ReturnType<typeof setTimeout> | null = null;
let searchTimer: ReturnType<typeof setTimeout> | null = null;
let typingGcTimer: ReturnType<typeof setInterval> | null = null;

const activeRoom = computed(
  () => roomDetails.value ?? rooms.value.find((r) => r.id === activeRoomId.value) ?? null,
);
const quickEmojis = ['👍', '❤️', '🎉', '👏', '😂'];

const activeTypingText = computed(() => {
  const now = Date.now();
  const names = Object.entries(typingUsers.value)
    .filter(([pid, v]) => pid !== props.currentPersonId && v.expiresAt > now)
    .map(([, v]) => v.displayName);
  if (names.length === 0) return '';
  return `${names.join(', ')} ${chatT('typing')}`;
});

function wsStatusLabel(): string {
  if (wsStatus.value === 'connected') return chatT('wsConnected');
  if (wsStatus.value === 'connecting') return chatT('wsConnecting');
  return chatT('wsDisconnected');
}

function getPlaybackUrl(msg: ChatMessage): string | undefined {
  const aid = msg.media?.id || msg.mediaAssetId;
  if (!aid) return undefined;
  if (playbackUrls.value[aid]) return playbackUrls.value[aid];
  void mediaApi
    .getPlaybackUrl(props.organizationId, aid)
    .then((info) => {
      playbackUrls.value = { ...playbackUrls.value, [aid]: info.url };
    })
    .catch(() => undefined);
  return playbackUrls.value[aid];
}

async function loadRooms(): Promise<void> {
  if (!props.organizationId) return;
  try {
    const res = await chatApi.listRooms(props.organizationId, {
      tab: tab.value,
      q: searchQuery.value.trim() || undefined,
      contextType: props.contextType || undefined,
      contextId: props.contextId || undefined,
    });
    rooms.value = res.items;
    if (!activeRoomId.value && props.initialRoomId) {
      activeRoomId.value = props.initialRoomId;
    }
    if (activeRoomId.value && !composerText.value) {
      const activeSummary = rooms.value.find((r) => r.id === activeRoomId.value);
      if (activeSummary?.draftText) {
        composerText.value = activeSummary.draftText;
      }
    }
  } catch (err) {
    errorBanner.value = err instanceof Error ? err.message : 'Failed to load rooms';
  }
}

async function openRoom(roomId: string, aroundMessageId?: string): Promise<void> {
  if (!roomId || !props.organizationId) return;
  activeRoomId.value = roomId;
  emit('select-room', roomId);
  typingUsers.value = {};
  editingMessage.value = null;
  contextMenu.value = null;

  unsubRoom?.();
  unsubRoom = realtimeClient.subscribe(`room:${roomId}`, handleRoomEvent);

  try {
    const [detail, msgs] = await Promise.all([
      chatApi.getRoom(props.organizationId, roomId),
      chatApi.listMessages(
        props.organizationId,
        roomId,
        aroundMessageId ? { aroundMessageId } : undefined,
      ),
    ]);
    roomDetails.value = detail;
    messages.value = [...msgs.items].reverse();
    hasMoreMessages.value = msgs.hasMore;
    if (detail.draftText && !composerText.value) {
      composerText.value = detail.draftText;
    }
    const latest = messages.value[messages.value.length - 1];
    if (latest && latest.senderPersonId !== props.currentPersonId) {
      await chatApi.markRead(props.organizationId, roomId, latest.id);
      const r = rooms.value.find((x) => x.id === roomId);
      if (r) r.unreadCount = 0;
    }
    await nextTick();
    if (aroundMessageId) {
      scrollToMessage(aroundMessageId);
    } else {
      scrollToBottom();
    }
  } catch (err) {
    errorBanner.value = err instanceof Error ? err.message : 'Failed to open chat';
  }
}

async function loadOlderMessages(): Promise<void> {
  if (!activeRoomId.value || !props.organizationId || loadingOlder.value || !hasMoreMessages.value) {
    return;
  }
  const oldest = messages.value[0];
  if (!oldest?.seq) return;
  loadingOlder.value = true;
  const el = messagesBox.value;
  const prevHeight = el?.scrollHeight ?? 0;
  try {
    const res = await chatApi.listMessages(props.organizationId, activeRoomId.value, {
      beforeSeq: oldest.seq,
    });
    hasMoreMessages.value = res.hasMore;
    const older = [...res.items].reverse();
    const existingIds = new Set(messages.value.map((m) => m.id));
    const uniqueOlder = older.filter((m) => !existingIds.has(m.id));
    if (uniqueOlder.length > 0) {
      messages.value = [...uniqueOlder, ...messages.value];
      await nextTick();
      if (el) {
        el.scrollTop = el.scrollHeight - prevHeight;
      }
    }
  } finally {
    loadingOlder.value = false;
  }
}

function scrollToMessage(messageId: string): void {
  const container = messagesBox.value;
  if (!container) return;
  const target = container.querySelector<HTMLElement>(`[data-message-id="${messageId}"]`);
  if (target) {
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    highlightedMessageId.value = messageId;
    setTimeout(() => {
      if (highlightedMessageId.value === messageId) {
        highlightedMessageId.value = '';
      }
    }, 2000);
  }
}

async function jumpToMessage(roomId: string, messageId: string): Promise<void> {
  if (!roomId || !messageId) return;
  if (roomId !== activeRoomId.value || !messages.value.some((m) => m.id === messageId)) {
    await openRoom(roomId, messageId);
    return;
  }
  await nextTick();
  scrollToMessage(messageId);
}

function onMessagesScroll(): void {
  if (messagesBox.value && messagesBox.value.scrollTop < 40 && hasMoreMessages.value && !loadingOlder.value) {
    void loadOlderMessages();
  }
}

function handleRoomEvent(ev: RealtimeEventEnvelope): void {
  const payload = ev.payload;
  if (ev.eventType === 'chat.message.created') {
    const msg = payload.message as ChatMessage | undefined;
    if (!msg || msg.roomId !== activeRoomId.value) return;
    const existingIdx = messages.value.findIndex((m) => m.id === msg.id);
    if (existingIdx >= 0) {
      messages.value[existingIdx] = msg;
    } else {
      // Replace matching optimistic message if any
      const clientKey = typeof msg.metadata?.clientKey === 'string' ? msg.metadata.clientKey : '';
      const optIdx = messages.value.findIndex(
        (m) =>
          m.optimisticPending &&
          ((clientKey && m.clientKey === clientKey) ||
            (m.senderPersonId === msg.senderPersonId && m.body === msg.body)),
      );
      if (optIdx >= 0) {
        messages.value[optIdx] = msg;
      } else {
        messages.value.push(msg);
      }
    }
    if (msg.senderPersonId !== props.currentPersonId) {
      void chatApi
        .markRead(props.organizationId, activeRoomId.value, msg.id)
        .catch(() => undefined);
    }
    void nextTick().then(scrollToBottom);
  } else if (ev.eventType === 'chat.message.updated') {
    const msg = payload.message as ChatMessage | undefined;
    if (!msg) return;
    const idx = messages.value.findIndex((m) => m.id === msg.id);
    if (idx >= 0) messages.value[idx] = msg;
  } else if (ev.eventType === 'chat.message.deleted') {
    const messageId = String(payload.messageId ?? '');
    const mode = String(payload.mode ?? 'for_me');
    if (mode === 'for_all') {
      const m = messages.value.find((x) => x.id === messageId);
      if (m) {
        m.body = null;
        m.deletedAt = new Date().toISOString();
        m.deletedForAll = true;
      }
    } else {
      messages.value = messages.value.filter((x) => x.id !== messageId);
    }
  } else if (ev.eventType === 'chat.reaction.updated') {
    const messageId = String(payload.messageId ?? '');
    const reactions = payload.reactions as ChatMessage['reactions'] | undefined;
    const m = messages.value.find((x) => x.id === messageId);
    if (m && reactions) {
      m.reactions = reactions;
    }
  } else if (ev.eventType === 'chat.read') {
    const reader = String(payload.personId ?? '');
    if (reader && reader !== props.currentPersonId) {
      for (const m of messages.value) {
        if (m.senderPersonId === props.currentPersonId) {
          m.isReadByPeer = true;
        }
      }
    }
  } else if (ev.eventType === 'chat.room.pinned') {
    if (roomDetails.value) {
      roomDetails.value.pinnedMessageId = (payload.pinnedMessageId as string | null) ?? null;
    }
    if (activeRoomId.value) void openRoom(activeRoomId.value);
  } else if (ev.eventType === 'chat.member.added') {
    if (activeRoomId.value && String(payload.roomId ?? '') === activeRoomId.value) {
      void chatApi
        .getRoom(props.organizationId, activeRoomId.value)
        .then((d) => {
          roomDetails.value = d;
        })
        .catch(() => undefined);
    }
  } else if (ev.eventType === 'chat.member.removed') {
    const removedPid = String(payload.personId ?? '');
    const removedRoomId = String(payload.roomId ?? '');
    if (removedPid === props.currentPersonId && removedRoomId === activeRoomId.value) {
      unsubRoom?.();
      unsubRoom = null;
      activeRoomId.value = '';
      roomDetails.value = null;
      messages.value = [];
      offlineQueue.value = offlineQueue.value.filter((q) => q.roomId !== removedRoomId);
      void loadRooms();
    } else if (roomDetails.value && removedRoomId === activeRoomId.value) {
      roomDetails.value.members = roomDetails.value.members.filter(
        (m) => m.personId !== removedPid,
      );
    }
  } else if (ev.eventType === 'chat.typing') {
    const pid = String(payload.personId ?? '');
    const typing = Boolean(payload.typing);
    const displayName = String(payload.displayName ?? 'User');
    if (!pid || pid === props.currentPersonId) return;
    if (typing) {
      typingUsers.value = {
        ...typingUsers.value,
        [pid]: { displayName, expiresAt: Date.now() + 5500 },
      };
    } else {
      const copy = { ...typingUsers.value };
      delete copy[pid];
      typingUsers.value = copy;
    }
  }
}

function handleUserOrOrgEvent(ev: RealtimeEventEnvelope): void {
  if (ev.eventType === 'chat.room.created' || ev.eventType === 'chat.room.updated') {
    void loadRooms();
  } else if (ev.eventType === 'chat.member.removed') {
    const removedPid = String(ev.payload.personId ?? '');
    const removedRoomId = String(ev.payload.roomId ?? '');
    if (removedPid === props.currentPersonId) {
      if (removedRoomId === activeRoomId.value) {
        unsubRoom?.();
        unsubRoom = null;
        activeRoomId.value = '';
        roomDetails.value = null;
        messages.value = [];
      }
      void loadRooms();
    }
  } else if (ev.eventType === 'presence.changed') {
    const pid = String(ev.payload.personId ?? '');
    const status = ev.payload.status === 'online' ? 'online' : 'offline';
    const lastSeenAt = typeof ev.payload.lastSeenAt === 'string' ? ev.payload.lastSeenAt : null;
    for (const r of rooms.value) {
      if (r.peerPersonId === pid && r.peerPresence) {
        r.peerPresence = { personId: pid, status, lastSeenAt };
      }
    }
    if (roomDetails.value) {
      for (const m of roomDetails.value.members) {
        if (m.personId === pid) {
          m.presence = { personId: pid, status, lastSeenAt };
        }
      }
    }
  } else if (ev.eventType === 'chat.draft.updated') {
    const rid = String(ev.payload.roomId ?? '');
    if (
      rid === activeRoomId.value &&
      typeof ev.payload.draftText === 'string' &&
      composerText.value === ''
    ) {
      composerText.value = ev.payload.draftText;
    }
  }
}

function scrollToBottom(): void {
  if (messagesBox.value) {
    messagesBox.value.scrollTop = messagesBox.value.scrollHeight;
  }
}

function onComposerInput(): void {
  if (!activeRoomId.value) return;
  const rid = activeRoomId.value;
  realtimeClient.sendTyping(rid, composerText.value.length > 0);
  if (typingStopTimer) clearTimeout(typingStopTimer);
  typingStopTimer = setTimeout(() => realtimeClient.sendTyping(rid, false), 3000);

  if (draftTimer) clearTimeout(draftTimer);
  draftTimer = setTimeout(() => {
    if (editingMessage.value) return;
    void chatApi
      .saveDraft(
        props.organizationId,
        rid,
        composerText.value || null,
        replyingTo.value?.id ?? null,
      )
      .catch(() => undefined);
  }, 600);
}

async function sendTextMessage(): Promise<void> {
  const rid = activeRoomId.value;
  const body = composerText.value.trim();
  if (!rid || !body) return;

  if (editingMessage.value) {
    const target = editingMessage.value;
    editingMessage.value = null;
    composerText.value = '';
    const updated = await chatApi.editMessage(props.organizationId, rid, target.id, body);
    const idx = messages.value.findIndex((m) => m.id === updated.id);
    if (idx >= 0) messages.value[idx] = updated;
    return;
  }

  const clientKey = `msg-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
  const replyId = replyingTo.value?.id;
  replyingTo.value = null;
  composerText.value = '';
  realtimeClient.sendTyping(rid, false);

  const optimistic: ChatMessage = {
    id: `temp-${clientKey}`,
    roomId: rid,
    senderPersonId: props.currentPersonId,
    body,
    kind: 'text',
    mediaAssetId: null,
    replyToId: replyId ?? null,
    forwardedFromMessageId: null,
    forwardedFromRoomId: null,
    metadata: { clientKey },
    editedAt: null,
    deletedAt: null,
    deletedForAll: false,
    pinnedAt: null,
    createdAt: new Date().toISOString(),
    media: null,
    replyTo: null,
    isReadByPeer: false,
    reactions: [],
    optimisticPending: true,
    clientKey,
  };
  messages.value.push(optimistic);
  await nextTick();
  scrollToBottom();

  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    offlineQueue.value.push({ roomId: rid, body, clientKey, replyToId: replyId });
    return;
  }

  try {
    const saved = await chatApi.sendMessage(props.organizationId, rid, {
      body,
      replyToId: replyId,
      clientKey,
      metadata: { clientKey },
    });
    const idx = messages.value.findIndex((m) => m.id === optimistic.id || m.id === saved.id);
    if (idx >= 0) messages.value[idx] = saved;
    void loadRooms();
  } catch {
    optimistic.optimisticFailed = true;
    offlineQueue.value.push({ roomId: rid, body, clientKey, replyToId: replyId });
  }
}

async function flushOfflineQueue(): Promise<void> {
  if (!props.organizationId || offlineQueue.value.length === 0) return;
  const pending = [...offlineQueue.value];
  offlineQueue.value = [];
  for (const item of pending) {
    try {
      const saved = await chatApi.sendMessage(props.organizationId, item.roomId, {
        body: item.body,
        clientKey: item.clientKey,
        replyToId: item.replyToId,
        metadata: { clientKey: item.clientKey },
      });
      const idx = messages.value.findIndex(
        (m) => m.clientKey === item.clientKey || m.id === saved.id,
      );
      if (idx >= 0) messages.value[idx] = saved;
    } catch {
      offlineQueue.value.push(item);
    }
  }
  void loadRooms();
}

function cancelMediaUpload(): void {
  if (mediaUploadHandle) {
    void operations.cancelUpload(mediaUploadHandle.id);
  } else {
    mediaUploadController?.abort();
  }
}

async function uploadAndSendMedia(
  kind: 'voice' | 'video_note' | 'audio' | 'video' | 'image' | 'document',
  blob: Blob,
  filename: string,
  durationSec?: number,
): Promise<void> {
  const rid = activeRoomId.value;
  if (!rid || !props.organizationId) return;
  uploadMediaBusy.value = true;
  uploadMediaProgress.value = 0;
  const controller = new AbortController();
  mediaUploadController = controller;
  const tracker = operations.registerUpload({
    organizationId: props.organizationId,
    label: filename,
    total: blob.size,
    abortController: controller,
  });
  mediaUploadHandle = tracker;

  try {
    const sha256 = await computeBlobSha256(blob);
    if (controller.signal.aborted) {
      throw new DOMException('The operation was aborted.', 'AbortError');
    }
    const reserved = await mediaApi.reserveUpload(props.organizationId, {
      kind,
      name: filename,
      mime: blob.type || (kind === 'voice' ? 'audio/webm' : 'video/webm'),
      sizeBytes: blob.size,
      sha256,
    });
    tracker.setMediaId(reserved.id);
    if (controller.signal.aborted) {
      await mediaApi.cancelUpload(reserved.id, props.organizationId).catch(() => undefined);
      throw new DOMException('The operation was aborted.', 'AbortError');
    }
    await mediaApi.uploadBinary(props.organizationId, reserved.id, blob, sha256, {
      signal: controller.signal,
      onProgress: (info) => {
        uploadMediaProgress.value = info.percent;
        tracker.updateProgress(info);
      },
    });
    if (durationSec) {
      await mediaApi.confirmUpload(props.organizationId, reserved.id, {
        durationSeconds: durationSec,
      });
    }
    const saved = await chatApi.sendMessage(props.organizationId, rid, {
      kind: kind === 'document' ? 'file' : kind,
      mediaAssetId: reserved.id,
      replyToId: replyingTo.value?.id,
    });
    tracker.markCompleted();
    replyingTo.value = null;
    if (!messages.value.some((m) => m.id === saved.id)) {
      messages.value.push(saved);
    }
    isRecordingVoice.value = false;
    isRecordingVideo.value = false;
    isAttachingFile.value = false;
    void loadRooms();
  } catch (err) {
    if (controller.signal.aborted || (err instanceof DOMException && err.name === 'AbortError')) {
      tracker.markCancelled();
    } else {
      const msg = err instanceof Error ? err.message : 'Media upload failed';
      tracker.markFailed(msg);
      errorBanner.value = msg;
    }
  } finally {
    if (mediaUploadController === controller) {
      mediaUploadController = null;
      mediaUploadHandle = null;
    }
    uploadMediaBusy.value = false;
    uploadMediaProgress.value = null;
  }
}

function inferChatMediaKind(file: File): 'image' | 'video' | 'audio' | 'document' {
  const mime = (file.type || '').toLowerCase();
  if (mime.startsWith('image/')) return 'image';
  if (mime.startsWith('video/')) return 'video';
  if (mime.startsWith('audio/')) return 'audio';
  return 'document';
}

function onFilesSelected(files: File[]): void {
  const file = files[0];
  if (!file) return;
  void uploadAndSendMedia(inferChatMediaKind(file), file, file.name);
}

async function onReact(msg: ChatMessage, emoji: string): Promise<void> {
  if (!activeRoomId.value) return;
  const res = await chatApi.toggleReaction(props.organizationId, activeRoomId.value, msg.id, emoji);
  msg.reactions = res.reactions;
}

async function onDeleteMessage(msg: ChatMessage, mode: 'for_me' | 'for_all'): Promise<void> {
  if (!activeRoomId.value) return;
  await chatApi.deleteMessage(props.organizationId, activeRoomId.value, msg.id, mode);
  if (mode === 'for_all') {
    msg.body = null;
    msg.deletedAt = new Date().toISOString();
    msg.deletedForAll = true;
  } else {
    messages.value = messages.value.filter((x) => x.id !== msg.id);
  }
}

async function onPin(msgId: string | null): Promise<void> {
  if (!activeRoomId.value) return;
  const res = await chatApi.pinMessage(props.organizationId, activeRoomId.value, msgId);
  if (roomDetails.value) {
    roomDetails.value.pinnedMessageId = res.pinnedMessageId;
  }
  await openRoom(activeRoomId.value);
}

async function onForwardTo(targetRoomId: string): Promise<void> {
  if (!forwardingMessage.value) return;
  const src = forwardingMessage.value;
  forwardingMessage.value = null;
  await chatApi.sendMessage(props.organizationId, targetRoomId, {
    forwardedFromMessageId: src.id,
  });
  await openRoom(targetRoomId);
}

async function openNewChatModal(): Promise<void> {
  isNewChatOpen.value = true;
  const res = await chatApi.listContacts(props.organizationId);
  contacts.value = res.items;
}

async function submitNewChat(): Promise<void> {
  if (!props.organizationId) return;
  const created = await chatApi.createRoom(props.organizationId, {
    roomType: props.contextType ? 'context' : newRoomType.value,
    title: newRoomTitle.value.trim() || undefined,
    memberPersonIds: selectedContactIds.value,
    includeAllOrgMembers: newRoomType.value === 'organization',
    contextType: props.contextType || undefined,
    contextId: props.contextId || undefined,
    body: newRoomFirstBody.value.trim() || undefined,
  });
  isNewChatOpen.value = false;
  newRoomTitle.value = '';
  newRoomFirstBody.value = '';
  selectedContactIds.value = [];
  await loadRooms();
  await openRoom(created.id);
}

const canManageMembers = computed(() => {
  const role = roomDetails.value?.myRole;
  return role === 'owner' || role === 'admin' || role === 'moderator';
});

const availableToAdd = computed(() => {
  const existing = new Set((roomDetails.value?.members ?? []).map((m) => m.personId));
  return contacts.value.filter((c) => !existing.has(c.personId));
});

async function openMembersModal(): Promise<void> {
  isMembersOpen.value = true;
  if (props.organizationId && contacts.value.length === 0) {
    const res = await chatApi.listContacts(props.organizationId);
    contacts.value = res.items;
  }
}

async function addRoomMember(): Promise<void> {
  if (!activeRoomId.value || !selectedAddPersonId.value) return;
  await chatApi.addMember(props.organizationId, activeRoomId.value, selectedAddPersonId.value);
  selectedAddPersonId.value = '';
  roomDetails.value = await chatApi.getRoom(props.organizationId, activeRoomId.value);
  await loadRooms();
}

async function removeRoomMember(personId: string): Promise<void> {
  if (!activeRoomId.value) return;
  await chatApi.removeMember(props.organizationId, activeRoomId.value, personId);
  roomDetails.value = await chatApi.getRoom(props.organizationId, activeRoomId.value);
  await loadRooms();
}

async function leaveCurrentRoom(): Promise<void> {
  if (!activeRoomId.value) return;
  const rid = activeRoomId.value;
  await chatApi.leaveRoom(props.organizationId, rid);
  isMembersOpen.value = false;
  unsubRoom?.();
  unsubRoom = null;
  activeRoomId.value = '';
  roomDetails.value = null;
  messages.value = [];
  await loadRooms();
}

function openContextMenu(event: MouseEvent, msg: ChatMessage): void {
  if (msg.deletedAt || msg.optimisticPending) return;
  const x = Math.min(event.clientX, window.innerWidth - 200);
  const y = Math.min(event.clientY, window.innerHeight - 240);
  contextMenu.value = { x: Math.max(8, x), y: Math.max(8, y), message: msg };
}

function closeContextMenu(): void {
  contextMenu.value = null;
}

async function syncActiveRoomOnReconnect(): Promise<void> {
  if (!props.organizationId) return;
  await loadRooms();
  if (!activeRoomId.value) return;
  try {
    const res = await chatApi.listMessages(props.organizationId, activeRoomId.value);
    const latest = [...res.items].reverse();
    const byId = new Map(messages.value.map((m) => [m.id, m]));
    for (const m of latest) {
      byId.set(m.id, m);
    }
    messages.value = Array.from(byId.values()).sort(
      (a, b) => new Date(a.createdAt).getTime() - new Date(b.createdAt).getTime(),
    );
  } catch {
    // Room may have been revoked while offline
  }
}

async function toggleArchiveRoom(): Promise<void> {
  if (!activeRoom.value) return;
  await chatApi.updatePreferences(props.organizationId, activeRoom.value.id, {
    archived: !activeRoom.value.archivedAt,
  });
  await loadRooms();
  activeRoomId.value = '';
  roomDetails.value = null;
}

async function toggleMuteRoom(): Promise<void> {
  if (!activeRoom.value) return;
  const isMuted = Boolean(
    activeRoom.value.mutedUntil && new Date(activeRoom.value.mutedUntil).getTime() > Date.now(),
  );
  await chatApi.updatePreferences(props.organizationId, activeRoom.value.id, {
    mutedMinutes: isMuted ? 0 : 480,
  });
  await openRoom(activeRoom.value.id);
}

watch(searchQuery, (val) => {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    void loadRooms();
    if (val.trim().length >= 2 && props.organizationId) {
      void chatApi
        .searchMessages(props.organizationId, val.trim())
        .then((r) => {
          searchResults.value = r.items;
        })
        .catch(() => undefined);
    } else {
      searchResults.value = [];
    }
  }, 300);
});

watch(tab, () => {
  void loadRooms();
});

watch(
  () => props.organizationId,
  (org) => {
    if (!org) return;
    void realtimeClient.connect(org);
    unsubUser?.();
    unsubOrg?.();
    if (props.currentPersonId) {
      unsubUser = realtimeClient.subscribe(`user:${props.currentPersonId}`, handleUserOrOrgEvent);
    }
    unsubOrg = realtimeClient.subscribe(`org:${org}`, handleUserOrOrgEvent);
    void loadRooms().then(() => {
      if (
        activeRoomId.value &&
        (!roomDetails.value || roomDetails.value.id !== activeRoomId.value)
      ) {
        void openRoom(activeRoomId.value);
      }
    });
  },
  { immediate: true },
);

let hadDisconnect = false;
onMounted(() => {
  unsubStatus = realtimeClient.onStatusChange((st) => {
    wsStatus.value = st;
    if (st === 'disconnected') {
      hadDisconnect = true;
    } else if (st === 'connected') {
      void flushOfflineQueue();
      if (hadDisconnect) {
        hadDisconnect = false;
        void syncActiveRoomOnReconnect();
      }
    }
  });
  window.addEventListener('online', flushOfflineQueue);
  window.addEventListener('click', closeContextMenu);
  typingGcTimer = setInterval(() => {
    const now = Date.now();
    const copy = { ...typingUsers.value };
    let changed = false;
    for (const [k, v] of Object.entries(copy)) {
      if (v.expiresAt <= now) {
        delete copy[k];
        changed = true;
      }
    }
    if (changed) typingUsers.value = copy;
  }, 1500);
  if (props.initialRoomId) {
    void openRoom(props.initialRoomId);
  }
});

onBeforeUnmount(() => {
  unsubStatus?.();
  unsubRoom?.();
  unsubUser?.();
  unsubOrg?.();
  window.removeEventListener('online', flushOfflineQueue);
  window.removeEventListener('click', closeContextMenu);
  if (draftTimer) clearTimeout(draftTimer);
  if (typingStopTimer) clearTimeout(typingStopTimer);
  if (searchTimer) clearTimeout(searchTimer);
  if (typingGcTimer) clearInterval(typingGcTimer);
});
</script>

<template>
  <section
    class="uchat"
    :class="{ 'uchat--has-active': Boolean(activeRoomId) }"
  >
    <aside class="uchat__sidebar">
      <div class="uchat__sidebar-top">
        <div>
          <h2 class="uchat__title">{{ chatT('title') }}</h2>
          <span class="uchat__ws-pill">
            <span
              class="uchat__dot"
              :class="{
                'uchat__dot--online': wsStatus === 'connected',
                'uchat__dot--connecting': wsStatus === 'connecting',
              }"
            />
            {{ wsStatusLabel() }}
          </span>
        </div>
        <AppButton
          size="s"
          variant="primary"
          @click="openNewChatModal"
        >
          {{ chatT('newChat') }}
        </AppButton>
      </div>

      <div class="uchat__search">
        <AppInput
          id="uchat-search"
          v-model="searchQuery"
          :placeholder="chatT('searchPlaceholder')"
        />
      </div>

      <div class="uchat__tabs">
        <button
          v-for="key in ['all', 'unread', 'group', 'archived'] as const"
          :key="key"
          type="button"
          class="uchat__tab"
          :class="{ 'uchat__tab--active': tab === key }"
          @click="tab = key"
        >
          {{
            key === 'all'
              ? chatT('tabAll')
              : key === 'unread'
                ? chatT('tabUnread')
                : key === 'group'
                  ? chatT('tabGroups')
                  : chatT('tabArchived')
          }}
        </button>
      </div>

      <div
        v-if="searchResults.length > 0"
        class="uchat__search"
      >
        <div class="uchat__header-meta">{{ chatT('searchMessagesTitle') }}</div>
        <button
          v-for="hit in searchResults"
          :key="hit.id"
          type="button"
          class="uchat__room-btn"
          @click="
            jumpToMessage(hit.roomId, hit.id);
            searchResults = [];
          "
        >
          <strong>{{ hit.roomTitle }} — {{ hit.senderName }}</strong>
          <span class="uchat__room-preview">{{ hit.body }}</span>
        </button>
      </div>

      <ul class="uchat__rooms">
        <li
          v-if="rooms.length === 0"
          class="uchat__empty"
        >
          {{ chatT('emptyRooms') }}
        </li>
        <li
          v-for="room in rooms"
          :key="room.id"
        >
          <button
            type="button"
            class="uchat__room-btn"
            :class="{ 'uchat__room-btn--active': room.id === activeRoomId }"
            @click="openRoom(room.id)"
          >
            <div class="uchat__room-top">
              <span class="uchat__room-name">
                <span
                  v-if="room.peerPresence"
                  class="uchat__dot"
                  :class="{ 'uchat__dot--online': room.peerPresence.status === 'online' }"
                />
                {{ room.peerName || room.title }}
              </span>
              <AppBadge
                v-if="room.unreadCount > 0"
                variant="primary"
              >
                {{ room.unreadCount }}
              </AppBadge>
            </div>
            <div class="uchat__room-preview">
              <template v-if="room.draftText">
                <strong>{{ chatT('draftLabel') }}</strong>
                {{ room.draftText }}
              </template>
              <template v-else>
                {{
                  room.lastMessageBody ||
                  (room.lastMessageKind ? `[${room.lastMessageKind}]` : room.roomType)
                }}
              </template>
            </div>
          </button>
        </li>
      </ul>
    </aside>

    <main class="uchat__main">
      <template v-if="activeRoom">
        <header class="uchat__header">
          <div>
            <div class="uchat__room-name">
              <button
                type="button"
                class="uchat__act-btn"
                @click="activeRoomId = ''"
              >
                ←
              </button>
              <strong>{{ activeRoom.peerName || activeRoom.title }}</strong>
            </div>
            <div class="uchat__header-meta">
              <span v-if="activeTypingText">{{ activeTypingText }}</span>
              <span v-else-if="activeRoom.roomType === 'direct' && activeRoom.peerPresence">
                {{
                  activeRoom.peerPresence.status === 'online' ? chatT('online') : chatT('offline')
                }}
              </span>
              <span v-else>{{ activeRoom.memberCount }} {{ chatT('membersCount') }}</span>
            </div>
          </div>
          <div class="uchat__msg-actions">
            <button
              type="button"
              class="uchat__act-btn"
              @click="openMembersModal"
            >
              {{ chatT('membersTitle') }}
            </button>
            <button
              type="button"
              class="uchat__act-btn"
              @click="toggleMuteRoom"
            >
              {{ activeRoom.mutedUntil ? chatT('unmuteRoom') : chatT('muteRoom') }}
            </button>
            <button
              type="button"
              class="uchat__act-btn"
              @click="toggleArchiveRoom"
            >
              {{ activeRoom.archivedAt ? chatT('unarchiveRoom') : chatT('archiveRoom') }}
            </button>
          </div>
        </header>

        <div
          v-if="roomDetails?.pinnedMessage"
          class="uchat__pin-bar"
        >
          <span
            style="cursor: pointer"
            @click="
              roomDetails.pinnedMessage?.id &&
              jumpToMessage(activeRoomId, String(roomDetails.pinnedMessage.id))
            "
          >
            <strong>{{ chatT('pinnedLabel') }}</strong>
            {{ String(roomDetails.pinnedMessage.body || roomDetails.pinnedMessage.kind || '') }}
          </span>
          <button
            type="button"
            class="uchat__act-btn"
            @click="onPin(null)"
          >
            {{ chatT('unpin') }}
          </button>
        </div>

        <p
          v-if="errorBanner"
          class="uchat__empty"
        >
          {{ errorBanner }}
        </p>

        <div
          ref="messagesBox"
          class="uchat__messages"
          @scroll="onMessagesScroll"
        >
          <button
            v-if="hasMoreMessages"
            type="button"
            class="uchat__act-btn uchat__load-older"
            :disabled="loadingOlder"
            @click="loadOlderMessages"
          >
            {{ loadingOlder ? chatT('loadingOlder') : chatT('loadOlder') }}
          </button>

          <article
            v-for="msg in messages"
            :key="msg.id"
            :data-message-id="msg.id"
            class="uchat__msg"
            :class="{
              'uchat__msg--mine': msg.senderPersonId === currentPersonId,
              'uchat__msg--deleted': Boolean(msg.deletedAt),
              'uchat__msg--highlighted': highlightedMessageId === msg.id,
            }"
            @contextmenu.prevent="openContextMenu($event, msg)"
          >
            <div
              v-if="msg.senderPersonId !== currentPersonId && msg.senderName"
              class="uchat__msg-sender"
            >
              {{ msg.senderName }}
            </div>

            <div
              v-if="msg.forwardedFromMessageId"
              class="uchat__header-meta"
            >
              {{ chatT('forwardedBadge') }}
            </div>

            <div
              v-if="msg.replyTo"
              class="uchat__msg-quote"
              style="cursor: pointer"
              @click="jumpToMessage(activeRoomId, msg.replyTo.id)"
            >
              {{ msg.replyTo.body || '...' }}
            </div>

            <div
              v-if="msg.deletedAt"
              class="uchat__msg-body"
            >
              {{ chatT('deletedPlaceholder') }}
            </div>
            <template v-else>
              <VoiceMessagePlayer
                v-if="msg.kind === 'voice' && getPlaybackUrl(msg)"
                :src="getPlaybackUrl(msg)!"
                :duration-hint="msg.media?.duration ?? undefined"
              />
              <AudioPlayer
                v-else-if="msg.kind === 'audio' && getPlaybackUrl(msg)"
                :src="getPlaybackUrl(msg)!"
                :duration-hint="msg.media?.duration ?? undefined"
                :show-download="true"
              />
              <VideoNotePlayer
                v-else-if="msg.kind === 'video_note' && getPlaybackUrl(msg)"
                :src="getPlaybackUrl(msg)!"
              />
              <VideoPlayer
                v-else-if="msg.kind === 'video' && getPlaybackUrl(msg)"
                :src="getPlaybackUrl(msg)!"
                :title="msg.media?.name || 'Video'"
              />
              <MediaPlayer
                v-else-if="msg.kind === 'image' && getPlaybackUrl(msg)"
                kind="image"
                :src="getPlaybackUrl(msg)!"
                :name="msg.media?.name || 'Image'"
              />
              <FileAttachment
                v-else-if="msg.media && getPlaybackUrl(msg)"
                :name="msg.media.name"
                :size="msg.media.size"
                :mime="msg.media.mime"
                :url="getPlaybackUrl(msg)!"
              />
              <div
                v-else-if="msg.kind === 'activity_card'"
                class="uchat__msg-quote"
              >
                <strong>{{ String(msg.metadata?.title || 'Activity') }}</strong>
                <div v-if="msg.metadata?.activityId">
                  <RouterLink :to="`/activities/${String(msg.metadata.activityId)}/run`">
                    Open Activity
                  </RouterLink>
                </div>
              </div>
              <div
                v-if="msg.body"
                class="uchat__msg-body"
              >
                {{ msg.body }}
              </div>
            </template>

            <div
              v-if="msg.reactions?.length"
              class="uchat__reactions"
            >
              <button
                v-for="r in msg.reactions"
                :key="r.emoji"
                type="button"
                class="uchat__reaction"
                :class="{ 'uchat__reaction--mine': r.reacted }"
                @click="onReact(msg, r.emoji)"
              >
                {{ r.emoji }} {{ r.count }}
              </button>
            </div>

            <div class="uchat__msg-footer">
              <span>
                {{
                  msg.optimisticPending
                    ? chatT('queuedOffline')
                    : new Date(msg.createdAt).toLocaleTimeString([], {
                        hour: '2-digit',
                        minute: '2-digit',
                      })
                }}
                <template v-if="msg.editedAt">· {{ chatT('editedBadge') }}</template>
                <template v-if="msg.senderPersonId === currentPersonId && !msg.optimisticPending">
                  · {{ msg.isReadByPeer ? '✓✓' : '✓' }}
                </template>
              </span>

              <div
                v-if="!msg.deletedAt && !msg.optimisticPending"
                class="uchat__msg-actions"
              >
                <button
                  v-for="em in quickEmojis.slice(0, 2)"
                  :key="em"
                  type="button"
                  class="uchat__act-btn"
                  @click="onReact(msg, em)"
                >
                  {{ em }}
                </button>
                <button
                  type="button"
                  class="uchat__act-btn"
                  @click="replyingTo = msg"
                >
                  {{ chatT('reply') }}
                </button>
                <button
                  type="button"
                  class="uchat__act-btn"
                  @click="forwardingMessage = msg"
                >
                  {{ chatT('forward') }}
                </button>
                <button
                  type="button"
                  class="uchat__act-btn"
                  @click="onPin(msg.id)"
                >
                  {{ chatT('pin') }}
                </button>
                <button
                  v-if="msg.senderPersonId === currentPersonId && msg.body"
                  type="button"
                  class="uchat__act-btn"
                  @click="
                    editingMessage = msg;
                    composerText = msg.body || '';
                  "
                >
                  {{ chatT('edit') }}
                </button>
                <button
                  type="button"
                  class="uchat__act-btn"
                  @click="onDeleteMessage(msg, 'for_me')"
                >
                  {{ chatT('deleteForMe') }}
                </button>
                <button
                  v-if="msg.senderPersonId === currentPersonId"
                  type="button"
                  class="uchat__act-btn"
                  @click="onDeleteMessage(msg, 'for_all')"
                >
                  {{ chatT('deleteForAll') }}
                </button>
                <button
                  type="button"
                  class="uchat__act-btn"
                  :aria-label="chatT('moreActions')"
                  @click.stop="openContextMenu($event, msg)"
                >
                  ⋯
                </button>
              </div>
            </div>
          </article>
        </div>

        <footer class="uchat__composer">
          <div
            v-if="editingMessage || replyingTo"
            class="uchat__pin-bar"
          >
            <span>
              <strong>{{ editingMessage ? chatT('editingTitle') : chatT('replyingTitle') }}</strong>
              :
              {{ (editingMessage || replyingTo)?.body || '...' }}
            </span>
            <button
              type="button"
              class="uchat__act-btn"
              @click="
                editingMessage = null;
                replyingTo = null;
                composerText = '';
              "
            >
              ✕
            </button>
          </div>

          <AudioRecorder
            v-if="isRecordingVoice"
            @record-complete="
              (p) => uploadAndSendMedia('voice', p.blob, 'voice.webm', p.durationSeconds)
            "
            @cancel="isRecordingVoice = false"
          />
          <VideoNoteRecorder
            v-else-if="isRecordingVideo"
            @record-complete="
              (p) => uploadAndSendMedia('video_note', p.blob, 'video-note.webm', p.durationSeconds)
            "
            @cancel="isRecordingVideo = false"
          />

          <div class="uchat__composer-row">
            <button
              type="button"
              class="uchat__act-btn"
              @click="isRecordingVoice = !isRecordingVoice"
            >
              🎤 {{ chatT('voiceMessage') }}
            </button>
            <button
              type="button"
              class="uchat__act-btn"
              @click="isRecordingVideo = !isRecordingVideo"
            >
              🎥 {{ chatT('videoNote') }}
            </button>
            <button
              type="button"
              class="uchat__act-btn"
              @click="isAttachingFile = !isAttachingFile"
            >
              📎 {{ chatT('attachFile') }}
            </button>
          </div>

          <AppFileDropzone
            v-if="isAttachingFile || uploadMediaBusy"
            compact
            :busy="uploadMediaBusy"
            :progress="uploadMediaProgress"
            :cancellable="uploadMediaBusy"
            :status-text="uploadMediaBusy ? chatT('attachFile') : ''"
            @select="onFilesSelected"
            @cancel="cancelMediaUpload"
          />

          <div class="uchat__composer-row">
            <textarea
              v-model="composerText"
              class="uchat__textarea"
              :placeholder="chatT('messagePlaceholder')"
              @input="onComposerInput"
              @keydown.enter.exact.prevent="sendTextMessage"
            />
            <AppButton
              variant="primary"
              @click="sendTextMessage"
            >
              {{ editingMessage ? chatT('saveEdit') : chatT('send') }}
            </AppButton>
          </div>
        </footer>
      </template>

      <div
        v-else
        class="uchat__empty"
      >
        {{ chatT('selectRoomHint') }}
      </div>
    </main>

    <div
      v-if="isNewChatOpen"
      class="uchat__modal-backdrop"
      @click.self="isNewChatOpen = false"
    >
      <div class="uchat__modal">
        <h3 class="uchat__title">{{ chatT('newChat') }}</h3>
        <div class="uchat__tabs">
          <button
            v-for="rt in ['direct', 'group', 'organization'] as const"
            :key="rt"
            type="button"
            class="uchat__tab"
            :class="{ 'uchat__tab--active': newRoomType === rt }"
            @click="newRoomType = rt"
          >
            {{
              rt === 'direct'
                ? chatT('roomTypeDirect')
                : rt === 'group'
                  ? chatT('roomTypeGroup')
                  : chatT('roomTypeOrg')
            }}
          </button>
        </div>
        <AppInput
          id="uchat-new-title"
          v-model="newRoomTitle"
          :label="chatT('roomTitleLabel')"
        />
        <div v-if="newRoomType !== 'organization'">
          <div class="uchat__header-meta">{{ chatT('selectParticipants') }}</div>
          <label
            v-for="c in contacts"
            :key="c.personId"
            class="uchat__room-btn"
          >
            <input
              v-model="selectedContactIds"
              type="checkbox"
              :value="c.personId"
            />
            {{ c.name }} ({{ c.role }})
          </label>
        </div>
        <AppInput
          id="uchat-new-body"
          v-model="newRoomFirstBody"
          :label="chatT('firstMessage')"
        />
        <div class="uchat__msg-actions">
          <AppButton
            variant="primary"
            @click="submitNewChat"
          >
            {{ chatT('createRoom') }}
          </AppButton>
          <AppButton
            variant="ghost"
            @click="isNewChatOpen = false"
          >
            {{ chatT('cancelEdit') }}
          </AppButton>
        </div>
      </div>
    </div>

    <div
      v-if="forwardingMessage"
      class="uchat__modal-backdrop"
      @click.self="forwardingMessage = null"
    >
      <div class="uchat__modal">
        <h3 class="uchat__title">{{ chatT('forwardingTitle') }}</h3>
        <button
          v-for="r in rooms"
          :key="r.id"
          type="button"
          class="uchat__room-btn"
          @click="onForwardTo(r.id)"
        >
          {{ r.peerName || r.title }}
        </button>
        <AppButton
          variant="ghost"
          @click="forwardingMessage = null"
        >
          {{ chatT('cancelEdit') }}
        </AppButton>
      </div>
    </div>

    <div
      v-if="isMembersOpen && roomDetails"
      class="uchat__modal-backdrop"
      @click.self="isMembersOpen = false"
    >
      <div class="uchat__modal">
        <h3 class="uchat__title">{{ chatT('membersTitle') }} ({{ roomDetails.members.length }})</h3>
        <div
          v-for="m in roomDetails.members"
          :key="m.personId"
          class="uchat__member-row"
        >
          <span>
            <span
              v-if="m.presence"
              class="uchat__dot"
              :class="{ 'uchat__dot--online': m.presence.status === 'online' }"
            />
            {{ m.name }} · {{ m.role }}
          </span>
          <button
            v-if="canManageMembers && m.personId !== currentPersonId"
            type="button"
            class="uchat__act-btn"
            @click="removeRoomMember(m.personId)"
          >
            {{ chatT('removeMember') }}
          </button>
        </div>

        <div
          v-if="canManageMembers && roomDetails.roomType !== 'direct' && availableToAdd.length > 0"
          class="uchat__composer-row"
        >
          <select
            v-model="selectedAddPersonId"
            class="uchat__textarea"
            style="min-height: 36px"
          >
            <option value="">{{ chatT('addMember') }}</option>
            <option
              v-for="c in availableToAdd"
              :key="c.personId"
              :value="c.personId"
            >
              {{ c.name }} ({{ c.role }})
            </option>
          </select>
          <AppButton
            size="s"
            variant="primary"
            :disabled="!selectedAddPersonId"
            @click="addRoomMember"
          >
            {{ chatT('addMember') }}
          </AppButton>
        </div>

        <div class="uchat__msg-actions">
          <AppButton
            v-if="roomDetails.roomType !== 'direct'"
            variant="ghost"
            @click="leaveCurrentRoom"
          >
            {{ chatT('leaveRoom') }}
          </AppButton>
          <AppButton
            variant="ghost"
            @click="isMembersOpen = false"
          >
            {{ chatT('close') }}
          </AppButton>
        </div>
      </div>
    </div>

    <div
      v-if="contextMenu"
      class="uchat__context-menu"
      :style="{ left: `${contextMenu.x}px`, top: `${contextMenu.y}px` }"
      @click.stop
    >
      <button
        type="button"
        class="uchat__context-item"
        @click="
          replyingTo = contextMenu.message;
          closeContextMenu();
        "
      >
        {{ chatT('reply') }}
      </button>
      <button
        type="button"
        class="uchat__context-item"
        @click="
          forwardingMessage = contextMenu.message;
          closeContextMenu();
        "
      >
        {{ chatT('forward') }}
      </button>
      <button
        type="button"
        class="uchat__context-item"
        @click="
          onPin(contextMenu.message.id);
          closeContextMenu();
        "
      >
        {{ chatT('pin') }}
      </button>
      <button
        v-if="contextMenu.message.senderPersonId === currentPersonId && contextMenu.message.body"
        type="button"
        class="uchat__context-item"
        @click="
          editingMessage = contextMenu.message;
          composerText = contextMenu.message.body || '';
          closeContextMenu();
        "
      >
        {{ chatT('edit') }}
      </button>
      <button
        type="button"
        class="uchat__context-item"
        @click="
          onDeleteMessage(contextMenu.message, 'for_me');
          closeContextMenu();
        "
      >
        {{ chatT('deleteForMe') }}
      </button>
      <button
        v-if="contextMenu.message.senderPersonId === currentPersonId"
        type="button"
        class="uchat__context-item uchat__context-item--danger"
        @click="
          onDeleteMessage(contextMenu.message, 'for_all');
          closeContextMenu();
        "
      >
        {{ chatT('deleteForAll') }}
      </button>
    </div>
  </section>
</template>
