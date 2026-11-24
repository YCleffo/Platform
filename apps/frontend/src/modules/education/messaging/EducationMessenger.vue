<script setup lang="ts">
import { computed, nextTick, onMounted, onScopeDispose, ref, shallowRef, watch } from 'vue';
import { formatZonedTime, formatCalendarDate } from '@/shared/formatting/calendar';
import { ChevronLeft, Plus, RotateCw, X, Message as MessageIcon, Check } from '@/shared/ui/icons';
import AudioPlayer from '@/shared/ui/media/AudioPlayer.vue';
import AudioRecorder from '@/shared/ui/media/AudioRecorder.vue';
import VideoNotePlayer from '@/shared/ui/media/VideoNotePlayer.vue';
import FileAttachment from '@/shared/ui/media/FileAttachment.vue';
import {
  educationApi,
  type Thread,
  type Message,
  type MessageReaction,
  type EducationContact,
} from '../api';
import { parentApi, type ParentChild } from '../parent/api';
import { useEducationI18n } from '../i18n';
import { useEducationError } from '../resources';
import EducationState from '../EducationState.vue';
import './messenger.css';

const props = withDefaults(
  defineProps<{
    role: 'teacher' | 'student' | 'parent';
    organizationId: string;
    currentPersonId: string;
    initialThreadId?: string;
    parentChild?: ParentChild;
    timezone?: string;
  }>(),
  {
    initialThreadId: '',
    parentChild: undefined,
    timezone: 'UTC',
  },
);

const emit = defineEmits<{
  'select-thread': [threadId: string];
}>();

const { t, locale } = useEducationI18n();
const issue = useEducationError();

const threads = ref<Thread[]>([]);
const selectedThreadId = ref<string>(props.initialThreadId);
const messages = ref<Message[]>([]);
const contacts = ref<EducationContact[]>([]);

const loadingThreads = ref(false);
const loadingMessages = ref(false);
const loadingContacts = ref(false);
const sendingMessage = ref(false);
const startingThread = ref(false);

const errorThreads = shallowRef<unknown>(null);
const errorMessages = shallowRef<unknown>(null);
const errorAction = shallowRef<unknown>(null);

const searchQuery = ref('');
const activeTab = ref<'all' | 'unread'>('all');
const composerText = ref('');
const isRecordingVoice = ref(false);
const replyingTo = ref<Message | null>(null);
const isNewChatModalOpen = ref(false);
const contactSearchQuery = ref('');
const selectedContact = ref<EducationContact | null>(null);
const newChatSubject = ref('');
const newChatMessage = ref('');

const mobileView = ref<'list' | 'chat'>(props.initialThreadId ? 'chat' : 'list');
const messagesContainer = ref<HTMLElement | null>(null);

let pollTimer: ReturnType<typeof setInterval> | null = null;
let active = true;

const tz = computed(() => props.timezone || 'UTC');

const selectedThread = computed(
  () => threads.value.find((th) => th.id === selectedThreadId.value) || null,
);

const totalUnread = computed(() =>
  threads.value.reduce((acc, th) => acc + (th.unreadCount || 0), 0),
);

const filteredThreads = computed(() => {
  let list = threads.value;
  if (activeTab.value === 'unread') {
    list = list.filter((th) => (th.unreadCount || 0) > 0);
  }
  const q = searchQuery.value.trim().toLowerCase();
  if (q) {
    list = list.filter(
      (th) =>
        th.subject.toLowerCase().includes(q) ||
        (th.peerName && th.peerName.toLowerCase().includes(q)) ||
        (th.lastMessageBody && th.lastMessageBody.toLowerCase().includes(q)),
    );
  }
  return list;
});

const filteredContacts = computed(() => {
  const q = contactSearchQuery.value.trim().toLowerCase();
  if (!q) return contacts.value;
  return contacts.value.filter(
    (c) =>
      c.name.toLowerCase().includes(q) ||
      (c.contextName && c.contextName.toLowerCase().includes(q)),
  );
});

function getInitials(name?: string | null): string {
  if (!name) return '??';
  const parts = name.trim().split(/\s+/);
  if (parts.length >= 2 && parts[0] && parts[1]) {
    return ((parts[0][0] || '') + (parts[1][0] || '')).toUpperCase();
  }
  return name.slice(0, 2).toUpperCase();
}

function roleBadgeClass(role?: string | null): string {
  if (role === 'teacher') return 'edu-messenger__role-badge--teacher';
  if (role === 'student') return 'edu-messenger__role-badge--student';
  if (role === 'parent') return 'edu-messenger__role-badge--parent';
  return '';
}

function avatarClass(role?: string | null): string {
  if (role === 'teacher') return 'edu-messenger__avatar--teacher';
  if (role === 'student') return 'edu-messenger__avatar--student';
  if (role === 'parent') return 'edu-messenger__avatar--parent';
  return '';
}

function roleLabel(role?: string | null): string {
  if (role === 'teacher') return t('education.role_teacher');
  if (role === 'student') return t('education.role_student');
  if (role === 'parent') return t('education.role_parent');
  return '';
}

interface TextPart {
  isUrl: boolean;
  text: string;
  url?: string;
}

function linkify(text: string | null): TextPart[] {
  if (!text) return [];
  const urlRegex = /(https?:\/\/[^\s<]+[^<.,:;"')\]\s])/g;
  const parts: TextPart[] = [];
  let lastIndex = 0;
  let match: RegExpExecArray | null;

  while ((match = urlRegex.exec(text)) !== null) {
    if (match.index > lastIndex) {
      parts.push({
        isUrl: false,
        text: text.slice(lastIndex, match.index),
      });
    }
    const rawUrl = match[0];
    parts.push({
      isUrl: true,
      text: rawUrl,
      url: rawUrl,
    });
    lastIndex = match.index + rawUrl.length;
  }

  if (lastIndex < text.length) {
    parts.push({
      isUrl: false,
      text: text.slice(lastIndex),
    });
  }

  return parts;
}

function formatRelativeTime(dateIso?: string | null): string {
  if (!dateIso) return '';
  try {
    const d = new Date(dateIso);
    const now = new Date();
    const isToday =
      d.getDate() === now.getDate() &&
      d.getMonth() === now.getMonth() &&
      d.getFullYear() === now.getFullYear();

    if (isToday) {
      return formatZonedTime(dateIso, locale.value, tz.value);
    }
    return formatCalendarDate(dateIso.slice(0, 10), locale.value, {
      month: 'short',
      day: 'numeric',
    });
  } catch {
    return '';
  }
}

function formatDateDivider(dateIso: string): string {
  try {
    const todayStr = new Intl.DateTimeFormat('en-CA', {
      timeZone: tz.value,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date());

    const yesterdayStr = new Intl.DateTimeFormat('en-CA', {
      timeZone: tz.value,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date(Date.now() - 86400000));

    const msgDateStr = new Intl.DateTimeFormat('en-CA', {
      timeZone: tz.value,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date(dateIso));

    if (msgDateStr === todayStr) return t('education.today_divider');
    if (msgDateStr === yesterdayStr) return t('education.yesterday_divider');
    return formatCalendarDate(msgDateStr, locale.value, { dateStyle: 'medium' });
  } catch {
    return dateIso.slice(0, 10);
  }
}

function shouldShowDateDivider(index: number): boolean {
  if (index === 0) return true;
  const curr = messages.value[index];
  const prev = messages.value[index - 1];
  if (!curr || !prev) return false;
  return curr.createdAt.slice(0, 10) !== prev.createdAt.slice(0, 10);
}

function scrollToBottom(): void {
  void nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
  });
}

async function fetchThreads(silent = false): Promise<void> {
  if (!silent) loadingThreads.value = true;
  errorThreads.value = null;
  try {
    let result: { items: Thread[] };
    if (props.role === 'teacher') {
      result = await educationApi.threads(props.organizationId, 0);
    } else if (props.role === 'student') {
      result = await educationApi.studentThreads(props.organizationId, 0);
    } else {
      result = await parentApi.threads(props.parentChild!, 0);
    }
    if (active) {
      threads.value = result.items;
      if (!selectedThreadId.value && threads.value.length > 0 && mobileView.value !== 'list') {
        const first = threads.value[0];
        if (first) selectThread(first.id);
      }
    }
  } catch (err) {
    if (active && !silent) errorThreads.value = err;
  } finally {
    if (active && !silent) loadingThreads.value = false;
  }
}

async function fetchMessages(threadId: string, silent = false): Promise<void> {
  if (!threadId) return;
  if (!silent) loadingMessages.value = true;
  errorMessages.value = null;
  try {
    let result: { items: Message[] };
    if (props.role === 'teacher') {
      result = await educationApi.messages(props.organizationId, threadId, 0);
    } else if (props.role === 'student') {
      result = await educationApi.studentMessages(props.organizationId, threadId, 0);
    } else {
      result = await parentApi.messages(props.parentChild!, threadId, 0);
    }
    if (active && selectedThreadId.value === threadId) {
      messages.value = result.items;
      scrollToBottom();
      void markThreadRead(threadId);
    }
  } catch (err) {
    if (active && !silent) errorMessages.value = err;
  } finally {
    if (active && !silent) loadingMessages.value = false;
  }
}

async function markThreadRead(threadId: string): Promise<void> {
  if (!messages.value.length) return;
  const lastMsg = messages.value[messages.value.length - 1];
  if (!lastMsg) return;
  try {
    if (props.role === 'teacher') {
      await educationApi.readThread(props.organizationId, threadId, lastMsg.id);
    } else if (props.role === 'student') {
      await educationApi.studentReadThread(props.organizationId, threadId, lastMsg.id);
    } else {
      await parentApi.readThread(props.parentChild!, threadId, lastMsg.id);
    }
    const th = threads.value.find((item) => item.id === threadId);
    if (th) th.unreadCount = 0;
  } catch {
    // Non-blocking read cursor update
  }
}

async function fetchContacts(): Promise<void> {
  loadingContacts.value = true;
  try {
    let result: { items: EducationContact[] };
    if (props.role === 'teacher') {
      result = await educationApi.contacts(props.organizationId);
    } else if (props.role === 'student') {
      result = await educationApi.studentContacts(props.organizationId);
    } else {
      result = await parentApi.contacts(props.parentChild!);
    }
    if (active) contacts.value = result.items;
  } catch {
    // Handled gracefully in UI
  } finally {
    if (active) loadingContacts.value = false;
  }
}

function selectThread(threadId: string): void {
  selectedThreadId.value = threadId;
  mobileView.value = 'chat';
  emit('select-thread', threadId);
  void fetchMessages(threadId);
}

function goBackToList(): void {
  mobileView.value = 'list';
}

function setReplyingTo(msg: Message): void {
  replyingTo.value = msg;
}

function cancelReply(): void {
  replyingTo.value = null;
}

async function handleSendMessage(): Promise<void> {
  const body = composerText.value.trim();
  const threadId = selectedThreadId.value;
  if (!body || !threadId || sendingMessage.value) return;

  sendingMessage.value = true;
  errorAction.value = null;
  try {
    let sentMsg: Message;
    // Generate a per-send client key for idempotency (retry-safe)
    const clientKey = `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
    const replyToId = replyingTo.value?.id ?? null;
    if (props.role === 'teacher') {
      sentMsg = await educationApi.sendMessage(
        props.organizationId,
        threadId,
        body,
        clientKey,
        'text',
        null,
        replyToId,
      );
    } else if (props.role === 'student') {
      sentMsg = await educationApi.studentSendMessage(
        props.organizationId,
        threadId,
        body,
        clientKey,
        'text',
        null,
        replyToId,
      );
    } else {
      sentMsg = await parentApi.sendMessage(
        props.parentChild!,
        threadId,
        body,
        clientKey,
        'text',
        null,
        replyToId,
      );
    }
    composerText.value = '';
    replyingTo.value = null;
    messages.value.push(sentMsg);
    scrollToBottom();
    // Update last message in thread list
    const th = threads.value.find((item) => item.id === threadId);
    if (th) {
      th.lastMessageBody = sentMsg.body;
      th.lastMessageAt = sentMsg.createdAt;
      th.lastMessageSenderId = sentMsg.senderPersonId;
    }
  } catch (err) {
    errorAction.value = err;
  } finally {
    sendingMessage.value = false;
  }
}

async function onVoiceRecorded(payload: {
  blob: Blob;
  durationSeconds: number;
  mimeType: string;
}): Promise<void> {
  const threadId = selectedThreadId.value;
  if (!threadId || sendingMessage.value) return;

  sendingMessage.value = true;
  errorAction.value = null;
  try {
    let sentMsg: Message;
    const clientKey = `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
    const replyToId = replyingTo.value?.id ?? null;
    const metadata = { durationSeconds: payload.durationSeconds, mimeType: payload.mimeType };
    if (props.role === 'teacher') {
      sentMsg = await educationApi.sendMessage(
        props.organizationId,
        threadId,
        null,
        clientKey,
        'voice',
        null,
        replyToId,
        metadata,
      );
    } else if (props.role === 'student') {
      sentMsg = await educationApi.studentSendMessage(
        props.organizationId,
        threadId,
        null,
        clientKey,
        'voice',
        null,
        replyToId,
        metadata,
      );
    } else {
      sentMsg = await parentApi.sendMessage(
        props.parentChild!,
        threadId,
        null,
        clientKey,
        'voice',
        null,
        replyToId,
        metadata,
      );
    }
    replyingTo.value = null;
    isRecordingVoice.value = false;
    messages.value.push(sentMsg);
    scrollToBottom();
    const th = threads.value.find((item) => item.id === threadId);
    if (th) {
      th.lastMessageBody = `🎤 ${t('education.voiceMessage')} (${payload.durationSeconds}s)`;
      th.lastMessageAt = sentMsg.createdAt;
      th.lastMessageSenderId = sentMsg.senderPersonId;
    }
  } catch (err) {
    errorAction.value = err;
  } finally {
    sendingMessage.value = false;
  }
}

async function handleToggleReaction(msg: Message, emoji: string): Promise<void> {
  const threadId = selectedThreadId.value;
  if (!threadId) return;
  try {
    let res: { reacted: boolean; reactions: MessageReaction[] };
    if (props.role === 'teacher') {
      res = await educationApi.toggleReaction(props.organizationId, threadId, msg.id, emoji);
    } else if (props.role === 'student') {
      res = await educationApi.studentToggleReaction(props.organizationId, threadId, msg.id, emoji);
    } else {
      res = (await parentApi.toggleReaction(props.parentChild!, threadId, msg.id, emoji)) as {
        reacted: boolean;
        reactions: MessageReaction[];
      };
    }
    msg.reactions = res.reactions;
  } catch {
    // Non-blocking reaction update
  }
}

function onComposerKeydown(event: KeyboardEvent): void {
  if (event.key === 'Enter' && !event.shiftKey) {
    event.preventDefault();
    void handleSendMessage();
  }
}

function openNewChatModal(): void {
  selectedContact.value = null;
  newChatSubject.value = '';
  newChatMessage.value = '';
  isNewChatModalOpen.value = true;
  if (!contacts.value.length) void fetchContacts();
}

function closeNewChatModal(): void {
  isNewChatModalOpen.value = false;
}

function selectContact(c: EducationContact): void {
  selectedContact.value = c;
  if (!newChatSubject.value) {
    newChatSubject.value = t('education.conversationWith', { name: c.name });
  }
}

async function handleStartThread(): Promise<void> {
  const contact = selectedContact.value;
  const subject = newChatSubject.value.trim();
  const body = newChatMessage.value.trim();
  if (!contact || !subject || !body || startingThread.value) return;

  startingThread.value = true;
  errorAction.value = null;
  try {
    let newThread: Thread;
    const contextType = contact.contextType ?? null;
    const contextId = contact.contextId ?? null;

    if (props.role === 'teacher') {
      newThread = await educationApi.startThread(
        props.organizationId,
        contact.personId,
        subject,
        body,
        contextType ?? undefined,
        contextId ?? undefined,
      );
    } else if (props.role === 'student') {
      newThread = await educationApi.studentStartThread(
        props.organizationId,
        contact.personId,
        subject,
        body,
        contextType ?? undefined,
        contextId ?? undefined,
      );
    } else {
      newThread = await parentApi.startThread(props.parentChild!, contact.personId, subject, body);
    }
    closeNewChatModal();
    await fetchThreads(true);
    selectThread(newThread.id);
  } catch (err) {
    errorAction.value = err;
  } finally {
    startingThread.value = false;
  }
}

watch(
  () => props.initialThreadId,
  (newId) => {
    if (newId && newId !== selectedThreadId.value) {
      selectThread(newId);
    }
  },
);

onMounted(() => {
  void fetchThreads();
  if (selectedThreadId.value) {
    void fetchMessages(selectedThreadId.value);
  }
  pollTimer = setInterval(() => {
    if (active && !document.hidden) {
      void fetchThreads(true);
      if (selectedThreadId.value) {
        void fetchMessages(selectedThreadId.value, true);
      }
    }
  }, 4000);
});

onScopeDispose(() => {
  active = false;
  if (pollTimer) clearInterval(pollTimer);
});
</script>

<template>
  <div
    class="edu-messenger"
    :class="{
      'edu-messenger--view-list': mobileView === 'list',
      'edu-messenger--view-chat': mobileView === 'chat',
    }"
  >
    <!-- Left Sidebar: Conversations List -->
    <aside class="edu-messenger__sidebar">
      <header class="edu-messenger__sidebar-header">
        <h2>
          <MessageIcon :size="20" />
          {{ t('education.messages') }}
        </h2>
        <button
          type="button"
          class="edu-button edu-button--primary"
          @click="openNewChatModal"
        >
          <Plus :size="16" />
          {{ t('education.newChat') }}
        </button>
      </header>

      <div class="edu-messenger__search">
        <input
          v-model="searchQuery"
          type="search"
          class="edu-messenger__search-input"
          :placeholder="t('education.searchChats')"
        />
      </div>

      <div class="edu-messenger__filter-tabs">
        <button
          type="button"
          class="edu-messenger__filter-tab"
          :class="{ 'edu-messenger__filter-tab--active': activeTab === 'all' }"
          @click="activeTab = 'all'"
        >
          {{ t('education.allChats') }}
          <span>({{ threads.length }})</span>
        </button>
        <button
          type="button"
          class="edu-messenger__filter-tab"
          :class="{ 'edu-messenger__filter-tab--active': activeTab === 'unread' }"
          @click="activeTab = 'unread'"
        >
          {{ t('education.unreadChats') }}
          <span
            v-if="totalUnread > 0"
            class="edu-messenger__unread-badge"
          >
            {{ totalUnread }}
          </span>
        </button>
      </div>

      <EducationState
        :loading="loadingThreads"
        :error="errorThreads"
        :empty="!loadingThreads && !filteredThreads.length ? t('education.noThreads') : undefined"
        @retry="fetchThreads()"
      />

      <ul
        v-if="filteredThreads.length > 0"
        class="edu-messenger__thread-list edu-list"
      >
        <li
          v-for="th in filteredThreads"
          :key="th.id"
        >
          <a
            :href="`/messages/${th.id}`"
            class="edu-messenger__thread-item edu-list__item"
            :class="{ 'edu-messenger__thread-item--active': th.id === selectedThreadId }"
            @click.prevent="selectThread(th.id)"
          >
            <div
              class="edu-messenger__avatar"
              :class="avatarClass(th.peerRole)"
            >
              {{ getInitials(th.peerName || th.subject) }}
            </div>
            <div class="edu-messenger__thread-info">
              <div class="edu-messenger__thread-top">
                <span class="edu-messenger__thread-name">{{ th.peerName || th.subject }}</span>
                <span class="edu-messenger__thread-time">
                  {{ formatRelativeTime(th.lastMessageAt || th.lastActivityAt || th.createdAt) }}
                </span>
              </div>
              <div class="edu-messenger__thread-meta">
                <span
                  v-if="th.peerRole"
                  class="edu-messenger__role-badge"
                  :class="roleBadgeClass(th.peerRole)"
                >
                  {{ roleLabel(th.peerRole) }}
                </span>
                <span
                  v-if="th.subject && th.peerName"
                  class="edu-messenger__context-tag"
                >
                  {{ th.subject }}
                </span>
              </div>
              <div class="edu-messenger__thread-bottom">
                <span class="edu-messenger__last-msg">
                  {{
                    th.lastMessageBody
                      ? th.lastMessageSenderId === currentPersonId
                        ? `${t('education.you')}: ${th.lastMessageBody}`
                        : th.lastMessageBody
                      : t('education.noMessagesInThread')
                  }}
                </span>
                <span
                  v-if="(th.unreadCount || 0) > 0"
                  class="edu-messenger__unread-badge"
                >
                  {{ th.unreadCount }}
                </span>
              </div>
            </div>
          </a>
        </li>
      </ul>
    </aside>

    <!-- Right Pane: Active Chat -->
    <main class="edu-messenger__chat-pane">
      <template v-if="selectedThread">
        <header class="edu-messenger__chat-header">
          <div class="edu-messenger__chat-header-info">
            <button
              type="button"
              class="edu-messenger__mobile-back"
              :aria-label="t('education.back')"
              @click="goBackToList"
            >
              <ChevronLeft :size="24" />
            </button>
            <div
              class="edu-messenger__avatar"
              :class="avatarClass(selectedThread.peerRole)"
            >
              {{ getInitials(selectedThread.peerName || selectedThread.subject) }}
            </div>
            <div class="edu-messenger__chat-title">
              <strong>
                {{ selectedThread.peerName || selectedThread.subject }}
                <span
                  v-if="selectedThread.peerRole"
                  class="edu-messenger__role-badge"
                  :class="roleBadgeClass(selectedThread.peerRole)"
                >
                  {{ roleLabel(selectedThread.peerRole) }}
                </span>
              </strong>
              <div
                v-if="selectedThread.subject && selectedThread.peerName"
                class="edu-messenger__chat-context"
              >
                {{ t('education.chatContext') }}: {{ selectedThread.subject }}
              </div>
            </div>
          </div>
          <div>
            <button
              type="button"
              class="edu-icon-button"
              :title="t('education.refresh')"
              :aria-label="t('education.refresh')"
              :disabled="loadingMessages"
              @click="fetchMessages(selectedThread.id)"
            >
              <RotateCw :size="16" />
            </button>
          </div>
        </header>

        <EducationState
          :loading="loadingMessages"
          :error="errorMessages"
          @retry="fetchMessages(selectedThread.id)"
        />

        <div
          ref="messagesContainer"
          class="edu-messenger__messages"
        >
          <div
            v-if="!loadingMessages && messages.length === 0"
            class="edu-messenger__empty-chat"
          >
            <p>{{ t('education.noMessagesInThread') }}</p>
          </div>

          <template
            v-for="(msg, idx) in messages"
            :key="msg.id"
          >
            <div
              v-if="shouldShowDateDivider(idx)"
              class="edu-messenger__date-divider"
            >
              {{ formatDateDivider(msg.createdAt) }}
            </div>

            <div
              class="edu-messenger__bubble-group"
              :class="
                msg.senderPersonId === currentPersonId
                  ? 'edu-messenger__bubble-group--outgoing'
                  : 'edu-messenger__bubble-group--incoming'
              "
            >
              <span
                v-if="msg.senderPersonId !== currentPersonId && msg.senderName"
                class="edu-messenger__bubble-sender"
              >
                {{ msg.senderName }}
              </span>
              <div class="edu-messenger__bubble-wrapper">
                <div class="edu-messenger__actions-bar">
                  <button
                    type="button"
                    class="edu-messenger__action-btn"
                    title="Like"
                    aria-label="Like"
                    @click="handleToggleReaction(msg, '👍')"
                  >
                    👍
                  </button>
                  <button
                    type="button"
                    class="edu-messenger__action-btn"
                    title="Heart"
                    aria-label="Heart"
                    @click="handleToggleReaction(msg, '❤️')"
                  >
                    ❤️
                  </button>
                  <button
                    type="button"
                    class="edu-messenger__action-btn"
                    title="Clap"
                    aria-label="Clap"
                    @click="handleToggleReaction(msg, '👏')"
                  >
                    👏
                  </button>
                  <button
                    type="button"
                    class="edu-messenger__action-btn"
                    :title="t('education.reply')"
                    :aria-label="t('education.reply')"
                    @click="setReplyingTo(msg)"
                  >
                    ↩️
                  </button>
                </div>

                <div
                  class="edu-messenger__bubble"
                  :class="
                    msg.senderPersonId === currentPersonId
                      ? 'edu-messenger__bubble--outgoing'
                      : 'edu-messenger__bubble--incoming'
                  "
                >
                  <div
                    v-if="msg.replyTo"
                    class="edu-messenger__reply-quote"
                  >
                    <strong>
                      {{
                        msg.replyTo.senderPersonId === currentPersonId
                          ? t('education.you')
                          : t('education.message')
                      }}
                    </strong>
                    <span>{{ msg.replyTo.body }}</span>
                  </div>

                  <!-- Voice message player -->
                  <div
                    v-if="msg.kind === 'voice' || msg.kind === 'audio'"
                    class="edu-messenger__media-block"
                  >
                    <AudioPlayer
                      :asset="{
                        id: msg.id,
                        kind: 'voice',
                        originalName: 'voice-note.webm',
                        originalMime: 'audio/webm',
                        sizeBytes: 0,
                        status: 'READY',
                        durationSeconds: Number(msg.metadata?.durationSeconds ?? 5),
                      }"
                    />
                  </div>

                  <!-- Video note player -->
                  <div
                    v-else-if="msg.kind === 'video_note'"
                    class="edu-messenger__media-block"
                  >
                    <VideoNotePlayer
                      :asset="{
                        id: msg.id,
                        kind: 'video_note',
                        originalName: 'video-note.mp4',
                        originalMime: 'video/mp4',
                        sizeBytes: 0,
                        status: 'READY',
                        durationSeconds: Number(msg.metadata?.durationSeconds ?? 10),
                      }"
                    />
                  </div>

                  <!-- Document attachment -->
                  <div
                    v-else-if="msg.kind === 'document'"
                    class="edu-messenger__media-block"
                  >
                    <FileAttachment
                      :name="msg.body || 'attachment.pdf'"
                      :asset="{
                        id: msg.id,
                        kind: 'document',
                        originalName: msg.body || 'attachment.pdf',
                        originalMime: 'application/octet-stream',
                        sizeBytes: Number(msg.metadata?.size ?? 1024),
                        status: 'READY',
                      }"
                    />
                  </div>

                  <!-- Text message parts -->
                  <template v-if="msg.body">
                    <p class="edu-message">
                      <template
                        v-for="(part, pIdx) in linkify(msg.body)"
                        :key="pIdx"
                      >
                        <a
                          v-if="part.isUrl"
                          :href="part.url"
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          {{ part.text }}
                        </a>
                        <template v-else>{{ part.text }}</template>
                      </template>
                    </p>
                  </template>

                  <div class="edu-messenger__bubble-meta">
                    <time>{{ formatZonedTime(msg.createdAt, locale, tz) }}</time>
                    <Check
                      v-if="msg.senderPersonId === currentPersonId"
                      :size="12"
                    />
                  </div>
                </div>

                <!-- Reactions strip -->
                <div
                  v-if="msg.reactions && msg.reactions.length > 0"
                  class="edu-messenger__reactions"
                >
                  <button
                    v-for="react in msg.reactions"
                    :key="react.emoji"
                    type="button"
                    class="edu-messenger__reaction-pill"
                    :class="{ 'edu-messenger__reaction-pill--active': react.reacted }"
                    @click="handleToggleReaction(msg, react.emoji)"
                  >
                    <span>{{ react.emoji }}</span>
                    <span>{{ react.count }}</span>
                  </button>
                </div>
              </div>
            </div>
          </template>
        </div>

        <!-- Replying banner -->
        <div
          v-if="replyingTo"
          class="edu-messenger__reply-banner"
        >
          <div>
            <strong>
              {{ t('education.replyingTo') }}: {{ replyingTo.senderName || t('education.message') }}
            </strong>
            <span>
              {{ replyingTo.body ? replyingTo.body.slice(0, 60) : t('education.voiceMessage') }}
            </span>
          </div>
          <button
            type="button"
            class="edu-icon-button"
            :title="t('education.cancelReply')"
            :aria-label="t('education.cancelReply')"
            @click="cancelReply"
          >
            <X :size="16" />
          </button>
        </div>

        <!-- Inline Voice Recorder -->
        <div
          v-if="isRecordingVoice"
          class="edu-messenger__composer"
        >
          <AudioRecorder
            @record-complete="onVoiceRecorded"
            @cancel="isRecordingVoice = false"
          />
        </div>

        <form
          v-else
          class="edu-messenger__composer"
          @submit.prevent="handleSendMessage"
        >
          <textarea
            id="education-reply"
            v-model="composerText"
            class="edu-messenger__composer-input"
            :placeholder="t('education.typeMessage')"
            rows="1"
            maxlength="5000"
            required
            :disabled="sendingMessage"
            @keydown="onComposerKeydown"
          ></textarea>
          <div class="edu-messenger__composer-actions">
            <button
              type="button"
              class="edu-button edu-button--secondary"
              :title="t('education.recordVoice')"
              :aria-label="t('education.recordVoice')"
              :disabled="sendingMessage"
              @click="isRecordingVoice = true"
            >
              🎤
            </button>
            <button
              type="submit"
              class="edu-button edu-button--primary"
              :disabled="sendingMessage || !composerText.trim()"
            >
              {{ t(sendingMessage ? 'education.loading' : 'education.send') }}
            </button>
          </div>
        </form>
      </template>

      <!-- Empty State: No Thread Selected -->
      <div
        v-else
        class="edu-messenger__empty-chat"
      >
        <div class="edu-messenger__empty-icon">
          <MessageIcon :size="28" />
        </div>
        <h3>{{ t('education.messages') }}</h3>
        <p>{{ t('education.selectChatHint') }}</p>
        <button
          type="button"
          class="edu-button edu-button--primary"
          @click="openNewChatModal"
        >
          <Plus :size="16" />
          {{ t('education.newChat') }}
        </button>
      </div>
    </main>

    <!-- New Conversation Modal Dialog -->
    <div
      v-if="isNewChatModalOpen"
      class="edu-messenger__modal-overlay"
      role="dialog"
      aria-modal="true"
      @click.self="closeNewChatModal"
    >
      <div class="edu-messenger__modal">
        <header class="edu-messenger__modal-header">
          <h3>{{ t('education.newChat') }}</h3>
          <button
            type="button"
            class="edu-icon-button"
            :aria-label="t('education.cancel')"
            @click="closeNewChatModal"
          >
            <X :size="18" />
          </button>
        </header>

        <form
          class="edu-messenger__modal-body"
          @submit.prevent="handleStartThread"
        >
          <div>
            <label for="contact-search">{{ t('education.contactsList') }}</label>
            <input
              id="contact-search"
              v-model="contactSearchQuery"
              type="search"
              class="edu-messenger__search-input"
              :placeholder="t('education.searchContacts')"
            />
          </div>

          <EducationState
            :loading="loadingContacts"
            :empty="
              !loadingContacts && !filteredContacts.length
                ? t('education.noContactsFound')
                : undefined
            "
          />

          <div
            v-if="filteredContacts.length > 0"
            class="edu-messenger__contact-list"
          >
            <button
              v-for="contact in filteredContacts"
              :key="contact.personId"
              type="button"
              class="edu-messenger__contact-item"
              :class="{
                'edu-messenger__contact-item--selected':
                  selectedContact?.personId === contact.personId,
              }"
              @click="selectContact(contact)"
            >
              <div
                class="edu-messenger__avatar"
                :class="avatarClass(contact.role)"
              >
                {{ getInitials(contact.name) }}
              </div>
              <div class="edu-messenger__thread-info">
                <strong>{{ contact.name }}</strong>
                <div class="edu-messenger__thread-meta">
                  <span
                    class="edu-messenger__role-badge"
                    :class="roleBadgeClass(contact.role)"
                  >
                    {{ roleLabel(contact.role) }}
                  </span>
                  <span
                    v-if="contact.contextName"
                    class="edu-messenger__context-tag"
                  >
                    {{ contact.contextName }}
                  </span>
                </div>
              </div>
            </button>
          </div>

          <div v-if="selectedContact">
            <label for="new-chat-subject">{{ t('education.subject') }}</label>
            <input
              id="new-chat-subject"
              v-model="newChatSubject"
              type="text"
              class="edu-messenger__search-input"
              maxlength="200"
              required
            />
          </div>

          <div v-if="selectedContact">
            <label for="new-chat-msg">{{ t('education.message') }}</label>
            <textarea
              id="new-chat-msg"
              v-model="newChatMessage"
              class="edu-messenger__composer-input"
              rows="3"
              maxlength="5000"
              required
              :placeholder="t('education.typeMessage')"
            ></textarea>
          </div>

          <p
            v-if="errorAction"
            class="edu-error"
            role="alert"
          >
            {{ issue(errorAction) }}
          </p>

          <div class="edu-form-actions">
            <button
              type="button"
              class="edu-button"
              @click="closeNewChatModal"
            >
              {{ t('education.cancel') }}
            </button>
            <button
              type="submit"
              class="edu-button edu-button--primary"
              :disabled="
                !selectedContact ||
                !newChatSubject.trim() ||
                !newChatMessage.trim() ||
                startingThread
              "
            >
              {{ t(startingThread ? 'education.loading' : 'education.startConversation') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
