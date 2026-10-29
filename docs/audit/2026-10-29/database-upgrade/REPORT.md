# Репетиция обновления существующих БД — 2026-10-29

**Результат этапа: PASS на изолированных копиях. Общая готовность: `CORE_NOT_READY`.**
Оригинальные БД не мигрировались, их роли/секреты не менялись. База исходников:
`6e3f0524af607abcdb29632831c97890fe955713` плюс текущий working-tree diff;
remote CI эти изменения не проверял. [Source manifest](evidence/source-manifest.json)
фиксирует новые инструменты и миграцию.

## Результат по данным

| Контур | Реальный source snapshot | После обновления копии | Сохранность |
| --- | --- | --- | --- |
| Core / Runtime | 31 migration, 53 таблицы, 600 строк | **33 migrations**, 53 таблицы | Все прежние строки и поля совпали по count/SHA-256 |
| Commercial | 25 migrations, 51 таблица, 59 строк | **40 migrations**, 60 таблиц | Все прежние строки и поля совпали по count/SHA-256 |

Источник — свежие логические дампы работающих `platform-postgres-1` / БД
`platform` и `platform-control-postgres-1` / БД `platform_commercial`, а не
пустая тестовая схема и не произвольно выбранный старый backup. PostgreSQL
18.6. Каждый dump и его baseline fingerprints взяты из одного exported read-only
snapshot соответствующей БД. Общего атомарного снимка двух БД здесь нет.

Два независимых финальных прогона:

- `09f93b19c6be`: восстановленные исходные данные без добавленных legacy fixtures;
  [полный результат](evidence/snapshot-only.json).
- `20a50dc56a75`: новый restore плюс явно синтетическая история **до** обновления;
  [полный результат](evidence/legacy-fixtures.json).

В настоящем source snapshot нет invoices/payments/refunds/receipts и external
identities. Их пустоту нельзя выдавать за проверку заполненной денежной истории.
Во втором прогоне созданы опубликованный paid plan/price, subscription/item,
issued→paid invoice/line на **10 000 копеек RUB**, succeeded payment/attempt,
succeeded refund/allocation на **5 000 копеек**, payment/refund receipts/items.
Все они созданы в старой 25-migration схеме при включённых SQL guards и deferred
checks, без provider calls, затем сохранены при 25→40 и повторном запуске.

В старую Core-схему второго прогона добавлены две external identities. `032`
правильно перенёс linked_at в created_at/updated_at и last_login_at в last_used_at
в обоих случаях — timestamp и NULL. Остальные прежние поля не изменились.

После миграции проверены точные имена всех applied migration files, отсутствие
NOT VALID constraints, совпадение catalog со схемой документации и no-op повтор.
В Core 747 constraints и 20 custom triggers, в Commercial 917 и 53; в counts
constraints включены PostgreSQL NOT NULL constraints. Восстановленные состояния
3 Core и 2 Commercial sequences миграции сохранили. [Хеши исходных таблиц](evidence/data-integrity.json)
не содержат значений клиентских строк.

## Почему Core теперь требует 033

Первоначальный 31→32 действительно прошёл без потери данных. Однако более
строгая сверка выявила старые варианты `015_core_data_hygiene.sql`: в реальной
БД запись о её применении есть, а текущего набора ограничений ещё нет.
[Точный diff](evidence/core-32-drift.json) содержит 9 различий полей catalog,
относящихся к **8 CHECK-ограничениям**:

- Старые проверки labels у accounts/person_contacts допускают control characters.
- Старый username CHECK допускает короткое значение и не требует value = normalized_value.
- Нет двух label CHECK для passkeys/TOTP и трёх description CHECK для settings/flags.

Добавлена [033](../../../../apps/backend/migrations/sql/033_reconcile_legacy_data_hygiene.sql),
которая устанавливает уже действующие ограничения чистой схемы. Ни `015`, ни
другие исторические migration files не редактировались. Не менялись API/event
contracts, состав таблиц и финансовые переходы. Требования CORE-01/02; проверка
денежной сохранности также покрывает CAT/BILL/PAY/REF/FISC invariants из
[CORE_INVARIANTS](../../../architecture/CORE_INVARIANTS.md).

Негативная проба добавляет старый username `x` в отдельной транзакции копии:
`033` отклоняет его с `23514`, вся транзакция/DDL откатывается, fingerprints
совпадают с baseline. Миграция не переписывает и не удаляет неподходящие данные.
В реальном Core runner каждая migration — отдельная транзакция: отказ `033`
не обещает откат уже успешно выполненной `032`.

В Commercial различия SQL текста CHECK/index predicates оказались эквивалентной
записью массивов varchar→text после dump/restore. Comparator нормализует только
эти литеральные casts, сохраняя элементы, порядок, operators и predicates.
Отсутствующие CHECK и изменённые значения продолжают давать FAIL; это покрыто
тестами. Содержательных расхождений Commercial нет, новая migration ему не нужна.
DBML не переписывался ради косметики: подтверждённая физическая модель осталась
той же, Core catalog получил applied `033` и её source hash.

## Проверка SQL boundary

Source роли `platform_app` и `platform_control` по живому `pg_roles` имеют
SUPERUSER, CREATEDB, CREATEROLE, BYPASSRLS и LOGIN. После репетиции flags, schema
metadata и history оригиналов остались прежними. Этот риск **не закрыт на
оригиналах**.

На каждой копии отдельно созданы admin, NOLOGIN owner, LOGIN NOINHERIT migrator
и LOGIN runtime. Runtime не имеет elevated flags, memberships, owned relations
или schemas. Migrator получил owner с INHERIT FALSE / SET TRUE / ADMIN FALSE;
именно он применил штатные migrations, без SUPERUSER.

Для каждого runtime все **11** опасных операций отвергнуты с SQLSTATE `42501`:
DDL в business/public schemas, ALTER таблицы, TRUNCATE, CREATE ROLE, назначение
себе SUPERUSER, SET ROLE owner, GRANT owner, запись history, TEMP, pg_read_file.
Итого **22 отказа за прогон**, в обоих полных прогонах. Default ACL дополнительно
проверены на новых owner objects: runtime делает INSERT с identity sequence и
вызывает разрешённую функцию, PUBLIC EXECUTE отсутствует.

Приложения действительно работали под runtime: Core Yii DI + Person create/read
и human Account; Commercial register/verify/login и token hashes, account/owner,
billing onboarding, published free plan, subscription→invoice→zero settlement,
повтор с тем же payment ID, SQL immutability отказ `23514` для published price и
derived entitlements. Пробы откатывались; полные бизнес-таблицы до/после совпали.
Они не доказывают приёмку всех фоновых workers под ограниченными ролями.
В копии передавались синтетические ключи, а не секреты оригиналов. Поэтому
проверка не подтверждает расшифровку существующих MFA factors или пригодность
старых session tokens; сохранность их DB-полей и новый synthetic auth — разные проверки.

В обоих прогонах `/health/live` и `/health/ready` дали HTTP 200 до и после
фактического restart двух приложений. StartedAt приложений изменился; DB
StartedAt остался прежним. Проверены разные internal networks, отсутствие
published host ports и отсутствие DNS-разрешения противоположной DB.
PostgreSQL restart, восстановление после аварии и внешний network pen-test
этим не имитируются.

Grants сейчас являются совместимым DML-уровнем своего контура, а не полной
потабличной least-privilege политикой всех процессов. [План перехода](../../../operations/SAFE_DATABASE_UPGRADE.md)
содержит ownership/default ACL, остановку writers, переключение DSN, отзыв старых
admin logins, preflight и recovery без удаления финансовой истории.

## Проверки и ограничения

[Машинный итог проверок](evidence/checks.json) отличает новые проверки от
[исторического общего аудита](../../2026-10-28/REPORT.md). Полный Commercial и
cross-plane suites в этом этапе повторно не запускались: их app code не менялся.
Полная новая Core regression прошла на отдельной свежей БД после установки
**0→33: 508 tests / 14 050 assertions**, seed `2120741368`, frozen Git-visible
source внутри image, совпадающий dependency lock. [Результат](evidence/core-regression.json)
и [898 source hashes](evidence/core-regression-source-manifest.json) сохранены.
Это test-only bootstrap role для TestReset, а не подмена доказательств runtime ACL.
Также прошли 6 тестов защит rehearsal driver, syntax/compile, live catalog checks,
DBML parser и справочник полей. Частично пройденные/заблокированные попытки
не засчитываются как PASS: среди них незавершённый медленный Windows bind run,
отказ TestRuntimeGuard из-за имени новой test DB и один полный run с ошибкой
настройки heartbeat checks в стенде. Для итогового PASS исправлялся стенд;
existing tests и application guards не ослаблялись.

Дампы, credentials, необработанные SQL/application logs и восстановленные volumes
остались в локальном ignored `runtime/database-upgrade/`, не в Git. Контейнеры
репетиции остановлены. Изображения/volumes не публиковались и не удалялись.

Исторический `Platform.zip`, в котором ранее обнаружены env/backups/runtime,
2026-10-29 уже отсутствует по прежнему пути. Это не доказывает ротацию или утечку.
Path-only проверка текущих Git-visible файлов не нашла приватных env/dumps/
backups/provisioning secrets; полноценный content secret scan не заявляется.
[Archive boundary](evidence/archive-boundary.json) фиксирует эту ограниченную
проверку. Оригинальные секреты не ротировались автоматически.

Следующая граница — управляемый cutover оригиналов по runbook, затем отдельная
приёмка E01–E20. Производительность 10 000 VU, provider sandbox, CI/PITR/HA и
production provisioning остаются за пределами этой репетиции.
