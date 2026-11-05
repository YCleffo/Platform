# Аудит Platform

Дата: **5 ноября 2026**. Проверяемая база: `8543cb58` и незакоммиченные изменения
текущего рабочего дерева. Коммиты и push не выполнялись.

**Итог: NOT_READY для production.** Этот отчёт заменяет накопленную историю этапов.
Ниже разделены свежие локальные результаты, исправления CI и ещё не подтверждённые
условия выпуска. GitHub Actions для нового дерева не запускался.

## Область и границы проверки

Проверены четыре присланных сбоя: Composer audit Core/Commercial, Core account browser
E2E и Commercial account workspace E2E. Дополнительно проверяются статический анализ,
полные прикладные test suites, frontend quality/build и документационные gates.
Это инженерная проверка текущего кода и CI, а не новый полный security scan исходников,
нагрузочная аттестация или приёмка внешних провайдеров.

Все destructive проверки выполняются в отдельном проекте Docker
`platform-ci-repair-20261105`: Core `platform_test`, Commercial
`platform_commercial_test`, отдельные Redis, SMTP, сети и тома.
Рабочие Core/Commercial/MyBots БД не используются. Секреты одноразовые и находятся
вне репозитория. Backend зависимости устанавливаются в отдельной копии исходников;
рабочие PHP vendor directories не заменяются.

## Исправленные причины CI

| Причина | Изменение | Подтверждение |
| --- | --- | --- |
| Новые Symfony advisories от 4 ноября | Core: filesystem, serializer, yaml → 7.4.23. Commercial: config, filesystem, framework-bundle, http-client, http-foundation, http-kernel, routing, security-http, serializer, validator, yaml → 7.4.23 | Обе установки по lock, Composer platform requirements и audit проходят; composer.json и PHP 8.3 сохранены |
| Core отклонял возврат от Google/Telegram | Per-page client ID менялся после full-page redirect. Одноразовый OAuth marker теперь переносит исходный ID; восстановление разрешено после проверки provider/state/expiry/purpose | Регрессия воспроизводится на исходном коде; unit tests проверяют новый document, чужой state, повреждённый ID, replay и отдельную identity-link вкладку |
| Workspace проверял активный раздел до завершения router transition | Проверка ждёт ровно одну активную ссылку с нужным href одновременно в desktop и mobile navigation | Полный account workspace browser E2E PASS |
| UI fixture отпускал задержанный login до ухода со страницы | Перед ответом ожидается новый layout с активной навигацией, а не прежний main | 42 auth UI states PASS, включая late-response race |
| Следующий Core E2E ждал устаревший текст отказа | Проверяется актуальный status Workbench. Отсутствие строк предыдущего оператора и API 403 остаются обязательными | Полный повторный Core E2E — см. матрицу ниже |
| Node 20 warning для artifact upload | Все 6 `actions/upload-artifact@v4` заменены на `@v6`, использующий Node 24 | Upstream action.yml проверен; actionlint PASS. Реальная загрузка artifacts требует нового Actions run |

Серверные state/nonce/PKCE/client binding, single-use challenge, проверка владельца
provider identity и запрет выпуска сессии при MFA recovery сохранены.
Marker содержит несекретный client ID и hash state, но не access/refresh tokens,
raw state, code или verifier. После потребления marker удаляется; обычная новая
страница получает новый ID. Identity-link popup передаёт code исходной вкладке;
она завершает операцию под собственной session и client binding.

Диагностика Core browser E2E теперь сначала выводит исходную ошибку. Ошибка
получения screenshot/DOM при навигации больше не подменяет причину падения.

Первичные источники обновлений:
[Symfony Serializer advisory](https://symfony.com/cve-2026-118515),
[Filesystem advisory](https://github.com/symfony/symfony/security/advisories/GHSA-6prc-h956-hxjq),
[upload-artifact v6 action](https://github.com/actions/upload-artifact/blob/v6/action.yml).
Полный актуальный набор advisories проверяется Composer, без ignore/suppressions.

## Свежие результаты локальной проверки

| Проверка | Результат этого прохода |
| --- | --- |
| Core Composer validate/platform/install/audit | PASS; security advisories 0 |
| Commercial Composer validate/platform/audit | PASS; security advisories 0 |
| Core Psalm без cache | PASS, JSON `[]` |
| Commercial PHPStan level 7 | PASS, 0 errors |
| Commercial PHPUnit, Linux/PHP 8.3.36 | PASS: 468 tests / 5027 assertions |
| Symfony YAML/container lint | PASS: 9 YAML files; container compatible |
| Core полный deterministic suite, seed 2120741368 | PASS: 540 tests / 14290 assertions; API 75, Console 2, Functional 94, Unit 369 |
| Frontend Vitest | PASS: 70 files / 364 tests |
| Frontend typecheck, ESLint, Prettier | PASS |
| Styles, generated tokens, SVG sprite | PASS; 30 symbols |
| Core mutation guards | PASS: 11 tests |
| Dependency security regressions | PASS: 2 tests |
| HTTP workflow contracts / RU-EN labels | PASS: 2 tests |
| Frontend npm audit | PASS: vulnerabilities 0 |
| Production frontend build | PASS; main JS 436.38 kB / gzip 139.76 kB |
| Account workspace browser E2E | PASS: login, accounts, members, switching, unknown account, re-login, theme, 320/1440, keyboard, cross-user denial |
| Core account browser E2E | PASS: activation/reset/recovery, Google/Telegram, replay denial, API 403 и отсутствие чужих строк, required password change/MFA; 20 responsive/axe public states |
| Core credential browser E2E | PASS: real cryptographic passkey registration/login, link/unlink/cancel/conflict, last-method protection, recent authentication; 100 reads @20, 0 errors |
| Core auth UI fixtures | PASS: 42 RU/EN states, light/dark, reduced motion, responsive/axe и auth races |
| Workflow actionlint / CI shell syntax | PASS |
| PowerShell syntax | PASS; Windows ZIP regression локально не запускался: CI-only entry point |
| Database Python tests | PASS: 3 tests |
| Generated schema reference / coverage inventory | PASS |
| DBML parser | PASS: Core 50 / Runtime 3 / Commercial 61 tables |
| Documentation Python types / npm audit | PASS: 0 errors, warnings, vulnerabilities |
| Markdown local links | PASS: 418 ссылок в 50 Markdown документах |

Полные команды соответствуют `.github/workflows/ci.yml`:
`composer validate --strict`, `composer check-platform-reqs`, `composer audit`,
`vendor/bin/psalm --no-cache --no-progress --output-format=json`,
`vendor/bin/phpstan analyse --no-progress`, `vendor/bin/phpunit`,
`vendor/bin/codecept run --seed 2120741368`, `npm test`, `npm run build`,
`node scripts/e2e-account-workspace.mjs`, `npm run test:e2e:core-auth`,
`npm run test:e2e:core-credentials`. Результаты не подменяются прежними прогонами.

## Остаточный security inventory образов

В этом проходе свежий Trivy inventory рабочих/production images **не выполнялся**.
Ранее зафиксированный blocker libxml2/CVE-2026-6653 и другие distro findings
не считаются закрытыми обновлением Composer. Предыдущие counts не переносятся
в таблицу свежих результатов. Принятие остаточного риска не получено:
[правила работы с findings](operations/IMAGE_SECURITY.md).

Рабочие HTTP/worker images этим изменением не обновляются. Новые lock files
попадут в них только при следующей сборке и развёртывании. Проверка тестового
образа и vendor directory не доказывает обновление production.
Перед выпуском нужны свежие inventories точных image digests и прохождение
fixable HIGH/CRITICAL gate без сокрытия остальных findings.

## Актуальные открытые условия

1. **Новый полный GitHub Actions для выпускаемого SHA.** Локальные PASS не являются
   удалённым CI. Cross-plane, production-image smoke/scan, periodic billing и все
   остальные browser jobs необходимо оценить по новому run.
2. **Живые внешние интеграции.** Google/Telegram consent/cancel/revoke/recovery,
   YooKassa sandbox с публичным HTTPS webhook, чеком/refund, фактические SMTP/TLS/DNS.
   Test OIDC transport, virtual WebAuthn authenticator и Mailpit их не заменяют.
3. **Production privacy.** Настроить настоящие operator identity/address,
   processing purposes, evidence storage и legal/representative policies.
   Синтетические browser fixtures нельзя переносить в production.
4. **Эксплуатационная приёмка.** Runtime/maintenance/migrator role checks для
   выпускаемого окружения, restore/PITR drill, monitoring/alerts, worker recovery,
   реальные объёмы данных и согласованные SLO.
5. **Coverage и границы продукта.** Source inventory и frontend caller не доказывают
   end-to-end lifecycle каждой операции. Education/deployment UI по-прежнему требует
   собственных backend contracts. Существующий billing/periodic код сохраняется;
   этот проход не означает повторную приёмку всего billing.

Текущая сборка main JS меньше 500 kB; прежний warning 636.46 kB не является
результатом этой сборки. Это не измерение сетевой производительности production.

## Документация и очистка

Вместо датированных промежуточных отчётов поддерживается этот единый аудит.
Обновлены frontend/auth/security описания одноразового OAuth client ID и условия
выпуска. Архитектура, контракты, миграции, schema catalogs, generated coverage и
privacy/operations runbooks сохраняются как действующая документация.

Удалены 54 PNG прежних этапов: после переписывания аудита на них нет ссылок из
действующих документов или кода. Перед удалением все файлы сохранены и проверены
в архиве вне репозитория: `documentation-evidence.zip`, SHA256
`863943094a7cf088b84eba8b4b7163d4d11e1dabfe6fff7d9fe209f412d12e46`.
Файлы удалены по одному без рекурсивного удаления; ссылки документации проверены после очистки.

Очистка завершена: удалены 7 сервисов стенда, 4 тестовых тома, 2 сети,
3 созданных test/tool images, frontend dist/screenshots, Python caches и временная
копия исходников/vendor с одноразовыми секретами. Preview 4174/4175 остановлены;
временная запись hosts удалена. Build cache освобождён на 3.13 GB, остаток 0 B.

Итог Docker: **те же 13 рабочих container IDs, 12 running, 4 рабочих тома,
7/7 images used**. Core health и Commercial live возвращают **200**.
Рабочие Core/Commercial/MyBots контейнеры не пересоздавались.
Пересобранный при разрешении Compose dependencies PostgreSQL image удалён как
неиспользуемый: локальный tag `platform-postgres:local` создаст следующая сборка;
работающие PostgreSQL контейнеры сохранили свои исходные image IDs.

При cleanup основной Compose также запросил удаление общих именованных runtime
томов; Docker отказал, поскольку они используются. Все четыре рабочих тома затем
проверены. Для повторения следует удалять конкретные test resources, как делают
`test-stop.sh` и `control-test-stop.sh`: project name сам по себе не изолирует
явные имена томов из основного Compose.

Логи и новые screenshots сохранены вне репозитория в `verification.zip`, SHA256
`acb221d5bf22be0fd02b4fe4c0f07c46150fcda7d022daf4b20c8de589d6a223`.
Оба архива и `final-summary.json` находятся в локальном хранилище Codex:
`~/.codex/state/plugins/codex-security/scans/Platform/artifacts-ab2770cbc283f21f32f548ba352f7e16ca6799138a9831af98b61d68a3b586df/artifacts/ci-repair/`.
