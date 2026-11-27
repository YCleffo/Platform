# PLATFORM_15_B — Universal Messenger Finalization Acceptance Report

**VERDICT: READY**

## 1. Канонический Chat Engine

- **Backend Engine:** [ChatService.php](file:///c:/Platform/apps/backend/src/Chat/Application/ChatService.php), [ChatAction.php](file:///c:/Platform/apps/backend/src/Chat/Api/ChatAction.php), [chat-routes.php](file:///c:/Platform/apps/backend/config/common/chat-routes.php).
- **Каноническое хранилище:** схема `chat.*` (`chat.rooms`, `chat.members`, `chat.messages`, `chat.message_edits`, `chat.message_deletions`, `chat.reactions`, `chat.delivery_receipts`) — [001_chat_foundation.sql](file:///c:/Platform/apps/backend/migrations/chat/001_chat_foundation.sql), [002_chat_consolidation.sql](file:///c:/Platform/apps/backend/migrations/chat/002_chat_consolidation.sql).
- **Доменные политики доступа:** `ChatContextPolicyInterface` реализован через [EducationChatContextPolicy.php](file:///c:/Platform/apps/backend/src/Education/Infrastructure/EducationChatContextPolicy.php) без хардкода образовательных ролей внутри `ChatService`.
- **Новые возможности Engine:**
  - `POST /api/chat/rooms/{id}/members` (`ChatService::addMember`) — добавление участников с проверкой прав модератора и `ChatContextPolicyInterface::canCreateRoomWith`.
  - `aroundMessageId` в `GET /api/chat/rooms/{id}/messages` (`ChatService::listMessages`) — загрузка контекста вокруг конкретного сообщения для перехода из поиска, закреплённых сообщений и цитат.
- **Realtime & Notifications:**
  - Единый [RealtimeService.php](file:///c:/Platform/apps/backend/src/Realtime/Application/RealtimeService.php) и [WebSocketServer.php](file:///c:/Platform/apps/backend/src/Realtime/Infrastructure/WebSocketServer.php) + клиент [realtimeClient.ts](file:///c:/Platform/apps/frontend/src/shared/realtime/realtimeClient.ts) (дедупликация по `eventId`, `sinceSeq` replay при reconnect, `BroadcastChannel` для синхронизации вкладок, отписка при `chat.member.removed`).
  - Единый [NotificationsService.php](file:///c:/Platform/apps/backend/src/Notifications/Application/NotificationsService.php) (`notifications.items`), без отдельной push-системы для Chat.

## 2. Единый Frontend Messenger

- **Компонент:** [UniversalMessenger.vue](file:///c:/Platform/apps/frontend/src/modules/chat/UniversalMessenger.vue) + [chatApi.ts](file:///c:/Platform/apps/frontend/src/modules/chat/chatApi.ts) + [chatI18n.ts](file:///c:/Platform/apps/frontend/src/modules/chat/chatI18n.ts) + [chat.css](file:///c:/Platform/apps/frontend/src/modules/chat/chat.css).
- **Подключён во всех кабинетах:**
  - Universal Chat: [UniversalChatPage.vue](file:///c:/Platform/apps/frontend/src/modules/chat/UniversalChatPage.vue)
  - Преподаватель: [MessagesView.vue](file:///c:/Platform/apps/frontend/src/modules/education/MessagesView.vue), [ThreadView.vue](file:///c:/Platform/apps/frontend/src/modules/education/ThreadView.vue)
  - Ученик: [StudentMessagesView.vue](file:///c:/Platform/apps/frontend/src/modules/education/student/StudentMessagesView.vue)
  - Родитель: [ParentSectionView.vue](file:///c:/Platform/apps/frontend/src/modules/education/parent/ParentSectionView.vue) (с поддержкой `context-type="student"` и `context-id`)
- **Удалено:** `EducationMessenger.vue`, `messenger.css`, `EducationMessenger.spec.ts`.
- **Media Foundation (из PLATFORM_15_A):** используются `MediaPlayer`, `VoiceMessagePlayer`, `AudioPlayer`, `AudioRecorder`, `VideoPlayer`, `VideoNotePlayer`, `VideoNoteRecorder`, `FileAttachment`, `AppFileDropzone` без дубликатов.
- **Реализовано:** список диалогов, личные/групповые/организационные/контекстные чаты, пагинация истории (`beforeSeq` с сохранением позиции скролла), переход к сообщению (`jumpToMessage`), редактирование и удаление (`for_me` / `for_all`), вложения, голосовые и видеосообщения, просмотр изображений, индикатор набора текста, непрочитанные, поиск, управление участниками (добавление/удаление/выход), контекстные меню (`@contextmenu` + кнопка `⋯`), RU/EN паритет, адаптив и темы.

## 3. Состояние `EducationChatBridge` и legacy-хранилищ

- **Зеркалирование отключено:** из [EducationChatBridge.php](file:///c:/Platform/apps/backend/src/Education/Infrastructure/EducationChatBridge.php) удалены методы `mirrorRoom`, `mirrorMessage`, `mirrorReaction`, `mirrorRead`, `flushRealtime`. В классе оставлены только `syncRoomMembership` и `revokeMemberAccess` для синхронизации членства в `chat.members` при изменении связей в Education (например, отзыв опекуна в [ParentFamily.php](file:///c:/Platform/apps/backend/src/Education/Application/ParentFamily.php)).
- **Независимая запись в старое хранилище отключена:** [PostgresEducationStore.php](file:///c:/Platform/apps/backend/src/Education/Infrastructure/PostgresEducationStore.php) и [ParentReadService.php](file:///c:/Platform/apps/backend/src/Education/Application/ParentReadService.php) в методах `startThread`, `sendMessage`, `toggleReaction`, `readThread` больше не пишут в `education.threads`, `education.thread_members`, `education.messages` и чат-записи `education.notifications`; все операции идут напрямую в `chat.*`, `notifications.items` и `RealtimeService`.
- **Исправлена изоляция медиа-доступа:** из [EducationMediaAccessAuthorizer.php](file:///c:/Platform/apps/backend/src/Education/Infrastructure/EducationMediaAccessAuthorizer.php) удалена проверка `education.messages`/`education.thread_members`, чтобы доступ к медиа в чатах контролировался исключительно через [ChatMediaAccessAuthorizer.php](file:///c:/Platform/apps/backend/src/Chat/Infrastructure/ChatMediaAccessAuthorizer.php) (`chat.members.left_at IS NULL`).
- **Legacy-таблицы:** `education.threads`, `education.thread_members`, `education.messages`, `education.message_reactions` сохранены в схеме (физически не удалены, так как используются для GDPR-экспорта/удаления в `WorkspaceErasureHandler`, `PersonalDataContributor`, `RetentionCandidateContributor` и проверки идемпотентности миграции `002_chat_consolidation.sql`).

## 4. Оставшиеся дубликаты и ограничения

- Legacy HTTP-маршруты `/api/education/threads*` и `/api/education/parent/threads*` сохранены как адаптеры совместимости и работают напрямую поверх `chat.*`.
- Полное физическое удаление таблиц `education.threads` / `education.messages` отложено до отдельной очистки GDPR-контрибьюторов.

## 5. Результаты проверок

| Проверка | Результат | Детали |
| --- | --- | --- |
| Backend PHPUnit `Platform12ChatRealtimeTest` | **PASS (5/5, 71 assertions)** | ACL Education в Chat, `removeMember`, `syncRoomMembership`, `revokeMemberAccess`, идемпотентный backfill `002_chat_consolidation.sql`, outbox & `sinceSeq` |
| Backend PHPUnit `ParentAccessTest` | **BLOCKED (env guard)** | Требует изолированный test runtime (`DB_NAME=platform_test`, `REDIS_HOST=redis-test` по `TestRuntimeGuard`) |
| Frontend `typecheck`, `lint`, `check:i18n`, `build` | **PASS (0 errors)** | 100% RU/EN паритет, Vite build 824 modules |
| Frontend Vitest (`UniversalMessenger.spec.ts`, `realtimeClient.spec.ts`, `MediaPlayer.spec.ts`) | **PASS (14/14)** | Загрузка комнат, пагинация, управление участниками, realtime-клиент, плееры Media Foundation |
| Playwright E2E (`e2e-platform15-b-messenger.mjs`) | **PASS (`PLATFORM_15_B_E2E_PASS`)** | 2 браузерных контекста (`/chat/:id` и `/student/messages/:id`), групповой чат, live-сообщение, идемпотентный retry (`clientKey`), документ + voice-сообщение через Media Foundation, редактирование, удаление `for_all`, ACL `403` на `addMember` для несвязанного ученика, добавление/исключение участника с немедленным `404` и очисткой после reconnect, `0` записей в `education.messages` |
