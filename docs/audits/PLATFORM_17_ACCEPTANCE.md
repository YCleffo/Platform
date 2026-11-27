# PLATFORM_17 — Universal Workspace Finalization Acceptance Report

**VERDICT: READY**  
**Date:** 2026-11-27

---

## 1. Итоги финализации Universal Workspace

1. **Universal File Manager (`CloudDriveView.vue` + Media Foundation + SeaweedFS S3):**
   - Устранена несовместимость `CloudDriveView.vue` со старым mock/mismatched контрактом `drive.ts`; канонический файловый менеджер (`/drive`) переведён на **Media Foundation** (`mediaApi`), общий `AppFileDropzone`, `MediaPreviewDialog`, `AppContextMenu`, `useOperationsCenter()` (реальный XHR-прогресс и отмена загрузки через `POST /api/media/{id}/cancel`) и `workspaceApi.moveToTrash` / `/trash`.
   - На backend в [MediaService.php](file:///c:/Platform/apps/backend/src/Media/Application/MediaService.php), [MediaAction.php](file:///c:/Platform/apps/backend/src/Media/Api/MediaAction.php) и [media-routes.php](file:///c:/Platform/apps/backend/config/common/media-routes.php) добавлены операции `rename`, `move` (виртуальные папки в `metadata.folderPath`), `copy` (копирование S3-объекта с отдельным `storage_key`) и `?download=1` (`Content-Disposition: attachment`).
   - В [MediaAssetTrashProvider.php](file:///c:/Platform/apps/backend/src/Workspace/Infrastructure/MediaAssetTrashProvider.php) и [StorageFileTrashProvider.php](file:///c:/Platform/apps/backend/src/Workspace/Infrastructure/StorageFileTrashProvider.php) добавлена проверка ссылок `storage_key` перед возвратом ключей на физическое удаление из S3, чтобы удаление одной ссылки не уничтожало физический объект, используемый другими сущностями.
   - Реализованы все 12 сценариев файлового менеджера: просмотр файлов и папок, навигация по breadcrumbs, поиск и фильтрация по типу, сортировка (имя/размер/дата), загрузка и скачивание, переименование, перемещение и копирование, множественный выбор (bulk move / bulk trash), контекстное меню (`AppContextMenu`), предпросмотр (`MediaPreviewDialog`), удаление в корзину и восстановление через `/trash`, отображение информации о файле (MIME, размер, SHA-256, папка, статус, метаданные).
2. **Universal Dashboard & Organization Switching:**
   - Единая система провайдеров (`DashboardProviderInterface`, 8 провайдеров) и `WorkspaceDashboardView.vue` сохранена без дублирования.
   - В [useOperationsCenter.ts](file:///c:/Platform/apps/frontend/src/modules/workspace/useOperationsCenter.ts) `setOrganizationId` теперь переподключает `realtimeClient.connect(nextOrgId)` и перезагружает операции при смене организации.
3. **Universal Search & Command Palette (`Ctrl+K`):**
   - Единый `WorkspaceSearchService` с 7 провайдерами (`SearchProviderInterface`) и ACL-фильтрацией до возврата результатов, заголовков, snippet и `facets`/`total`.
   - В [GlobalSearchView.vue](file:///c:/Platform/apps/frontend/src/modules/workspace/GlobalSearchView.vue) добавлена группировка результатов по категориям с сохранением клавиатурной навигации (`ArrowUp`/`ArrowDown`/`Enter`), отмены устаревших запросов (`AbortController`) и `Ctrl+K` (`CommandPalette.vue`).
4. **Universal Trash & Undo:**
   - Единая корзина `workspace.trash_items` (`TrashView.vue`) с 4 доменными провайдерами (`storage.file`, `media.asset`, `activity.item`, `education.homework`), 30-дневным сроком хранения, `409 restore_conflict` / `renameOnConflict` и 10-секундным окном `Undo` (`UndoToastHost.vue`), недоступным для необратимых/высокорисковых операций.
5. **Universal Operations:**
   - Единый `OperationsCenterDrawer.vue` + `useOperationsCenter.ts` + `WorkspaceOperationService.php` для длительных задач, загрузок, отмены, retry и realtime-обновлений (`workspace.operation.updated`).
6. **Universal Notifications:**
   - Единый `NotificationsCenterView.vue` + `UniversalNotificationsPage.vue` + `NotificationsService.php`: в [UniversalNotificationsPage.vue](file:///c:/Platform/apps/frontend/src/modules/notifications/UniversalNotificationsPage.vue) синхронизировано переключение организации с `useOperationsCenter().organizationId`; в [NotificationsCenterView.vue](file:///c:/Platform/apps/frontend/src/modules/notifications/NotificationsCenterView.vue) добавлена межинстансная синхронизация вкладок через `BroadcastChannel('platform_notifications_sync_v1')` в дополнение к `realtimeClient` WS. Статус Web Push отображается честно (`vapid_keys_not_configured` при отсутствии VAPID-ключей).

---

## 2. Реестр канонических общеплатформенных подсистем

| Подсистема | Назначение | Канонический backend | Канонический frontend | Каноническое хранилище | Потребители | Compatibility-адаптеры | Подтверждённые дубликаты |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **Universal File Manager & Media** | Единый файловый менеджер, загрузка/стриминг/превью медиа, папки, копирование, перемещение | `App\Media\Api\MediaAction`, `App\Media\Application\MediaService`, `App\Storage\Api\StorageAction` | `CloudDriveView.vue`, `mediaApi.ts`, `FileUploader.vue`, `MediaPreviewDialog.vue` | `media.assets`, `media.variants`, `media.upload_sessions`, `storage.files`, SeaweedFS S3 | Workspace (`/drive`), Chat, Activity, Education (`HomeworkFiles.vue`) | `StudentDocumentsView.vue` (доменное представление Student Storage API поверх `storage.ts` + общих UI-компонентов), `drive.ts` (legacy client) | Нет отдельных файловых менеджеров для Messenger/Activity/Commercial |
| **Universal Dashboard** | Единый дашборд с провайдерами виджетов и быстрых действий | `App\Workspace\Application\WorkspaceDashboardService`, `DashboardProviderRegistry` (8 провайдеров) | `WorkspaceDashboardView.vue`, `workspaceApi.ts` | Чтение из доменных таблиц через провайдеры | Workspace (`/workspace`), Parent read-only summary | `ParentSectionView.vue` (использует `WorkspaceDashboardResponse`) | Не обнаружены |
| **Universal Search** | Глобальный поиск с ACL-проверкой, фасетами, группировкой и `Ctrl+K` | `App\Workspace\Application\WorkspaceSearchService`, `SearchProviderRegistry` (7 провайдеров) | `GlobalSearchView.vue`, `CommandPalette.vue`, `WorkspaceShellControls.vue` | PostgreSQL FTS / `pg_trgm` по доменным таблицам | Все оболочки (`AppLayout`, `AdminLayout`, `EducationLayout`, `ParentLayout`, `StudentLayout`) | Нет | Не обнаружены |
| **Universal Trash & Undo** | Единая корзина, soft-delete, разрешение конфликтов и обратимое окно Undo | `App\Workspace\Application\WorkspaceTrashService`, `TrashProviderRegistry` (4 провайдера), `WorkspaceOperationService` | `TrashView.vue`, `UndoToastHost.vue`, `useOperationsCenter.ts` | `workspace.trash_items`, `workspace.operations` | Workspace (`/trash`), File Manager (`/drive`), Activity, Education Homework | Нет | Не обнаружены |
| **Universal Operations** | Центр длительных операций, загрузок, отмены и retry | `App\Workspace\Application\WorkspaceOperationService`, `ProcessOperationsCommand` | `OperationsCenterDrawer.vue`, `useOperationsCenter.ts` | `workspace.operations` | Workspace, File Manager, Media `FileUploader`, `HomeworkFiles.vue` | Нет | Не обнаружены |
| **Universal Notifications** | Центр уведомлений, непрочитанные, настройки, realtime и статус Web Push | `App\Notifications\Api\NotificationsAction`, `App\Notifications\Application\NotificationsService` | `NotificationsCenterView.vue`, `UniversalNotificationsPage.vue`, `notificationsApi.ts` | `notifications.items`, `notifications.preferences`, `notifications.push_subscriptions` | Workspace (`/notifications-center`), Chat, Realtime | `education/NotificationsView.vue` (Education domain-specific feed `/notifications` & `/student/notifications`, оставлен в рамках границ Education) | Отдельных Notification Center для Commercial не обнаружено |
| **Realtime & Cross-Tab Sync** | Единый WebSocket-клиент, подписки и синхронизация вкладок | `App\Realtime\Application\RealtimeService` | `realtimeClient.ts` (`PlatformRealtimeClient`) | `realtime.events`, Redis Pub/Sub | Auth/Session, Notifications, Chat, Operations, EducationLayout | Нет | Не обнаружены |

---

## 3. Результаты локальной приёмки

| Проверка | Результат | Детали |
| --- | --- | --- |
| Backend PHPUnit (`Platform12WorkspaceTest.php`, `MediaServiceTest.php`) | **PASS (20/20, 140 assertions)** | Проверено после финальных правок PHP |
| Backend Psalm (`MediaService.php`, `MediaAction.php`, `MediaAssetTrashProvider.php`, `StorageFileTrashProvider.php`) | **PASS (0 errors)** | Статический анализ без ошибок |
| Frontend `npm run build` (`vue-tsc --noEmit` + `tsc --noEmit` + `vite build`) | **PASS (824 modules)** | Сборка и проверка типов без ошибок |
| Frontend ESLint (`modules/storage`, `modules/workspace`, `modules/notifications`, `mediaApi.ts`) | **PASS (0 errors, 0 warnings)** | После финальных правок |
| Frontend Vitest (`storage`, `workspace`, `mediaApi`, `workspaceApi`, `StudentDocumentsView`) | **PASS (7 files, 23/23 tests)** | Включая `CloudDriveView.spec.ts` (3/3) |
| Live API Verification (`http://127.0.0.1:8083` + SeaweedFS S3 + Postgres) | **PASS (`P17_LIVE_API_OK`)** | `reserve` → `upload` → `confirm` → `rename` → `move` → `copy` → `trash` → `purge` (copy) → `playback & download=1` (original 200 OK) → `/api/notifications` |
| Live Playwright E2E (`e2e-platform12-workspace.mjs`, SC-01..SC-11) | **PASS (11/11 Workspace scenarios)** | Dashboard, Org Switch, `Ctrl+K`, dangerous confirmation, Search RU/EN & ACL, Upload Cancel + S3 purge, Trash 409/Purge, Undo Toast & 409 expired. *(SC-12 Activity runner selector из P12 изменился в P13/14 и не относится к границам PLATFORM_17).* |
