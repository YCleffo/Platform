# Роли PostgreSQL и минимальные привилегии

Локальный Compose использует bootstrap-пользователей PostgreSQL для удобства разработки. Это не
production-модель: web/worker процессы не должны подключаться суперпользователем и не должны иметь
возможность выполнять DDL.

## Разделение ролей

| Роль | Назначение | Права |
| --- | --- | --- |
| owner | Владение базой и управление ролями | `CREATEDB`, управление membership; не используется приложением |
| migrator | Применение миграций | `CREATE`/`USAGE` на схемах, владение объектами, `ALTER DEFAULT PRIVILEGES` |
| runtime | HTTP, workers, scheduler | `CONNECT`, `USAGE` на схемах, `SELECT/INSERT/UPDATE/DELETE`, `USAGE/SELECT` на sequences, `EXECUTE` на прикладных functions |
| backup | Резервное копирование | `CONNECT`, чтение; отдельный credential |

Runtime не получает `CREATE`, `TRUNCATE`, `REFERENCES`, `REPLICATION`, `BYPASSRLS`, членство в
owner/migrator, доступ к `pg_read_file`, изменение `doctrine_migration_versions` и таблиц истории
миграций. Скрипт явно отзывает все членства runtime-роли и требует
`RUNTIME_ROLE != MIGRATION_ROLE`.

## Новый Commercial database

1. Создать базу и роли owner/migrator/runtime средствами администратора.
2. Выдать migrator членство во владельце схемы и выполнить миграции.
3. Проверить и при необходимости отозвать `SECURITY DEFINER` у функций, созданных не migrator.
4. Запустить `scripts/operations/provision-db-role.sh` для схем `commercial` и `public`.
   `public` содержит `doctrine_migration_versions`; runtime получает на неё только `USAGE`
   и `SELECT`, без DML и DDL.
5. Подключить приложение как runtime, мигратор — только для шага миграции.

Пример:

```bash
PGHOST=db PGPORT=5432 PGADMIN_USER=platform_owner PGDATABASE=platform_commercial \
SCHEMAS=commercial,public RUNTIME_ROLE=platform_commercial_runtime \
RUNTIME_PASSWORD='...' MIGRATION_ROLE=platform_commercial_migrator \
bash ./scripts/operations/provision-db-role.sh
```

Для Core используются схемы `core,platform_runtime,public` и отдельная пара ролей. Пароли и
membership не хранятся в репозитории; `RUNTIME_PASSWORD` передаётся из secret store. Скрипт
читает пароль внутри `psql` через `\getenv`, а не через argv; production DDL-логи не должны
включать текст `CREATE/ALTER ROLE ... PASSWORD`.

## Проверка отказа

На изолированной копии выполнить от runtime:

```sql
CREATE TABLE commercial.should_fail (id int); -- ERROR: permission denied for schema commercial
TRUNCATE commercial.commercial_accounts;       -- ERROR: permission denied
ALTER TABLE commercial.commercial_accounts ADD COLUMN x int; -- ERROR
DELETE FROM public.doctrine_migration_versions; -- ERROR
SELECT pg_read_file('/etc/passwd');            -- ERROR
```

Затем повторить бизнес-операции (создание аккаунта, приглашения, membership, billing) от runtime.
Результат фиксируется в release evidence, а не в рабочей базе.

Скрипт проверен на отдельной базе Commercial: `SELECT` из `commercial` проходит, а `CREATE TABLE`,
`TRUNCATE` и `DELETE FROM public.doctrine_migration_versions` завершаются `permission denied`.
CI gate `scripts/ci/db-role-gate.sh core|commercial` выполняет это на одноразовых БД:
создаёт отдельную runtime-роль, проверяет `rolsuper/rolcreatedb/rolcreaterole/rolbypassrls = 0`,
разрешает чтение прикладной таблицы и требует отказа для DDL, `TRUNCATE`, удаления history
миграций (`public.platform_migrations` в Core, `public.doctrine_migration_versions`
в Commercial) и `pg_read_file`. Это gate для ролей, а не доказательство всех
HTTP/worker flows; полный lifecycle под runtime-role остаётся обязательной
release-проверкой.

Известный пробел текущего gate: он принимает любой SQL error за отказ по правам.
`TRUNCATE` таблицы с внешними ключами может вернуть `0A000` при наличии права;
требуется проверка SQLSTATE `42501` и явного отсутствия привилегии. См. [аудит](../AUDIT.md).

## Ограничения

- Локальный Compose по-прежнему использует bootstrap-суперпользователя. Это dev-модель,
  а не production credentials; перед production обе БД должны быть переключены на runtime-роль.
- Скрипт не переключает рабочую установку автоматически: это осознанная операция оператора.
- Существующие объекты должны принадлежать migrator либо owner; `REASSIGN OWNED` не выполняется
  автоматически.
- Пароль bootstrap-пользователя Compose остаётся локальным секретом и не должен использоваться
  production-приложением.
