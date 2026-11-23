# Developer Portal & Service Credentials — Спецификация и фундамент личного кабинета разработчика

## 1. Контекст и цели
Для автоматизации, интеграции с внешними CRM/LMS, CI/CD контурами и локальными скриптами платформе требуется безопасный механизм выпуска и ротации API-ключей (Service Credentials / Personal Access Tokens).
В ядре `apps/backend` уже реализована серверная логика управления сервисными ключами аккаунта (`ServiceCredentialAction`).

Данный документ фиксирует архитектуру кабинета разработчика, протокол выпуска, ротации и отзыва API-ключей.

---

## 2. Модель данных и сущности

### 2.1. Service Credential (Сервисный ключ)
```typescript
export interface ServiceCredential {
  id: string;              // UUID ключа
  name: string;            // Понятное разработчику имя ("GitHub Actions CI", "1C Sync Bot")
  description: string | null;
  scopes: string[];        // Разрешённые скоупы ("education:read", "storage:write", etc.)
  lastUsedAt: string | null;
  expiresAt: string | null;// Срок действия (30/90/365 дней или бессрочно)
  createdAt: string;
  isExpired: boolean;
  isRevoked: boolean;
  prefix: string;          // Первые 8 символов для отображения (e.g. "plat_live_4a8b...")
}
```

### 2.2. Secret Issue Result (Результат выпуска)
Секретный токен показывается разработчику **ровно один раз** в момент выпуска:
```typescript
export interface ServiceCredentialCreated {
  credential: ServiceCredential;
  token: string;           // Полный секрет e.g. "plat_live_4a8b7c6d5e4f3a2b1c0d9e8f..."
}
```

### 2.3. Rotation Result (Результат ротации)
Ротация выпускает новый секрет с сохранением grace period для старого (например, 24 часа):
```typescript
export interface ServiceCredentialRotated {
  credentialId: string;
  newToken: string;
  previousTokenExpiresAt: string; // Окончание grace-периода старого токена
}
```

---

## 3. Бэкенд эндпоинты (`apps/backend`)

Эндпоинты реализованы в `apps/backend/src/Core/Auth/Api/ServiceCredentialAction.php`:

1. **Список ключей аккаунта:**
   - `GET /api/core/accounts/{accountId}/service-credentials`
   - Возвращает метаданные всех выпущенных ключей (без раскрытия секретов).
2. **Выпуск нового ключа:**
   - `POST /api/core/accounts/{accountId}/service-credentials`
   - Тело: `{ "name": "string", "scopes": ["..."], "expiresInDays": 90 }`
   - Возвращает `{ "id": "...", "token": "...", "expiresAt": "..." }`.
3. **Ротация существующего ключа:**
   - `POST /api/core/accounts/{accountId}/service-credentials/{credentialId}/rotate`
   - Выпускает новый секретный токен, старый деактивируется по таймеру grace-периода.
4. **Немедленный отзыв ключа:**
   - `POST /api/core/accounts/{accountId}/service-credentials/{credentialId}/revoke`
   - Немедленная инвалидация, ключ больше не принимается API шлюзом.

---

## 4. Архитектура Frontend (Vue 3 / TypeScript)

### 4.1. Размещение в интерфейсе
- Маршрут: `/settings/developer` (`settings-developer`)
- Расположение: Вкладка в меню «Настройки» (`SettingsLayout.vue`), рядом с «Безопасность», «Приватность» и «Внешний вид».

### 4.2. Компоненты UI
1. `DeveloperCredentialsView.vue`:
   - Таблица активных сервисных токенов.
   - Метаданные: Имя, префикс, разрешённые скоупы (чипсы), дата последнего использования, статус (Active / Expiring / Revoked).
   - Кнопка «Выпустить новый токен» (`+ Generate Token`).
2. `TokenCreationModal.vue`:
   - Шаг 1: Форма ввода имени, выбора срока действия и чекбоксов прав/скоупов.
   - Шаг 2: Модальное окно безопасного раскрытия токена:
     - Одноразовый просмотр с кнопкой «Скопировать в буфер обмена».
     - Предупреждение: *«Сохраните этот ключ прямо сейчас. Вы больше никогда не сможете увидеть его снова»*.
3. `TokenRotationDialog.vue`:
   - Подтверждение ротации с выбором grace-периода для бесшовного переключения продакшн-систем.
4. `TokenRevocationConfirmDialog.vue`:
   - Опасное действие с предупреждением о прерывании интеграций.

---

## 5. Требования безопасности
- Токены хешируются алгоритмом SHA-256 с солью перед сохранением в PostgreSQL.
- Полный токен недоступен для чтения даже администраторам базы данных.
- Поддерживается ограничение по IP-подсетям (опционально в теле запроса).
