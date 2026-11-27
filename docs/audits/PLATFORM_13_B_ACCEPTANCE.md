# PLATFORM_13_B — Unified Assessment Runner Acceptance Report

**Status:** `ACCEPTED`  
**Date:** 2026-11-27  
**Scope:** Единый механизм назначения и прохождения тестов на базе Universal Activity Engine (`ActivityEngineService` + `ActivityRunnerView.vue` / `AssessmentRunnerPlugin.vue`).

---

## 1. Канонический источник данных и архитектура

- **Канонические таблицы (PostgreSQL `activity` schema):**
  - `activity.items` — описания тестов (`plugin_code = 'platform-assessment'`).
  - `activity.versions` — неизменяемые опубликованные версии тестов (`time_limit_seconds`, `max_attempts`, `passing_score_percent`, `reveal_answers_policy`, `config`, `content`).
  - `activity.assignments` — назначения тестов (`target_type` `'group' | 'student' | 'person' | ...`, `version_id`, `opens_at`, `due_at`, `allow_late`, `revoked_at`).
  - `activity.attempts` — попытки, черновики ответов, CAS `revision`, `seed`, серверный `expires_at`, `idempotency_key`, оценки и `evaluation`.
  - `activity.attempt_events` — аудит переходов состояний попытки (`CREATED -> IN_PROGRESS -> EVALUATED / PENDING_REVIEW / EXPIRED`).
- **Канонический backend-сервис:** `App\Activity\Application\ActivityEngineService` (`apps/backend/src/Activity/Application/ActivityEngineService.php`) с плагином `AssessmentActivityPlugin` и доменным адаптером `EducationActivityDomainPolicy`.
- **Единый frontend-runner:** `ActivityRunnerView.vue` + `AssessmentRunnerPlugin.vue` (`apps/frontend/src/modules/activity/`).
- **Серверно-авторитетный таймер:**
  - `ActivityEngineService::getAttempt()` и `saveDraft()` возвращают `serverNow` и `remainingSeconds`, вычисленные на сервере относительно `expires_at`.
  - `ActivityRunnerView.vue` привязывает обратный отсчёт к монотонным часам `performance.now()`, благодаря чему изменение системного времени клиента и перезагрузка страницы не сбрасывают лимит времени.
  - По истечении времени (`expires_at < now()`) сервер переводит попытку в `EXPIRED`, оценивая **только ранее сохранённые в БД ответы**, и отклоняет поздние черновики (`409 attempt_expired`).

---

## 2. Устранённые дубликаты и делегирование

1. **Единый Assessment Runner на frontend:**
   - `apps/frontend/src/modules/education/assessment/StudentAssessmentRunner.vue` переведён в тонкую обёртку над `ActivityRunnerView.vue` (устранён дублирующий UI-движок и прямые вызовы legacy-эндпоинтов из раннера).
   - `AssessmentRunnerPlugin.vue` объединён в один универсальный раннер с прогресс-баром, навигатором по вопросам, отметками отвеченных/пропущенных/отмеченных вопросов, статусом сохранения, offline-баннером с локальным IndexedDB-черновиком (`draftStorage.ts`), защитой от конфликтов между вкладками (`BroadcastChannel` + CAS `revision`), подтверждением завершения, адаптивной вёрсткой, поддержкой RU/EN и светлой/тёмной тем.
   - В `AssessmentListView.vue` добавлено назначение опубликованного теста группе или ученику с периодом доступности (`opensAt`, `dueAt`, `allowLate`) через `activityApi.assignActivity`.
2. **Делегирование legacy Education API в `ActivityEngineService`:**
   - `AssessmentService.php` (`listStudentAssessments`, `startStudentAttempt`, `getStudentAttempt`, `saveStudentAttempt`, `submitStudentAttempt`, `listAssessmentAttempts`, `reviewAttempt`) делегирует выполнение в `ActivityEngineService` (с сохранением fallback-чтения только для исторических записей в `education.assessment_attempts`, отсутствующих в `activity.attempts`).
   - `ActivityResultView.php` дополнен чтением результатов из `activity.attempts`.
   - В `apps/backend/config/common/di/education.php` удалена дублирующая регистрация `ActivityDomainPolicyInterface` (конфликтовавшая с `activity.php`).

---

## 3. Реально проверенные сценарии

| Проверка | Результат | Детали |
| --- | --- | --- |
| `npx vitest run src/modules/education/assessment/AssessmentStudio.spec.ts` | **PASS (5/5)** | Включая тест `StudentAssessmentRunner` поверх `ActivityRunnerView` + `AssessmentRunnerPlugin` |
| `npm run check:i18n` | **PASS** | 100% паритет RU/EN словарей (включая новые ключи `activityI18n.ts`) |
| `phpunit tests/Foundation/AssessmentDomainTest.php` | **PASS (14/14)** | Сценарии доменов оценивания и совместимости |
| `node apps/frontend/scripts/e2e-platform13-b-runner.mjs` (Live Stack + Chromium) | **PASS (11/11)** | 1) Создание и публикация теста; 2) Назначение с `opensAt`/`dueAt`; 3) Запрет доступа постороннему (`403`); 4) Старт попытки, маскирование `answerKey`/`explanation`, `serverNow` и `remainingSeconds`; 5) Привязка к `v1` после публикации `v2`; 6) CAS `revision` конфликт (`409`); 7a-c) Перезагрузка страницы в браузере с восстановлением ответов, имитация потери сети (`setOffline(true)` -> offline-баннер -> `setOffline(false)` -> авто-синхронизация), подтверждение отправки и отображение результата; 8) Идемпотентная повторная отправка (`idempotencyKey`); 9) Серверное истечение времени (`EXPIRED` + оценка сохранённых ответов); 10) Лимит попыток (`409 max_attempts_reached`); 11) Делегирование legacy `/api/education/assessments/{id}/attempts` |

---

## 4. Оставшиеся ограничения

- Полная регрессия всего репозитория не запускалась (согласно границам задачи).
- В `npm run typecheck` присутствуют предсуществующие ошибки типов в сторонних для данной задачи файлах (`UnifiedAttemptResultPanel.vue`, `TypingArenaPlugin.vue`, `AssessmentResultsWorkspaceView.vue`); в файлах, изменённых в рамках `PLATFORM_13_B`, ошибок TypeScript нет.
- Fallback-чтение в `education.assessment_attempts` сохранено исключительно для исторических попыток, созданных до миграции в `activity.attempts`.
