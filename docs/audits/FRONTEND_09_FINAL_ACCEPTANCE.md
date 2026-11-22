# FRONTEND_09_FINAL

**Общий вердикт — `NOT_VERIFIED`.** Приёмка выполнена локально на изолированных Core, Commercial и Education. Для полного вердикта нужны оставшиеся проверки из таблицы и GitHub Actions для SHA кандидата.

Исходный SHA — `4cf8c6d40ea107f6da1c3265c97a098962b78615`. HEAD и локальная ссылка `origin/main` совпадают. Исходный `Platform CI` завершился с результатом 16/16. Текущий кандидат остался рабочим деревом. У итогового коммита и GitHub Actions run пока нет идентификаторов. Отпечаток рабочего дерева и список файлов в машиночитаемом отчёте. [Исходный CI](https://github.com/YCleffo/Platform/actions/runs/44988575194) · [Evidence JSON](frontend09-final-evidence.json)

Журналы, патч пакета и отчёты ниже — артефакты локальных прогонов. Они не входят в репозиторий, поэтому указаны путями, а не ссылками.

| Gate | Результат | Покрытие и граница |
| --- | --- | --- |
| `CORE_AUTHENTICATED_UX_VERIFIED` | `NOT_VERIFIED` | Ролевые прогоны и навигация перечислены в матрице. Полная проверка Back, Forward, reload, локали и прав для каждого вложенного маршрута открыта. |
| `COMMERCIAL_AUTHENTICATED_UX_VERIFIED` | `NOT_VERIFIED` | Реальные рабочие сценарии Account и Operator проверяются по отдельным прогонам. Полный набор биллинговых и операторских экранов и состояний открыт. |
| `CROSS_PRODUCT_DESIGN_CONSISTENCY` | `NOT_VERIFIED` | Общие компоненты и токены проверяются автоматически. Полная сравнительная матрица состояний остаётся открытой. |
| `AUTHENTICATED_THEME_VERIFIED` | `NOT_VERIFIED` | Есть проверки темы в реальных кабинетах и отдельный сценарий системной темы и reduced motion. Полный набор состояний для каждой роли открыт. |
| `AUTHENTICATED_I18N_VERIFIED` | `NOT_VERIFIED` | Проверены выбранные RU/EN сценарии. Полная проверка валют, часовых поясов, сообщений и длинных подписей по ролям открыта. |
| `AUTHENTICATED_LINKS_AND_NAVIGATION` | `NOT_VERIFIED` | Динамические ссылки классифицированы. Для части источников осталось воспроизвести конкретное действие в браузере. |
| `AUTHENTICATED_RESPONSIVE_A11Y` | `NOT_VERIFIED` | Результаты Chromium, axe и ручного разбора Settings в визуальном отчёте. Ручная проверка со screen reader остаётся открытой. |
| `AUTHENTICATED_MOTION_PERFORMANCE` | `NOT_VERIFIED` | Замеры выборочных переходов целевого кандидата. Полное сопоставление по ролям с исходной ревизией открыто. |
| `FRONTEND_09_REGRESSION_EXPANDED` | `PASS` | 541 Vitest, 19 браузерных скриптов и 19 изолированных реальных прогонов Core, Commercial и Education. Машиночитаемый отчёт фиксирует отпечаток исходников, команды, код выхода и артефакты. |
| `FRONTEND_09_FINAL_CI_GREEN` | `NOT_VERIFIED` | Для текущего рабочего дерева нет опубликованного SHA и GitHub Actions run. |

## Исправления

- Core Admin. Мобильная панель получает фокус при открытии, удерживает Tab и возвращает фокус при Escape. При переходе она закрывается. Активный раздел имеет `aria-current`; ключи групп сохраняются при переключении языка. Подвкладки Security остаются видимы после сужения окна, а у вложенного Privacy есть активная вкладка Profile. [AdminLayout](../../apps/frontend/src/modules/admin/AdminLayout.vue) · [Security](../../apps/frontend/src/modules/admin/AdminSecurityLayout.vue) · [Profile](../../apps/frontend/src/modules/admin/profile/CoreProfileLayout.vue)
- Карточки Core Dashboard снова видимы и учитывают права чтения. Ярлыки Feature flags и Legal holds открывают нужные вкладки, а Back и Forward восстанавливают выбор. [Dashboard](../../apps/frontend/src/modules/admin/AdminDashboardView.vue) · [Settings](../../apps/frontend/src/modules/admin/AdminSettingsView.vue) · [Privacy](../../apps/frontend/src/modules/admin/AdminPrivacyView.vue)
- Commercial Account. Активная вкладка видна после изменения ширины, локали и адреса. Доступное имя переключателя включает отображаемое название аккаунта. Периоды подписки отображаются локализованной подписью каталога. [AccountLayout](../../apps/frontend/src/modules/commercial/account/AccountLayout.vue) · [Переключатель](../../apps/frontend/src/modules/commercial/account/AccountSwitcher.vue) · [Billing](../../apps/frontend/src/modules/commercial/billing/AccountBillingView.vue)
- Commercial Operator. Выбранный раздел доступен как нажатая кнопка; сценарий проверяет, что активен один раздел. [OperatorConsole](../../apps/frontend/src/modules/commercial/operator/OperatorConsole.vue)
- Settings. Общий ползунок использует контрастный токен для незаполненной дорожки. Выбор языка в соседней вкладке того же origin сохраняет открытый диалог, фокус и черновик. [AppRange](../../apps/frontend/src/shared/ui/fields/AppRange.vue) · [Локализация](../../apps/frontend/src/shared/i18n/index.ts)
- Education. Сценарий календаря задаёт даты в часовом поясе организации и выбирает точный идентификатор завершённого урока. Сценарий Storage ждёт готовности S3 со стороны Core после старта SeaweedFS. [Календарь](../../apps/frontend/scripts/e2e-education.mjs) · [Storage E2E](../../apps/frontend/scripts/e2e-education-storage.mjs)
- В Teacher видимое имя кнопки дня недели сохранено, полная дата передаётся отдельным доступным описанием. В Parent имена переключателей начинаются с видимого RU или EN. Проверки WCAG 2.2 AA теперь включают критерий Label in Name в обоих сценариях. [Today](../../apps/frontend/src/modules/education/TodayView.vue) · [Parent](../../apps/frontend/src/modules/education/parent/ParentLayout.vue)

## Локальная проверка

| Команда | Результат |
| --- | --- |
| `npm test` | 541/541 в 109 файлах. В предыдущем локальном этапе было 541/541. Дополнительные регрессии добавлены в существующие тесты и браузерные скрипты. |
| `npm run typecheck`, `npm run lint`, `npm run format:check` | `PASS`. |
| `npm run check:tokens`, `npm run check:styles`, `npm run check:icons`, `node --test scripts/*.test.mjs` | `PASS`, в том числе 20 тестов Node. |
| `npm run build` | `PASS` в конфигурациях с включённым и выключенным Education. |
| `node ../../scripts/ci/run-verify-suite.mjs` | 19/19 в production preview, 4 мин 1 с. HTTP-сценарии с фикстурами размечены отдельно от реального API. Результаты — `apps/frontend/screenshots/verify-timings.json` |
| `node apps/frontend/scripts/frontend07-coverage.mjs --check`, `actionlint 1.7.11` | `PASS`. |

Первые локальные прогоны выявили невидимые карточки Dashboard, зависимость сценария календаря от даты и порядка одноимённых уроков, а также сбой повтора передачи при перезапуске SeaweedFS. Повторы завершились успешно. Результаты сохранены в каталоге журнала — `apps/frontend/screenshots/frontend09-final/logs`

Временные backend-проекты, их тестовые тома, worktree и каталоги сборок удалены. Настройка тестовых Compose-профилей пересобрала локальный тег `platform-postgres:local`. Работающий PostgreSQL сохранил прежний идентификатор образа.

## Выпуск

Локальный пакет содержит патч к исходному SHA и файл с SHA дерева и патча. Применимость проверяется командой `git apply --check <путь-к-патчу>` на чистом рабочем дереве исходного SHA. После разрешённых операций Git зафиксировать итоговый SHA, запустить `Platform CI` на нём, сверить все основные jobs и выгрузки браузерной проверки с этим SHA. Патч пакета — `apps/frontend/screenshots/frontend09-final/release-candidate.patch`, манифест — `apps/frontend/screenshots/frontend09-final/release-candidate.json`

Следующий этап локальной приёмки — пройти критические авторизованные маршруты с NVDA и нативным масштабом 200–400%.

[Матрица](FRONTEND_09_AUTHENTICATED_MATRIX.md) · [Визуальная проверка](FRONTEND_09_VISUAL_FINAL.md)
