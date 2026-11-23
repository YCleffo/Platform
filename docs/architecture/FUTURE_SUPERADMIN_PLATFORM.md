# Platform Superadmin — Спецификация платформы суперадминистратора

## 1. Контекст и бизнес-цель
Для масштабного управления тенантами, корпоративными клиентами, школами и глобальными учётными записями требуется панель платформенного суперадминистратора.
Бэкенд ядра уже содержит полнофункциональные контроллеры управления организациями (`OrganizationAction`) и жизненным циклом учётных записей (`AccountLifecycleAction`, `ListAccountsAction`, `GetAccountAction`, `CreateAccountAction`, `InviteAccountAction`).

Настоящая спецификация задаёт архитектуру панели управления организациями и модерации аккаунтов.

---

## 2. Модель данных и сущности

### 2.1. Organization (Организация Core)
```typescript
export interface CoreOrganization {
  id: string;               // UUID организации
  name: string;             // Официальное наименование
  code: string;             // Уникальный слаг / субдомен
  status: 'active' | 'suspended' | 'archived';
  memberCount: number;      // Количество участников
  createdAt: string;
  updatedAt: string;
}
```

### 2.2. Organization Member (Участник организации)
```typescript
export interface OrganizationMember {
  id: string;
  organizationId: string;
  personId: string;
  name: string;
  email: string | null;
  role: 'owner' | 'admin' | 'member';
  joinedAt: string;
}
```

### 2.3. Account Moderation Summary (Учётная запись Core)
```typescript
export interface CoreAccountModeration {
  id: string;
  email: string;
  displayName: string | null;
  status: 'pending_approval' | 'active' | 'disabled' | 'archived';
  isMfaEnrolled: boolean;
  lastLoginAt: string | null;
  createdAt: string;
  roles: string[];
}
```

---

## 3. Бэкенд эндпоинты (`apps/backend`)

Реализованы в `App\Core\Organization\Api` и `App\Core\Account\Api`:

1. **Реестр организаций:**
   - `GET /api/core/organizations` — список организаций с пагинацией и поиском.
   - `POST /api/core/organizations` — создание новой организации платформы.
   - `GET /api/core/organizations/{id}/members` — состав участников.
   - `POST /api/core/organizations/{id}/members` — добавление участника.
   - `POST /api/core/organizations/{id}/members/{personId}/leave` — исключение участника.
2. **Модерация пользователей платформы:**
   - `GET /api/core/accounts` — глобальный список аккаунтов с фильтрацией по статусу.
   - `GET /api/core/accounts/{id}` — подробный профиль, назначенные роли, журнал сессий.
   - `POST /api/core/accounts/{id}/approve` — одобрение регистрации.
   - `POST /api/core/accounts/{id}/disable` — блокировка аккаунта с немедленным сбросом сессий.
   - `POST /api/core/accounts/{id}/enable` — разблокировка учётной записи.
   - `POST /api/core/accounts/{id}/archive` — мягкое удаление / архивация.
   - `POST /api/core/accounts/{id}/invite` — отправка инвайта новому пользователю.
3. **Глобальный журнал аудита:**
   - `GET /api/core/audit-events` — реестр системных событий (входы, смены паролей, изменения прав, блокировки).

---

## 4. Архитектура Frontend (Vue 3 / TypeScript)

### 4.1. Размещение в навигации
- Модуль: `apps/frontend/src/modules/admin/superadmin/`
- Маршруты:
  - `/admin/organizations` (`admin-organizations`) — реестр организаций.
  - `/admin/accounts` (`admin-accounts`) — модерация учётных записей.
  - `/admin/audit` (`admin-audit`) — системный журнал аудита.
- Ограничение доступа: роутер защищён guard-ом проверки системной привилегии `core.organizations.manage` и `core.accounts.moderate`.

### 4.2. Компоненты UI
1. `PlatformOrganizationsView.vue`:
   - Полноэкранная таблица с фильтрами по статусу (Active, Suspended, Archived).
   - Быстрый переход в участников организации.
   - Модалка создания организации с указанием владельца.
2. `PlatformAccountsView.vue`:
   - Вкладки: «Ожидают одобрения», «Все пользователи», «Заблокированные».
   - Бейджи статусов, индикаторы 2FA/Passkey, даты активности.
   - Контекстные действия: Аппрув, Блокировка (с вводом причины), Архивация.
3. `AccountDetailDrawer.vue`:
   - Профиль пользователя, связанные организации, активные сессии с IP и браузерами.
   - Кнопка принудительного отзыва всех активных сессий пользователя.
