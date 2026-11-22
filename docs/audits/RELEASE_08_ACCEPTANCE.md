# RELEASE_08 — приёмка GitHub Actions

Проверка 22 ноября 2026 года. После `git fetch origin --prune` локальный `HEAD`, upstream и `origin/main` совпали, рабочее дерево было чистым. Проверяемая ревизия — `01ab19b1fb13d80524be97c68849ace98c02a69b`.

| Gate | Результат |
| --- | --- |
| `RELEASE_08_CI_VERIFIED` | **FAIL** для `01ab19b1` и `2beff3f6`. |
| Тесты на GitHub runners | Для `01ab19b1` в попытке 3 завершились 16 jobs, 12 успешно и 4 с ошибкой. |
| `WINDOWS_NATIVE_ACCEPTANCE` | **NOT_VERIFIED**. Нативный Windows logon требует отдельной VM-проверки. |
| Production readiness | `NOT_VERIFIED`. Релизная приёмка остаётся открытой. |

## GitHub Actions

После перехода репозитория в публичный режим GitHub выдал runners всем 16 jobs третьей попытки на том же SHA. Попытка завершилась в 08:01:45 по Екатеринбургу (UTC+05:00), conclusion `failure`. Ошибки принёс Prettier, обе проверки PHP coding style и проверка API coverage без TypeScript. Результаты шагов и артефактов проверены отдельно. [Попытка 3](https://github.com/YCleffo/Platform/actions/runs/44975349669/attempts/3)

С согласия пользователя в `origin/main` опубликованы коммиты `2b0ada31fde64ddafa059040f6ffb54e7d026014` и `2beff3f6b3e8c0d1866af0597d995036bd0c6a8a`. Для `2beff3f6b3e8c0d1866af0597d995036bd0c6a8a` run ID `44978793972`, attempt 1 завершился в 08:15:19 с conclusion `failure`. Успешны 14 из 16 jobs, включая обе Core quality jobs и Frontend quality. Отказы — `Commercial · fast feedback` и `Core · account browser E2E`. [Прогон кандидата](https://github.com/YCleffo/Platform/actions/runs/44978793972)

Workflow — `Platform CI` (`.github/workflows/ci.yml`), run ID `44975349669`, событие `push` на `main`. Первая попытка завершилась в 07:17:03, вторая — в 07:38:46 по Екатеринбургу (UTC+05:00). Для второй попытки отправлен полный повтор исходного прогона, GitHub API вернул HTTP 201. Head SHA обеих попыток совпадает с проверяемой ревизией. [Попытка 1](https://github.com/YCleffo/Platform/actions/runs/44975349669/attempts/1) · [Попытка 2](https://github.com/YCleffo/Platform/actions/runs/44975349669/attempts/2)

Во всех 16 аннотациях второй попытки GitHub указал одинаковую причину: ошибка недавнего платежа аккаунта или недостаточный лимит расходов. Аннотации первой попытки содержат то же сообщение. Конкретную причину внутри биллинга аннотации не различают. API журналов каждой job первой попытки вернул HTTP 404. ZIP журналов второй попытки скачался и содержит 0 записей. GitHub Actions опубликовал 0 артефактов. Воспроизводится отказ в назначении GitHub runner, поэтому код workflow не может устранить этот отказ. [GitHub Docs: биллинг Actions](https://docs.github.com/en/billing/concepts/product-billing/github-actions)

| Job | ID, попытка 1 | ID, попытка 2 | ID, попытка 3 | Conclusion попытки 3 |
| --- | ---: | ---: | ---: | --- |
| CI configuration | 137063900230 | 137069157802 | 137071142237 | `success` |
| Windows tools | 137063900228 | 137069157331 | 137071142281 | `success` |
| Windows agent | 137063900290 | 137069157569 | 137071142173 | `success` |
| Core · fast feedback | 137063900355 | 137069158110 | 137071142235 | `failure` |
| Core · quality and tests | 137063900291 | 137069157549 | 137071142049 | `failure` |
| Commercial · fast feedback | 137063900086 | 137069157695 | 137071142325 | `failure` |
| Commercial · quality and tests | 137063900224 | 137069157691 | 137071142340 | `success` |
| Image security · PostgreSQL | 137063900215 | 137069157527 | 137071142288 | `success` |
| Image security · Redis | 137063900282 | 137069157653 | 137071142319 | `success` |
| Image security · Mailpit | 137063900309 | 137069157830 | 137071142299 | `success` |
| Core ↔ Commercial · E2E | 137063900294 | 137069157566 | 137071142397 | `success` |
| Database and documentation | 137063900336 | 137069157602 | 137071142328 | `success` |
| Frontend · quality | 137063900267 | 137069157552 | 137071142345 | `failure` |
| Core · account browser E2E | 137063900285 | 137069157604 | 137071142357 | `success` |
| Billing · renewal and recovery | 137063900362 | 137069157584 | 137071142513 | `success` |
| Frontend · browser E2E | 137063900209 | 137069157627 | 137071142272 | `success` |

## Обязательные проверки в workflow

| Область | Что предусмотрено конфигурацией на проверяемом SHA |
| --- | --- |
| Core | Psalm, dry-run PHP CS Fixer, Unit/Console/Functional/API, миграции и объекты PostgreSQL, least privilege, проверка production image, проверки зависимостей и уязвимостей образов, browser E2E. Отдельный шаг `php -l` отсутствует; PHP разбирают анализатор и проверка стиля. |
| Commercial | PHPStan, миграции пустой базы и upgrade/rollback, интеграции, ограничения и права БД, cross-plane E2E, браузерные сценарии аккаунта, приглашений, восстановления, Billing и операторов, отдельная job продления и восстановления Billing. |
| Education | Миграции и PHPUnit suites Education/Foundation, отдельная PostgreSQL-база Classroom, регрессии DeviceManagement, браузерные роли teacher/student/parent/device, privacy и проверки границ доступа в ролевых сценариях. |
| Storage | Отдельный SeaweedFS, Storage02 PHPUnit и сценарий `storage-recovery`; сценарии покрывают подтверждение передачи, восстановление, версии, конфликт и повторное подтверждение. |
| Windows Agent | Release build, Credential Provider Release x64, протокол и recovery, regression и safe IPC, S3 transfer и storage resilience. |
| Frontend | Audit зависимостей, TypeScript, ESLint, Vitest, токены, стили, Prettier, проверка отказа E2E без изоляции, production build, accessibility и browser E2E. |

Это статическая сверка команд workflow. Результатов исполнения этих команд на GitHub для указанного SHA пока нет. [Platform CI](../../.github/workflows/ci.yml)

Проверка конфигурации изоляции подтвердила `APP_ENV=test` для тестовых сервисов, отдельные PostgreSQL, Redis, Mailpit и SeaweedFS, случайные CI-ключи и nonce, который браузерный guard проверяет напрямую у Core и через frontend proxy. Синтетический Agent включается только через test DI после проверки изолированной БД. `SessionEvidence` отклоняет synthetic evidence вне разрешённого адаптера и смешение synthetic с Windows evidence. Cleanup проверяет подтверждённый checkpoint текущего устройства и ученика, а также отсутствие ожидающего манифеста. Исполнение проверок tenant/auth/session isolation, presigned URLs и ролей БД на GitHub остаётся открытым. [Compose CI](../../compose.ci.yaml) · [Отдельный S3](../../compose.ci.s3.yaml) · [Guard](../../apps/frontend/scripts/core-e2e-guard.mjs) · [SessionEvidence](../../apps/backend/src/DeviceManagement/Application/SessionEvidence.php) · [Хранилище сессий устройств](../../apps/backend/src/DeviceManagement/Infrastructure/PostgresDeviceStore.php)

## FRONTEND_07 browser evidence

| Обязательный сценарий | GitHub evidence для `01ab19b1`, attempt 3 | Завершение, UTC | SHA-256 аттестации |
| --- | --- | --- | --- |
| `teacher-enrollment-journal` | `PASS`, exit 0 | 02:58:25.617 | `50ba16a4b544fde8c216475b46b7e6c17343ba74f53d2fdfb21405ee4c1305b7` |
| `classroom-lifecycle` | `PASS`, exit 0 | 02:59:10.565 | `8cdb34ee4bfe5629ec672812a9003743a95181d8caeedef0e98b8367403a86f8` |
| `storage-recovery` | `PASS`, exit 0 | 03:01:40.308 | `d2974f90c8ab88d4e14f4fb4f40430a0d36127546ba2923bb0c7c59f02435f5a` |

Манифест `github-44975349669-3` скачан из GitHub artifact `14737205500`. SHA-256 ZIP `37f19d9183ad2050367cf2905ae53cfdc6bdb11afa38d1ab1ec57a5ca2ad4649` совпал с digest GitHub. Манифест содержит точный `01ab19b1`, `sourceDirty=false`, source digest `2faebadcbd8f19b6c4b271bd8dc7dc96a389ee7b2347c8754d21b2cf35de0acb` и `isolated-ci` с хешем доказательства `b718553be623ea21543b323d7acd9c98fb90b0b962a599db4b7a18dd04930a07`. Штатный `--check-evidence` прошёл на GitHub и повторно после скачивания ZIP в чистом checkout этого SHA. Исторические манифесты `d493eeee` с `sourceDirty=true` относятся к предыдущей локальной проверке. [Артефакт GitHub](https://github.com/YCleffo/Platform/actions/runs/44975349669/artifacts/14737205500) · [Приёмка FRONTEND_07](FRONTEND_07_ACCEPTANCE.md) · [Валидатор](../../apps/frontend/scripts/frontend07-evidence.mjs)

Для `2beff3f6` скачан artifact `14737007367`, SHA-256 ZIP `8331980b9429777becb1a79b0f770f1abc99c5ab7c796e1425ae99844bb0a679`. На чистом checkout валидатор подтвердил привязку манифеста `github-44978793972-1` к SHA, чистому дереву и изоляции. `teacher-enrollment-journal` и `storage-recovery` прошли, `classroom-lifecycle` завершился с `FAIL`. Код завершения `--check-evidence` — 1. [Артефакт GitHub](https://github.com/YCleffo/Platform/actions/runs/44978793972/artifacts/14737007367)

## Локально исправленный дефект и проверки

Закреплённый Prettier 3.9.10 воспроизвёл отказ `npm run format:check` на двух файлах. Отказ подтверждён на содержимом файлов из опубликованного коммита. В рабочем дереве изменены только переносы строк и отступы в [DeviceHistoryDialog.vue](../../apps/frontend/src/modules/education/devices/DeviceHistoryDialog.vue) и [DevicesView.vue](../../apps/frontend/src/modules/education/devices/DevicesView.vue). Шаг CI и его критерии сохранены.

В попытке 3 GitHub подтвердил дефект Prettier. Обе Core jobs также нашли одинаковые три нарушения PHP CS Fixer. Применено форматирование к `PostgresDeviceStore.php`, `tests/e2e/education-fixture.php` и `ClassroomPostgresRegressionTest.php`. Полный локальный PHP CS Fixer dry-run проверил 907 файлов, синтаксис трёх файлов прошёл `php -l`, а PHPUnit подтвердил 10 тестов Classroom и 60 утверждений.

В `Commercial · fast feedback` проверка API coverage выполнялась без установки frontend-зависимостей, что приводило к `ERR_MODULE_NOT_FOUND` для `typescript`. Чистое рабочее дерево воспроизвело отсутствие пакета, после `npm ci` импорт TypeScript 5.8.3 прошёл. В workflow добавлен такой же шаг установки из lock-файла. Статический реестр обновлён штатным генератором: изменились пять номеров строк в `DevicesView.vue`; 447 операций и их статусы сохранены. Локальные `actionlint` и `frontend07-coverage.mjs --check` прошли.

В прогоне `44978793972` установка TypeScript прошла, а генератор JSON оборвал вывод вызовом `process.exit(0)` сразу после записи в pipe. В контейнере Linux с Node `24.22.3` до исправления штатная проверка упала в той же строке JSON и на той же позиции 146176, что и на GitHub. После удаления принудительного выхода команда `frontend07-coverage.mjs --check` прошла в том же контейнере и на Windows. Изменение сохранено в коммите `644e513f5443bae962a18ee1cfb75471d860bdcb`. [Журнал job](https://github.com/YCleffo/Platform/actions/runs/44978793972/job/137074521247) · [Генератор](../../apps/frontend/scripts/frontend06-coverage.mjs)

В том же прогоне Playwright не нашёл второго ученика в списке переназначения Classroom. Сценарий создавал ученика отдельным HTTP-запросом после первого открытия списка. Изолированная проверка с управляемыми часами воспроизвела устаревшее чтение списка до истечения 20 секунд после первого запроса. Создание второго ученика перенесено до первого чтения списка. Реальные операции Classroom, проверка выбранного ID, запись новой сессии и проверки Storage сохранились. Локально прошли 6 тестов кэша, 12 проверок guard и валидатора, а также проверка API-реестра. [Журнал job](https://github.com/YCleffo/Platform/actions/runs/44978793972/job/137074521233) · [Подготовка браузерного сценария](../../apps/frontend/scripts/e2e-education-roles.mjs)

На исходной ревизии локально прошли actionlint, PHP syntax на 16 изменённых PHP-файлах, 12 проверок валидатора манифеста и guard, проверка design tokens и сверка статического API-реестра (447 операций). После исправления прошли полная проверка Prettier, ESLint, TypeScript, 523 Vitest в 107 файлах, 20 проверок скриптов и контрактов и production build во временный каталог. Отчёт оставлен вне проверяемой ревизии.

## Блокеры и следующий этап

Текущий gate требует полного `Platform CI` на ревизии с исправлениями генератора и Classroom и сверки трёх browser evidence на этом SHA. Отдельный нативный Windows gate остаётся открытым. Scope audit `CORE_COMMERCIAL_08` начинается после полного GREEN.
