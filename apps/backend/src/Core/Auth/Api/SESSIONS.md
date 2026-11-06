# API собственных сессий Core

Маршруты зарегистрированы в `apps/backend/config/common/routes.php`:

- `GET /api/auth/sessions`;
- `DELETE /api/auth/sessions/{id}`;
- `POST /api/auth/sessions/revoke-others`.

Маршруты требуют доверенную `AuthenticatedIdentity`, предоставляющую идентификаторы
аккаунта и текущей сессии. Обработчик вызывает существующие сценарии собственных сессий;
отзыв ограничен владельцем аккаунта. Интерфейс: `/admin/security/sessions`.
Ни один из этих идентификаторов нельзя принимать из параметров строки запроса или JSON как
указание на то, чьи это «мои сессии».

Подробности: [граница аутентификации](../../../../../../docs/architecture/AUTHENTICATION_AUTHORIZATION.md),
[безопасность интерфейса](../../../../../../docs/frontend/README.md),
[условия выпуска](../../../../../../docs/operations/READINESS.md).
