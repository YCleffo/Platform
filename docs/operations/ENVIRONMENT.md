# Конфигурация и секреты

Корневой `.env` хранит локальную конфигурацию и исключён из Git и проектного архива. `scripts/init-env.ps1` сохраняет заполненные значения, генерирует отсутствующие секреты криптографическим генератором и ограничивает доступ к файлу текущим пользователем Windows и SYSTEM.

| Параметры | Назначение |
| --- | --- |
| `POSTGRES_*`, `CONTROL_POSTGRES_*` | Две независимые базы; значения PASSWORD — пароли существующих ролей PostgreSQL |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Явная конфигурация подключения Core вне Compose |
| `CONTROL_APP_SECRET`, `CONTROL_TOKEN_SECRET` | Независимые секреты Symfony и токенов Commercial |
| `AUTH_IDENTIFIER_HMAC_KEY`, `DEVICE_FINGERPRINT_KEY` | Хеширование идентификаторов и отпечатков Core |
| `AUTH_TOTP_ENCRYPTION_KEY`, `CONTROL_TOTP_ENCRYPTION_KEY` | Отдельные ключи XChaCha20-Poly1305, base64 от 32 случайных байтов |
| OAuth, SMTP, YooKassa | Реальные значения выдаёт соответствующий провайдер; генератор их не подменяет |

Compose передаёт Core адрес `postgres:5432`, Commercial — `control-postgres:5432`. Это имена сервисов внутри разных сетей. Приложение Core требует свои `DB_*` и не подхватывает `POSTGRES_*` при их отсутствии. Явный `DB_PASSWORD_FILE` используется только в выделенном provisioner-контракте с закреплённым путём секрета.

Локальная почта поступает в Mailpit: SMTP `mailpit:1025`, просмотр писем — `http://localhost:8025`. `LOCAL_MAIL_ENABLED=true` и `LOCAL_MAIL_COUNTRY=RU` регистрируют этот приёмник только в конфигурации Core `dev`; другой SMTP-хост, порт или TLS с этим флагом отклоняются. Commercial использует локальную запись `smtp` из `CONTROL_PRIVACY_PROVIDER_DECISIONS` в `.env.example`. Пароли SMTP для Mailpit не нужны. При переходе на внешний SMTP выключите локальный флаг и задайте конфигурацию реального провайдера; production-конфигурация Core локальный профиль не загружает.

## Изменение

```powershell
./scripts/init-env.ps1
docker compose --profile control config --quiet
```

Генератор не меняет существующие пароли или ключи MFA. Смена ключа MFA без миграции ciphertext лишит пользователей возможности проверить сохранённые факторы.

Для согласованной смены пароля PostgreSQL:

```powershell
./scripts/change-platform-db-password.ps1
./scripts/change-control-db-password.ps1
```

Скрипты обновляют роль, проверяют TCP-аутентификацию, записывают `.env` и пересоздают зависимые постоянные сервисы. Значения секретов не выводятся. При ошибке до записи `.env` восстанавливается прежний пароль роли.

CI генерирует собственные значения на каждое задание, маскирует их в логе и не использует локальный `.env`. Фиксированные фиктивные ключи в изолированных unit fixtures не являются настройками рабочего приложения.

## Ручные команды

| Скрипт | Когда использовать |
| --- | --- |
| `scripts/first-start.ps1` | Первичная сборка и запуск постоянных сервисов, установка зависимостей и миграции |
| `scripts/init-env.ps1` | Дополнить `.env` недостающими параметрами и секретами, сохранив существующие значения |
| `scripts/db-status.ps1` | Прочитать состояние сервисов и сверить обе живые схемы, историю миграций и DBML |
| `scripts/database/migrate.py` | Применить миграции для аргумента `core`, `commercial` или `all` через постоянные контейнеры |
| `scripts/change-platform-db-password.ps1` | Согласованно сменить пароль Core и обновить зависимые сервисы |
| `scripts/change-control-db-password.ps1` | Согласованно сменить пароль Commercial и обновить зависимые сервисы |
| `scripts/restore-db.ps1` | Явно восстановить указанную БД из конкретного custom-format dump |
| `scripts/create-project-zip.ps1` | Собрать Git-visible изменения в ZIP; при чистом worktree собрать весь Git-visible проект |

`common.ps1` и `rotate-db-password.ps1` — общие реализации этих команд, их не запускают самостоятельно. `scripts/ci` содержит только проверки и инфраструктуру, которые реально вызывает workflow. Регрессия ZIP перенесена туда и проверяется отдельным Windows job.

Экспорт схем и генераторы в `scripts/documentation` поддерживают DBML, справочник, покрытие, ссылки и SVG. Их команды приведены в [описании БД](../database/README.md) и [инструментах документации](../../scripts/documentation/README.md). Они не запускают дополнительные контейнеры.
