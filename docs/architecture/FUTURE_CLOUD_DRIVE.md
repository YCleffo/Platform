# Cloud Drive & Storage — Спецификация файлового хранилища на базе `/api/storage`

## 1. Контекст и бизнес-цель
В проекте Platform уже реализован промышленный бэкенд объектного хранилища и синхронизации файлов (`App\Storage\Api\StorageAction`).
Он включает:
- Расчёт дискового пространства и квот.
- Версионирование файлов (хранение предыдущих ревизий).
- Восстановление удалённых файлов и откат на предыдущие версии.
- Разрешение конфликтов параллельной правки файлов (Conflict Resolution).
- S3 direct-upload через pre-signed transfer тикеты с SHA-256 верификацией.
- Агентскую синхронизацию рабочих станций через `/api/device-agent/storage/*`.

Настоящий документ описывает архитектуру пользовательского веб-интерфейса «Облачный диск» (Cloud Drive).

---

## 2. Модель данных и сущности

### 2.1. File & Directory Item (Элемент хранилища)
```typescript
export interface StorageItem {
  id: string;               // UUID файла
  path: string;             // Относительный путь, e.g. "/Documents/Projects/Spec.pdf"
  name: string;             // "Spec.pdf"
  isDirectory: boolean;
  mime: string;             // "application/pdf"
  sizeBytes: number;
  sha256: string;
  version: number;          // Текущая версия (1, 2, 3...)
  updatedAt: string;
  createdAt: string;
  isDeleted: boolean;
}
```

### 2.2. File Version (История версий)
```typescript
export interface StorageFileVersion {
  versionId: string;
  fileId: string;
  versionNumber: number;
  sizeBytes: number;
  sha256: string;
  createdAt: string;
  authorPersonId: string | null;
  authorName: string | null;
}
```

### 2.3. Storage Conflict (Конфликт синхронизации)
Возникает при параллельной загрузке файла локальным агентом и браузером:
```typescript
export interface StorageConflict {
  id: string;
  fileId: string;
  path: string;
  baseSha256: string;
  localSha256: string;
  remoteSha256: string;
  detectedAt: string;
  status: 'pending' | 'resolved';
}
```

### 2.4. Storage Space & Quota (Квота пространства)
```typescript
export interface StorageSpaceQuota {
  usedBytes: number;
  totalLimitBytes: number;
  fileCount: number;
  usagePercent: number;
}
```

---

## 3. Бэкенд эндпоинты (`apps/backend`)

Реализованы в `App\Storage\Api\StorageAction.php`:

1. **Квоты и статистика:**
   - `GET /api/storage/space` — текущее использование, лимиты, процент заполнения.
2. **Файловые операции:**
   - `GET /api/storage/files` — список файлов с фильтрацией по директории.
   - `PUT /api/storage/files` — создание/загрузка файла.
   - `GET /api/storage/file?id={id}` — получение прямой ссылки на скачивание.
   - `POST /api/storage/files/rename` — переименование/перемещение (`id`, `newPath`).
   - `DELETE /api/storage/files` — удаление файла (`id`).
3. **Версионирование и откат:**
   - `GET /api/storage/files/versions?fileId={id}` — список всех версий файла.
   - `POST /api/storage/files/restore` — откат файла к версии (`fileId`, `versionId`).
4. **Конфликты синхронизации:**
   - `GET /api/storage/conflicts` — список активных конфликтов.
   - `POST /api/storage/conflicts/{id}/resolve` — выбор стратегии (`keep_local`, `keep_remote`, `keep_both`).
5. **Пре-подписанные трансферы (S3 Signed):**
   - `POST /api/storage/transfers` — инициализация загрузки.
   - `GET /api/storage/transfers/{id}` — обновление ссылки.
   - `POST /api/storage/transfers/{id}/complete` — подтверждение успешной заливки.
   - `POST /api/storage/transfers/{id}/abort` — отмена загрузки.

---

## 4. Архитектура Frontend (Vue 3 / TypeScript)

### 4.1. Размещение в навигации
- Модуль: `apps/frontend/src/modules/storage/`
- Маршрут: `/drive` (`cloud-drive`)
- Доступность: Доступен авторизованным пользователям в основном приложении.

### 4.2. Компоненты UI
1. `CloudDriveView.vue`:
   - Дерево папок и хлебные крошки (`Breadcrumbs`).
   - Панель инструментов: «Загрузить файл», «Создать папку», «Обновить».
   - Переключение вида: Таблица / Сетка иконок.
   - Индикатор квоты диска в боковой панели (e.g. «Использовано 4.2 ГБ из 15 ГБ»).
2. `FilePreviewDrawer.vue`:
   - Быстрый просмотр изображений, PDF и текстовых файлов.
   - Метаданные (размер, SHA-256, дата модификации).
   - Кнопка вызова истории версий.
3. `FileVersionsDrawer.vue`:
   - Временная шкала версий с автором изменения.
   - Кнопка «Восстановить эту версию».
4. `ConflictResolutionModal.vue`:
   - Сравнение конфликтующих версий с предпросмотром размера и времени.
   - Опции: «Оставить мою версию», «Принять версию с сервера», «Сохранить обе».
5. `UploadProgressBanner.vue`:
   - Плавающий прогресс загрузки чанков и вычисления SHA-256 хеша.
