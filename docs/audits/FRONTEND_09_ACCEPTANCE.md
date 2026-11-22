# FRONTEND_09 — локальная приёмка

**Вердикт: локальные изменения проверены в указанном ниже объёме. Полный FRONTEND_09 остаётся `NOT_VERIFIED`.**

Исходный SHA — `4cf8c6d40ea107f6da1c3265c97a098962b78615` (`main` и `origin/main`). Для него `Platform CI` прошёл 16 из 16 jobs в первой попытке. Кандидат находится в локальном рабочем дереве на этом SHA. По согласованному режиму локальной проверки кандидат не публиковался, итогового GitHub Actions run ID для него нет. [Исходный run 44988575194](https://github.com/YCleffo/Platform/actions/runs/44988575194)

Отдельного SHA коммита для локального кандидата пока нет. Отпечаток исходников рабочего дерева `3b53a5437efc6b466b1c0d482cc66ff1206dcc71269e6f2a7015f8ad8603b5a7` получен штатной функцией `revision()` из `frontend07-evidence.mjs`.

Снимки и JSON-отчёты ниже — артефакты локальных прогонов. Они не входят в репозиторий (`apps/frontend/.gitignore` исключает `screenshots`), поэтому указаны путями, а не ссылками.

| Gate | Статус | Доказательство или граница |
| --- | --- | --- |
| `UI_INVENTORY_VERIFIED` | `NOT_VERIFIED` | 12 областей описаны в Markdown и JSON. Для части авторизованных экранов визуальные данные есть только на исходном SHA. |
| `NAVIGATION_UNIFIED` | `NOT_VERIFIED` | Исправлены mobile/desktop режимы, активность вложенных маршрутов и конфликт жестов. Полный набор ролей и устройств ещё не пройден. |
| `THEME_CROSS_PRODUCT_VERIFIED` | `NOT_VERIFIED` | Системная тема, быстрые переключения, восстановление, fallback и редуцированное движение проверены в Chromium. Полная матрица авторизованных рабочих пространств открыта. |
| `I18N_CROSS_PRODUCT_VERIFIED` | `NOT_VERIFIED` | Приоритеты локали и сохранение черновика проверены. Полнота всех переводов, числовых и денежных форматов по ролям открыта. |
| `DESIGN_SYSTEM_CONSISTENCY` | `NOT_VERIFIED` | Education Login переиспользует AppButton и AppCard. Общие правила токенов, стилей и SVG прошли. Полное сопоставление состояний всех кабинетов открыто. |
| `ROUTE_LINK_INTEGRITY_VERIFIED` | `NOT_VERIFIED` | Статическая проверка прошла. Контекстные маршруты вынесены в браузерную проверку. Полный обход всех ролей открытый. |
| `RESPONSIVE_ALL_WORKSPACES` | `NOT_VERIFIED` | Есть реальная браузерная матрица публичных экранов. Экранную клавиатуру и все защищённые экраны ещё предстоит проверить на целевых устройствах. |
| `MOTION_PERFORMANCE_VERIFIED` | `NOT_VERIFIED` | Проверены длительность motion, последовательные переключения и циклы меню/диалогов. Полных измерений DOM, памяти и long tasks по ролям нет. |
| `ACCESSIBILITY_ALL_WORKSPACES` | `NOT_VERIFIED` | Есть axe и проверки клавиатуры. Ручная проверка screen reader, browser zoom 200–400% и контраст по всем ролям открыта. |
| `FRONTEND_09_TEST_COVERAGE` | `NOT_VERIFIED` | Расширены компонентные и браузерные тесты. Есть локальные эталоны трёх публичных экранов. Полная матрица авторизованных кабинетов открыта. |
| `FRONTEND_09_CI_GREEN` | `NOT_VERIFIED` | GitHub Actions проверил исходный SHA. Новый набор добавлен в действующий параллельный CI runner. |

## Изменения

- `AppLayout.vue` и `pageSwipe.ts` ограничивают переходы жестами. `AppBottomNav.vue` учитывает вложенные маршруты, изменение геометрии и завершение указательного жеста. Desktop Education использует sidebar, Student сохраняет связь вложенной страницы с вкладкой, Parent использует прокручиваемую строку. [AppLayout](../../apps/frontend/src/app/layouts/AppLayout.vue) · [Навигационная матрица](FRONTEND_09_NAVIGATION_MATRIX.md)
- `index.html` и `app/theme/index.ts` согласуют раннюю тему с системой при недоступном хранилище и избегают ненужного View Transition для того же цвета. `EducationLoginView.vue` переиспользует AppButton и AppCard, Education CSS сохраняет общие токены. [Тема](../../apps/frontend/src/app/theme/index.ts) · [Форма входа Education](../../apps/frontend/src/modules/education/EducationLoginView.vue)
- `shared/i18n/index.ts` применяет приоритет: явный выбор в браузере, настройка аккаунта Core, контекст Education, язык браузера, `en`. Явный выбор запоминается локально. Серверные значения принадлежат текущей сессии и сбрасываются при смене сессии. Часовой пояс задаётся отдельно. [Локализация](../../apps/frontend/src/shared/i18n/index.ts)
- `AppDialog.vue`, `AppMobileMenu.vue` и `useFocusTrap.ts` передают Escape и Tab верхнему оверлею. При закрытии фокус возвращается в родительский контекст. Добавлены регрессии для вложенных диалогов и пустого persistent-диалога. [Диалог](../../apps/frontend/src/shared/ui/overlays/AppDialog.vue) · [Проверки фокуса](../../apps/frontend/src/shared/ui/overlays/AppDialog.spec.ts)
- `AppMenu.vue` завершает возврат с временной записи мобильного меню перед действием выбранного пункта. Ранее этот Back мог отменить переход к выбранному ребёнку в Parent после отзыва доступа к другому ребёнку. `parent/workspace.ts` сохраняет выбор из актуального URL, когда завершается более ранний запрос контекста. [Меню](../../apps/frontend/src/shared/ui/menus/AppMenu.vue) · [Контекст Parent](../../apps/frontend/src/modules/education/parent/workspace.ts)
- `routeAudit.spec.ts` анализирует фактический реестр Vue Router, шаблоны и программные переходы и публикует JSON. `verify-frontend09.mjs` выполняет публичную визуально-функциональную матрицу в текущем CI runner. Статический API-реестр FRONTEND_07 обновлён генератором вслед за переносами строк. Статусы 447 операций сохранились. [Аудит маршрутов](../../apps/frontend/src/app/router/routeAudit.spec.ts) · [Браузерный runner](../../scripts/ci/run-verify-suite.mjs)

## Локальные проверки

| Команда | Результат |
| --- | --- |
| `npm run typecheck`, `npm run lint`, `npm run format:check` | `PASS` |
| `npm run check:tokens`, `npm run check:styles`, `npm run check:icons` | `PASS` |
| `npm test` | 109 файлов, 541 тест `PASS`. На исходной ревизии локально проходили 107 файлов, 523 теста. |
| `node --test scripts/*.test.mjs` | 20 проверок `PASS`. |
| `npm run build` | `PASS` для production-сборок с включённым Education и с настройкой основного frontend job. |
| `node apps/frontend/scripts/frontend07-coverage.mjs --check` | `PASS`. |
| Vitest `routeAudit.spec.ts` | 111 именованных маршрутов, 213 статически разрешённых ссылок, два фрагмента, 131 ссылка требует подтверждения в браузере, 0 установленных ошибок. |
| `node scripts/verify-frontend09.mjs` | 12 публичных состояний и форм входа совпали с исходным SHA по SHA-256 PNG. 0 автоматических нарушений axe, 0 необработанных ошибок страницы. Четыре состояния Settings требуют ручной оценки предупреждений axe. |
| `node ../../scripts/ci/run-verify-suite.mjs` на production preview | 19/19 Chromium-скриптов прошли в конфигурации основной frontend job. Responsive проверил 22 публичных и guard-состояния на 11 viewport, включая 844×390. Typography проверил 53 маршрута и 440 состояний на изолированных frontend fixtures. |
| `node scripts/verify-responsive.mjs --navigation-only` | `PASS`, CDP touch подтверждает нативную прокрутку таблицы без перехода, защиту ползунка и выбор раздела намеренным жестом. |

Повтор 19 браузерных скриптов после исправления `AppMenu` занял 3 мин 8 с при параллелизме 4. Результаты прогона — `apps/frontend/screenshots/verify-timings.json`.

## Education с реальным API

Мутации разрешались только после сверки nonce напрямую на QA Core и через frontend proxy. Использовались отдельные PostgreSQL, Redis и SeaweedFS с временными идентификаторами. [Guard](../../apps/frontend/scripts/core-e2e-guard.mjs)

| Сценарий | Доказательство |
| --- | --- |
| Teacher | `PASS`, запись ученика, посещаемость, оценка, карточка и журнал. |
| Student | `PASS` на полном повторе. Проверены повторный вход, приватные документы и байты SeaweedFS, домашняя работа и ревью, Typing, Logic и достижения. Первый прогон остановился по таймауту повторного входа в карточку задания в dev preview. |
| Parent RU/EN | `PASS`, сохранение выбранного ребёнка, языка, URL и документа при смене языка и обновлении контекста. |
| Parent, отзыв и переключение | `PASS` на полном повторе в Chromium. Пройдены приглашение, отказ при повторном использовании, независимый выбор двух детей, отказ для несвязанного ребёнка и переход к оставшемуся ребёнку после отзыва. |
| DeviceManagement | `PASS`, регистрация, heartbeat, назначение, отмена и журнал. |
| Classroom | `PASS`, lifecycle, persistent recovery, повтор подтверждения, реальные байты SeaweedFS и переназначение с синтетическим Agent. |
| Storage | `PASS`, 4 viewport, байты после восстановления SeaweedFS, отмена и повтор, исчерпание квоты, истечение подписанной ссылки, повторное подтверждение, конфликт версий и отказ в доступе к чужому документу. |
| Общий сценарий Education | `PASS`, 11 подтверждённых состояний с реальным API. Отдельно проверены RU/EN, обе темы на 393 и 1440 px, учебная группа, вложенный урок, профиль, переписка, материалы и права доступа. Результаты прогона — `apps/frontend/screenshots/education-real/results.json` |

[Визуальный отчёт](FRONTEND_09_VISUAL_REGRESSION.md) · [Инвентаризация](FRONTEND_09_UI_INVENTORY.md)

Локальный QA worktree, его Docker-проект, тестовые volumes и временные каталоги сборок удалены. Снимки и отчёты сохранены в `apps/frontend/screenshots`.

Следующий этап — закрыть матрицу авторизованных Core/Commercial ролей, провести ручную проверку доступности и после отдельного разрешения прогнать GitHub Actions на опубликованном кандидате.
