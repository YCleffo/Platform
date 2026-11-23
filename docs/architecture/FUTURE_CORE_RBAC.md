# Core RBAC — Спецификация и архитектурный фундамент управления ролями и привилегиями

## 1. Контекст и бизнес-цель
Платформа Platform использует строгую модель разграничения прав доступа на основе привилегий (Permissions) и ролей (Roles).
На уровне ядра (`apps/backend`) реализован полный стек контроля доступа:
- Кастомные и системные роли.
- Матрица прав доступа с атомарными permissions.
- Привязка ролей к аккаунтам пользователей Core.

Данный документ описывает архитектурный фундамент, модель данных, OpenAPI-контракт и архитектуру веб-интерфейса для внедрения пользовательского экрана управления ролями в админ-панели.

---

## 2. Модель данных и сущности

### 2.1. Permission (Привилегия)
Системная константа, определяющая атомарное действие.
```typescript
export interface CorePermission {
  code: string;        // e.g. "core.rbac.manage", "core.settings.read", "core.privacy.manage"
  name: string;        // Человекочитаемое название
  description: string; // Детальное описание влияния права
  category: string;    // Категория: "rbac" | "settings" | "privacy" | "education" | "storage"
}
```

### 2.2. Role (Роль)
Именованная группа привилегий.
```typescript
export interface CoreRole {
  id: string;               // UUID роли
  code: string;             // Машинный код (slug), e.g. "curriculum_lead", "auditor"
  name: string;             // Локализованное название, e.g. "Руководитель учебных программ"
  description: string | null;
  isSystem: boolean;        // true для системных преднастроенных ролей (root, student, teacher)
  permissionsCount?: number;// Количество активных прав
  createdAt: string;
  updatedAt: string;
}
```

### 2.3. Role Permissions Matrix (Матрица прав)
Связка «Роль ↔ Привилегии»:
```typescript
export interface RolePermissionsPayload {
  permissions: string[];    // Массив кодов permissions, назначенных роли
}
```

### 2.4. Account Role Assignment (Назначение роли аккаунту)
```typescript
export interface AccountRoleAssignment {
  accountId: string;
  roleId: string;
  roleCode: string;
  roleName: string;
  assignedAt: string;
}
```

---

## 3. Бэкенд эндпоинты (Apps/Backend Yii3)

Все эндпоинты уже реализованы в `apps/backend/src/Core/Rbac/Api`:

1. **Список ролей:**
   - `GET /api/core/roles`
   - Обработчик: `ListRolesAction`
   - Права: `RequirePermission('core.rbac.read')`
2. **Создание роли:**
   - `POST /api/core/roles`
   - Обработчик: `CustomRoleAction`
   - Права: `RequirePermission('core.rbac.manage')`
   - Тело: `{ "code": "string", "name": "string", "description": "string|null" }`
3. **Обновление роли:**
   - `PATCH /api/core/roles/{id}`
   - Обработчик: `CustomRoleAction`
   - Права: `RequirePermission('core.rbac.manage')`
   - Защита: Системные роли (`isSystem: true`) запрещены к переименованию кода.
4. **Удаление роли:**
   - `DELETE /api/core/roles/{id}`
   - Обработчик: `CustomRoleAction`
   - Защита: Системные роли удалять нельзя; если к роли привязаны аккаунты, возвращается `409 Conflict`.
5. **Чтение прав роли:**
   - `GET /api/core/roles/{id}/permissions`
   - Обработчик: `RolePermissionsAction`
6. **Обновление прав роли:**
   - `PUT /api/core/roles/{id}/permissions`
   - Обработчик: `RolePermissionsAction`
   - Тело: `{ "permissions": ["core.privacy.read", "core.settings.manage"] }`
7. **Глобальный реестр привилегий:**
   - `GET /api/core/permissions`
   - Обработчик: `ListPermissionsAction`
8. **Назначение роли аккаунту:**
   - `GET /api/core/accounts/{id}/roles`
   - `POST /api/core/accounts/{id}/roles/{roleId}`
   - `DELETE /api/core/accounts/{id}/roles/{roleId}`
   - Обработчик: `AccountRolesAction`

---

## 4. Архитектура Frontend (Vue 3 / TypeScript)

### 4.1. Размещение в навигации
- Модуль: `apps/frontend/src/modules/admin/rbac/`
- Маршрут: `/admin/roles` (`admin-roles`)
- Доступность: Доступно в меню «Настройки платформы» для пользователей с привилегией `core.rbac.read` / `core.rbac.manage`.

### 4.2. Компоненты UI
1. `AdminRolesView.vue` — главная таблица ролей:
   - Список ролей с бейджами «Системная» / «Пользовательская».
   - Кнопка «Создать роль».
   - Поиск и фильтрация.
   - Действия над строкой: редактировать, настроить права, удалить (только для кастомных).
2. `RoleEditorDialog.vue` — модальное окно создания и редактирования роли:
   - Валидация кода роли (`code`: lowercase, kebab-case, 3-64 символа).
   - Название и описание.
3. `RolePermissionsDrawer.vue` — выдвижная панель (шторка) матрицы прав:
   - Группировка прав по категориям (Ядро, Приватность, Обучение, Файлы).
   - Чекбоксы с мгновенным поиском по правам.
   - Защита от случайной потери прав суперпользователя.
4. `AccountRolesPicker.vue` — компонент для назначения ролей в карточке пользователя.

---

## 5. Безопасность и аудит
- Любые изменения прав логируются через `AuditService` с фиксацией `actor_id`, `role_id`, `before_permissions`, `after_permissions`.
- Инвалидация токенов сессий пользователей при отзыве прав запускается автоматически через смену `session_epoch`.
