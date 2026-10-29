# Safe Database Upgrade & Security Boundary

Последняя репетиция: **2026-10-29**, [отчёт и доказательства](../audit/2026-10-29/database-upgrade/REPORT.md).
Это runbook обновления существующей установки, а не инструкция сброса базы.
Требования: CORE-01/02, COMM-01/02, CAT-01/02, BILL-01/02/04,
PAY-01/02/03, REF-02/04, FISC-01/02 и CORE-13/ENT-01/02/03.

## Что проверено и где проходит граница

Снимки обычных локальных БД восстановлены в отдельных PostgreSQL-контейнерах.
Core обновлён **31→33**, Commercial **25→40**. На исходной ревизии путь Core
31→32 сохранял записи, но оставлял восемь старых/отсутствующих CHECK-ограничений.
Новая `033_reconcile_legacy_data_hygiene.sql` устраняет это расхождение с уже
действующим контрактом чистой установки. Старые migration files и history rows
для этого не переписываются.

**Оригиналы не обновлялись.** В них остаются 31/25 миграций и прежние
административные runtime-роли. Репетиция и план не означают выполненный cutover.
Два отдельных снимка не являются атомарным cross-database backup или PITR.
Результат не закрывает E01–E20, платёжный sandbox и production readiness.

## Повторить репетицию

Нужны Docker Compose, Python 3, доступ к работающим локальным контейнерам
`platform-postgres-1` и `platform-control-postgres-1`. Команды из корня репозитория:

```powershell
python -m unittest discover -s scripts/database -p test_upgrade_rehearsal.py
python scripts/database/upgrade-rehearsal.py
python scripts/database/upgrade-rehearsal.py --legacy-fixtures

# Полная Core regression на новой отдельной test DB и исходниках внутри image:
python scripts/database/rehearsal/run-core-regression.py
```

Первый запуск проверяет только восстановленные исходные записи. Второй — новый
независимый restore с дополнительными синтетическими записями **до** миграции:
две external identities с NULL/non-NULL login timestamp, опубликованный тариф,
счёт, платёж, возврат, allocations и два чека. Это не реальные provider операции.
Синтетические записи и исходный snapshot различаются в manifests.

Каждый запуск создаёт собственный `platform-upgrade-<run-id>`, два volumes,
отдельные internal networks и случайные credentials. Host ports, Docker socket,
исходные volumes и provider credentials не передаются. Destination проверяется
по Compose/run labels, имени БД и `cluster_name` перед восстановлением/миграцией.
Оригинальные psql-транзакции явно `REPEATABLE READ READ ONLY`.

Дамп и контрольные хеши каждой исходной БД используют один exported MVCC snapshot.
После restore сверяются counts и SHA-256 отсортированного множества хешей строк.
После миграции сравнивается проекция на **все старые столбцы**, поэтому новые
backfilled fields проверяются отдельно. Затем сверяются полная схема, точные
имена миграций, validation constraints, повторный запуск, SQL-права, application
use cases, HTTP и перезапуск приложений при работающей БД.

`033` дополнительно проверяется на заведомо недопустимом старом username внутри
отдельной транзакции: ожидается SQLSTATE `23514`, данные/DDL должны откатиться.
Повреждённые значения не нормализуются автоматически. При реальном конфликте
нужно остановиться, получить согласованное решение по конкретным данным и
повторить репетицию; нельзя удалять историю ради зелёного результата.

Результаты сохраняются в `runtime/database-upgrade/<run-id>/`. Это Git-ignored
приватные данные: дампы содержат настоящие записи, логи могут содержать SQL
диагностику, JSON credentials относится только к копиям. На Windows новый
run directory сразу получает ACL только текущего пользователя и SYSTEM.
Volumes также содержат копии данных и доступны Docker-администраторам.

Для отдельной полной Core regression нужны локальные images
`platform-cross-plane-test:latest` (dev vendor) и `platform-upgrade-core:local`.
Regression driver замораживает Git-visible Core/contracts в приватный build
context, сверяет dependency composer.lock и создаёт новый кластер с БД
`platform_test`, marker `platform-upgrade-regression` и своим `redis-test`.
Имена соответствуют существующему TestRuntimeGuard; проверка не ослабляется.
Кластер и internal network принадлежат только новому regression project.
SQL migrations идут с нуля до текущей версии, затем запускается весь Codeception
с фиксированным seed. TestReset использует отдельный test-only bootstrap role;
этот результат не является доказательством прав production runtime.

По умолчанию контейнеры останавливаются, volumes/дампы остаются для проверки.
`--keep-running` оставляет только созданный проект работающим. Для последующего
старта/остановки нужен тот же project ID и его disposable env. Не используйте
`docker compose down -v` без явной проверки принадлежности всех volumes.

Проверка хешей рассчитана на объём этих снимков; агрегация строк требует памяти
и не является измерением длительности production upgrade на больших данных.
Sequences не имеют MVCC-семантики строк: сравнивается их восстановленное
состояние до/после миграций. Rollback бизнес-проб может расходовать sequence
values; это нормальные gaps, а не потеря записей.

## Модель ролей для каждого независимого кластера

| Роль | Назначение | Разрешения и ограничения |
| --- | --- | --- |
| admin / break-glass | Bootstrap, restore, аварийное обслуживание | Отдельный контролируемый credential; отсутствует в API/worker env |
| `core_owner` / `commercial_owner` | Владелец своей БД, схем и объектов | NOLOGIN, NOSUPERUSER, NOCREATEDB, NOCREATEROLE, NOREPLICATION, NOBYPASSRLS |
| `*_migrator` | Только управляемое обновление своего контура | LOGIN, NOINHERIT, обычные role flags; membership owner с INHERIT FALSE, SET TRUE, ADMIN FALSE; явный SET ROLE |
| `*_runtime` | Работа приложения своего контура | LOGIN без административных flags, ownership и memberships; CONNECT/USAGE и выбранный DML |
| backup / monitoring | Отдельная эксплуатационная задача | Отдельные read/monitor grants по фактическому инструменту; не app owner |

На копиях migrator действительно выполнил штатные migrations после `SET ROLE
owner`; runtime действительно выполнил application use cases. Проверены запреты
DDL, TRUNCATE, изменения migration history, CREATE ROLE, назначения SUPERUSER,
SET/GRANT owner, TEMP и чтения server files. PostgreSQL ownership само по себе
даёт права ALTER/DROP, поэтому одного `NOSUPERUSER` старому владельцу недостаточно.

Проверенный [runtime-grants.sql](../../scripts/database/rehearsal/runtime-grants.sql)
— **кандидат для репетиции**, защищённый cluster/database markers. Он выдаёт
SELECT/INSERT/UPDATE/DELETE всем бизнес-таблицам своего контура, USAGE/SELECT
sequences и EXECUTE функций. Это совместимый стартовый уровень, **не завершённая
построчная/потабличная least-privilege модель всех workers**. TRUNCATE, DDL,
ownership и запись public migration history не выдаются; чтение history разрешено.
Не меняйте guards этого файла ради запуска на оригиналах.

Перед production дополнительно разделите API, scheduler, email/outbox и
финансового оператора по реальным запросам и SQL guard contracts. Проверки
неизменяемости остаются включёнными при любом DML. Все текущие business functions
проверены по catalog; новые SECURITY DEFINER функции требуют отдельного review.
Default privileges задаются от имени owner: новые tables/sequences доступны
runtime, PUBLIC EXECUTE новых functions отзывается глобально для owner, затем
выдаётся нужному runtime в его схемах. Новые объекты migrator должен создавать
после SET ROLE owner, иначе эти defaults не применятся.

## Порядок управляемого перехода оригиналов

Ни один шаг ниже этой секции автоматически не выполняется rehearsal-скриптом.

1. Зафиксировать revision/image digests, текущие схемы, migration history,
   роли/memberships/owners/default ACL и конфигурацию всех writers. Убедиться,
   что имеется отдельный рабочий admin recovery credential. Снять свежие дампы,
   globals/ACL inventory в защищённое хранилище и выполнить restore drill.
2. Проверить preflight: нет дублей `(account_id, provider_id)` для Core `032`,
   все существующие usernames/labels/descriptions удовлетворяют проверкам `033`.
   Если нет — остановиться с count-only отчётом; значения не стирать и не менять
   молча. Список конкретных предикатов находится в `033`.
3. Назначить окно обслуживания. Остановить приём мутаций, schedulers, outbox,
   provisioner и остальные writers обоих контуров; дождаться транзакций и
   удержать согласованную границу. Сделать финальные backups и fingerprints
   после quiescence. Два независимых дампа без этой границы не обещают атомарность.
4. Создать отдельные owner/migrator/runtime роли с новыми секретами **в каждом
   своём кластере**. Передать только заранее инвентаризированные business schemas,
   relations/sequences/types/functions и техническую history владельцу owner;
   проверить database ownership. Не выполнять слепой REASSIGN OWNED по всему
   кластеру/расширениям. Core никогда не получает Commercial DSN и наоборот.
5. Через credential своего migrator и явный SET ROLE owner выполнить штатный
   Core SQL runner и Commercial Doctrine runner. Core runner транзакционен
   **на файл**, Commercial настроен all-or-nothing. Если Core `033` не пройдёт,
   успешно завершённая `032` может уже оставаться применённой — это нужно
   учитывать в recovery, а не обещать общий откат 31→33.
6. Выдать проверенные grants/default ACL. В отдельном deployment config изменить
   DSN API/worker процессов на runtime. Запустить сперва ограниченные экземпляры,
   проверить authentication/billing, immutability, idempotency, outbox и readiness
   под их реальными ролями; выполнить запретительные probes. Не выдавать DDL
   приложению для обхода ошибки permissions.
7. После успешной проверки и переключения всех клиентов запретить LOGIN старым
   app-admin ролям, убрать SUPERUSER/CREATEDB/CREATEROLE/REPLICATION/BYPASSRLS,
   завершить их старые соединения, убедиться в отсутствии оставшегося ownership
   и memberships, убрать старые credentials из конфигурации. Смена
   `POSTGRES_USER`/`POSTGRES_PASSWORD` в Compose не изменяет уже созданные роли и
   пароли существующего data volume. Старую роль можно оставить NOLOGIN ради
   явной истории обслуживания; финансовые записи не удаляются.
8. Сверить counts/old-column hashes, balances/issued snapshots, sequence state,
   все applied migrations, constraints, app restart и отсутствие новых ошибок.
   Возвращать трафик/работников поэтапно, сохраняя окно наблюдения и backups.

До возобновления writers recovery — восстановление **обеих** финальных копий
в новые volumes и проверенный compatible release/config. Не восстанавливать
поверх работающей БД. После возобновления записей старый dump может потерять
новые деньги/события: нужен инцидентный freeze и forward repair либо проверенная
recovery-процедура, а не автоматическое возвращение старого backup. Down-миграций
Core runner не предоставляет. Не отключать financial triggers ради recovery.
После любого live restore до открытия трафика обязательна privacy reconciliation:
старый dump может вернуть уже удалённые персональные данные. Применяются правила
[BACKUP_RESTORE](BACKUP_RESTORE.md), включая сохранение блокировки до сверки.

## Архивы и секреты

Исторический `Platform.zip` содержал `.env` и данные; при проверке 2026-10-29
его уже нет по прежнему пути. Это не доказательство внешней утечки или ротации.
Текущий Git-visible path inventory исключает private env, dumps, backups,
provisioning secrets и dependencies; проверка имён не заменяет content secret scan.

Для обычного экспорта есть [create-project-zip.ps1](../../scripts/create-project-zip.ps1).
При dirty worktree он создаёт архив **только изменённых файлов**; полный экспорт
делайте из проверенного чистого checkout. `.gitignore` не исключает секреты,
которые уже были намеренно добавлены в Git. Сохраняйте source package отдельно
от приватных recovery artifacts и проверяйте его manifest перед передачей.

Если архив выходил за доверенную границу, нужна управляемая ротация раскрытых
секретов по типам. DB/provider credentials и session-token HMAC имеют отдельные
планы переключения/отзыва. TOTP encryption keys требуют сохранения возможности
расшифровать и перенести существующие factors; случайная замена уничтожит MFA.
Entitlement signing keys меняются с trust overlap/key ID и сохранением monotonic
sequence. Эти действия и удаление старых архивов в данной репетиции не выполнялись.

## Основания решений

- PostgreSQL 18: [exported snapshots](https://www.postgresql.org/docs/18/functions-admin.html#FUNCTIONS-SNAPSHOT-SYNCHRONIZATION).
- [Ownership и privileges](https://www.postgresql.org/docs/18/ddl-priv.html),
  [INHERIT/SET membership](https://www.postgresql.org/docs/18/role-membership.html).
- [pg_restore и trusted input](https://www.postgresql.org/docs/18/app-pgrestore.html).
- [ALTER DEFAULT PRIVILEGES](https://www.postgresql.org/docs/18/sql-alterdefaultprivileges.html):
  schema-local REVOKE не отменяет глобальный default PUBLIC EXECUTE.
