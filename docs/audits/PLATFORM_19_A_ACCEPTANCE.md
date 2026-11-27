# PLATFORM_19_A Acceptance Report — Activity Engine Final Consolidation

**VERDICT: READY**

Консолидация движка активностей на базе единого канонического **Universal Activity Engine** полностью завершена и подтверждена на изолированном локальном стеке (`PHP 8.3`, реальный `PostgreSQL`, HTTP API, `Vitest`, `vue-tsc`, `vite build`). Второй движок (`Education Activity Engine` / `ActivityHandlerRegistry` и независимые пути записи `AssessmentService` / `HomeworkAttemptLinker` / `PostgresEducationStore` / `LogicTrainer.vue`) окончательно устранён.

---

## 1. Канонический движок

Единый жизненный цикл (`authoring -> versioning -> assignment -> startAttempt -> saveDraft (CAS) -> submitAttempt -> evaluate / review -> publishResults`) для `Assessment`, `Typing`, `Logic`, `Math Sprint` и `Memory` реализован исключительно через каноническое ядро:

- **Backend сервисы и контракты:**
  - `App\Activity\Application\ActivityEngineService`
  - `App\Activity\Application\ActivityPluginRegistry` (`has()`, `get()`, `describeAll()`)
  - `App\Activity\Application\ActivityPluginInterface` (`platform-assessment`, `platform-typing`, `platform-logic`, `platform-math-sprint`, `platform-memory`)
  - `ActivityEngineService::bootstrapPluginActivity()` и HTTP-маршрут `POST /api/activity/plugins/{id}/bootstrap`
  - `ActivityEngineService::invalidateAttempt()` для отзыва попыток без записи в legacy-таблицы
- **Каноническая схема БД (`activity.*`):**
  - `activity.items`
  - `activity.versions`
  - `activity.assignments`
  - `activity.attempts`
  - `activity.question_bank`
- **Frontend:**
  - `apps/frontend/src/modules/activity/ActivityRunnerView.vue`
  - `apps/frontend/src/modules/activity/activityApi.ts` (`bootstrapPlugin`, `startAttempt`, `saveDraft`, `submitAttempt`, `getAttempt`, `analytics`)
  - Плагин-компоненты в `apps/frontend/src/modules/activity/plugins/` (`LogicLabPlugin.vue`, `TypingArenaPlugin.vue`, `MathSprintPlugin.vue`, `MemoryMatrixPlugin.vue`, `AssessmentQuizPlugin.vue`)

---

## 2. Устранённые старые реализации

1. **Удалены legacy-обработчики Education Activity Engine (8 файлов):**
   - `apps/backend/src/Education/Application/ActivityHandlerRegistry.php`
   - `apps/backend/src/Education/Application/ActivityHandler.php`
   - `apps/backend/src/Education/Application/TypingActivityHandler.php`
   - `apps/backend/src/Education/Application/LogicActivityHandler.php`
   - `apps/backend/src/Education/Application/MathSprintActivityHandler.php`
   - `apps/backend/src/Education/Application/AssessmentActivityHandler.php`
   - `apps/backend/src/Education/Application/TypingExerciseSets.php`
   - `apps/backend/src/Education/Activity/ActivityPluginInterface.php`
2. **Устранён второй движок в `EducationService` (`startAttempt`, `finishAttempt`, `saveAttemptState`, `assignActivity`, `revokeActivityAttempt`, `attemptView`, `studentActivityConfig`):**
   - Удалена диспетчеризация через `ActivityHandlerRegistry`; старые маршруты `/api/education/activities/*`, `/api/education/student/activities/*`, `/api/education/student/attempts*` переведены в тонкие адаптеры поверх `ActivityEngineService` + `ActivityPluginRegistry` + `ActivityCode::toCanonicalConfig`.
   - Повторный `finishAttempt` выполняет строгую серверную валидацию полезной нагрузки (включая запрет клиентских `score`/`accuracy`/`wpm`/`passed`), обеспечивает идемпотентный replay при идентичном вводе и `409` при изменении отправленных ответов.
3. **Устранена альтернативная логика в `AssessmentService`:**
   - Зависимости `ActivityEngineService` и `AuthenticatedIdentity` сделаны обязательными во всех публичных методах и конструкторе.
   - Удалены fallback-реализации создания/публикации/прохождения/оценивания/ручного ревью тестов в обход `ActivityEngineService`, а также dual-write проекции в `education.assessments`, `education.assessment_versions`, `education.assessment_attempts` и `education.question_bank`.
   - Добавлено авто-разрешение активного `activity.assignments` для `/api/education/student/assessments/{id}/start` (при отсутствии открытой практики) и совместимость `version -> revision` при сохранении/отправке попытки.
4. **Устранено обратное копирование и legacy-запись в Homework Integration:**
   - Удалён метод `HomeworkAttemptLinker::syncEngineAttemptToLegacyForHomework()`.
   - `HomeworkActivityService::start()`, `HomeworkAttemptLinker::finished()`/`attach()`, `PostgresHomeworkExtensions::activityAssignment()`, `changed('deadline_changed')`, `validateSubmission()` переведены напрямую на `activity.assignments` и `activity.attempts`.
   - В `PostgresHomeworkExtensions::notify()` устранён вызов несуществующей SQL-функции `education.create_notification(...)`; уведомления пишутся в `education.notifications` с каноническими `kind` (`homework_*`), добавлена передача событий в `ParentNotifications::homework()`.
5. **Устранены write/dual-write методы в `PostgresEducationStore` / `EducationStore`:**
   - Удалены `createAttempt`, `attempt`, `finishAttempt`, `guardAssignmentAttempt`, `exerciseSet`, `snapshotAttempt`, `saveAttemptState`, `completeActivityAttempt`, `revokeActivityAttempt`, `createAssignment`, `ensureEngineItemForDefinition`, `syncAssignmentToEngine`, `syncAttemptCreatedToEngine`, `syncAttemptEvaluatedToEngine`.
6. **Устранён старый frontend-движок `LogicTrainer`:**
   - `StudentLogicView.vue` переведён на `activityApi.bootstrapPlugin(..., 'platform-logic')` + `ActivityRunnerView` + `LogicLabPlugin.vue` с поддержкой выбора параметров открытой практики, назначенных заданий, восстановления незавершённых попыток, отображения разбора (`evaluation.breakdown`) и автопривязки к домашнему заданию.
   - Удалены `LogicTrainer.vue`, `LogicTrainer.qa.spec.ts` и legacy-методы (`startLogic`, `finishLogic`, `startHomework`, `attempt`, `saveState`) из `apps/frontend/src/modules/education/activityApi.ts`.

---

## 3. Оставшиеся legacy-потребители (strictly read-only / thin adapters / erasure)

Физически старые таблицы `education.*` на данном этапе сохранены, но **не используются для независимой записи новых активностей, попыток или результатов**:

- **Тонкие HTTP-адаптеры обратной совместимости:**
  - `/api/education/activities/*`, `/api/education/student/activities/*`, `/api/education/student/attempts*` (`EducationAction` -> `EducationService` -> `ActivityEngineService`).
  - `/api/education/assessments*`, `/api/education/student/assessments*`, `/api/education/student/assessment-attempts*` (`AssessmentAction` -> `AssessmentService` -> `ActivityEngineService`).
- **Только чтение исторических данных (`NOT EXISTS` fallback, при отсутствии строки в `activity.*`):**
  - `PostgresEducationStore` (`studentAssignments`, `studentAttempts`, `studentTypingResults`, `bestTyping`, `assignments`, `groupActivityResults`)
  - `ActivityResultView::forAttempt()`
  - `AchievementService` (`recordAttempt`, `reconcile`, `proof`, `progressForStudent`)
  - `AssessmentService::listAssessmentAttempts()`
  - `PersonalDataContributor::collect()` (GDPR-экспорт безопасных полей без приватных ключей ответа)
- **Удаление персональных данных (GDPR erasure):**
  - `ActivityPrivacy`, `HomeworkPrivacy`, `WorkspaceErasureHandler` очищают как канонические `activity.*` таблицы, так и остаточные исторические записи в `education.*`.
- **Прочее:**
  - `apps/backend/psalm-baseline.xml` содержит 3 устаревшие строки baseline для удалённого `ActivityHandlerRegistry` (не участвуют в runtime).

---

## 4. Состояние миграции исторических данных

Созданы и применены идемпотентные SQL-миграции (проверены двойным прогоном `activity:migrate` и `education:migrate` на dev и test БД):

- `apps/backend/migrations/activity/004_activity_final_consolidation.sql`:
  - Идемпотентный перенос с сохранением UUID из:
    - `education.assessments` -> `activity.items`
    - `education.assessment_versions` -> `activity.versions` (+ выставление `active_version_id`)
    - `education.question_bank` -> `activity.question_bank`
    - `education.activity_assignments` -> `activity.assignments`
    - `education.assessment_attempts` и `education.activity_attempts` (включая `activity_results`, `typing_results`, `logic_results`) -> `activity.attempts`
  - Backfill `results_published_at` для завершённых попыток с авто-публикацией.
  - Переключение FK таблиц домашних заданий на `activity.assignments(id)` и `activity.attempts(id)` при наличии схемы ДЗ.
- `apps/backend/migrations/education/024_homework_activity_consolidation.sql`:
  - Seed `platform-math-sprint` в `education.activity_definitions`.
  - Переключение всех 4 FK (`homework_recipients_activity_assignment_id_fkey`, `homework_submissions_selected_activity_attempt_id_fkey`, `homework_submission_versions_activity_attempt_id_fkey`, `homework_attempt_links_attempt_id_fkey`) с `education.activity_*` на `activity.assignments(id)` и `activity.attempts(id)`.
  - Обновление триггера `education.check_homework_integration()` для валидации попыток в `activity.attempts` (`EVALUATED`/`COMPLETED`/`EXPIRED`).
  - Идемпотентный маппинг `core.role_permissions` для `activity.use`/`activity.create`/`activity.assign`/`activity.review` на роли `education.*` и `core.admin` независимо от порядка запуска `activity:migrate` и `education:migrate`.

### Проверка полноты исторической миграции на локальной dev БД (`platform-postgres`)

| Сущность | Источник (legacy) | Каноническая таблица | Неперенесённых записей (`NOT EXISTS`) |
|---|---:|---:|---:|
| Assessments / Items | `education.assessments`: 5 | `activity.items` (`platform-assessment`): 22 | `0` |
| Versions | `education.assessment_versions`: 7 | `activity.versions` (`platform-assessment`): 25 | `0` |
| Question Bank | `education.question_bank`: 2 | `activity.question_bank`: 2 | `0` |
| Assignments | `education.activity_assignments`: 1 | `activity.assignments`: 4 | `0` |
| Attempts | `education.activity_attempts` (1) + `education.assessment_attempts` (1) = 2 | `activity.attempts`: 26 | `0` |
| Homework FKs | — | 4/4 на `activity.assignments(id)` и `activity.attempts(id)` | `0` на legacy |

---

## 5. Результаты проверки записи в старые таблицы

При сквозном выполнении всех канонических HTTP-сценариев на изолированном стеке (`platform-backend-test` `:8081` + `platform-postgres-test`) зафиксированы точные счётчики строк **до** и **после**:

| Устаревшая таблица | Новых строк после всех сценариев (delta) |
|---|---:|
| `education.activity_attempts` | `0` |
| `education.activity_results` | `0` |
| `education.typing_results` | `0` |
| `education.logic_results` | `0` |
| `education.activity_assignments` | `0` |
| `education.assessments` | `0` |
| `education.assessment_versions` | `0` |
| `education.assessment_attempts` | `0` |
| `education.question_bank` | `0` |

---

## 6. Фактически выполненные проверки

1. **PHPUnit (целевые Foundation & Activity наборы на реальном PostgreSQL `platform-postgres-test`, выполнен после финального редактирования кода):**
   - Команда: `vendor/bin/phpunit --bootstrap tests/bootstrap.php tests/Foundation/ActivityEngineHandlersTest.php tests/Foundation/ActivityPluginTest.php tests/Foundation/AssessmentDomainTest.php tests/Foundation/ActivityEngineTest.php tests/Foundation/HomeworkActivitiesTest.php tests/Foundation/HomeworkWorkflowTest.php tests/Foundation/HomeworkPrivacyTest.php tests/Foundation/ParentAccessTest.php`
   - Результат: **`OK (76 tests, 700 assertions)`** (0 failures, 0 errors, 0 skipped).
2. **Сквозная приёмка по реальному HTTP API + PostgreSQL (`platform-backend-test` `:8081`):**
   - Пункт 1: Создание вопроса в `POST /api/activity/question-bank`, создание/публикация теста (`/api/education/assessments`), назначение на группу (`/api/activity/items/{id}/assignments`), старт/сохранение/отправка студентом (`/api/education/student/assessments/{id}/start`, `/save`, `/submit`) — `200`, `passed: true`.
   - Пункт 2: Назначение и прохождение `platform-typing` (`/api/education/activities/platform-typing/assignments`, `/student/activities/platform-typing/attempts`, `/student/attempts/{id}/finish`) — `200`, `correctChars: 25`.
   - Пункт 3: Прохождение `platform-logic` через канонический движок (`POST /api/activity/plugins/platform-logic/bootstrap`, `/api/activity/items/{id}/attempts`, `PUT /api/activity/attempts/{id}/draft` CAS, `POST /api/activity/attempts/{id}/submit`) — `200`, `passed: true`.
   - Пункт 4–5: Создание/публикация typing-ДЗ, старт через ДЗ (`/student/homework/{id}/activity/start`), завершение, сдача (`/student/homework/{id}/submit`), принятие преподавателем (`/homework/submissions/{id}/review`, `decision: accepted`, `resultStatus: mastered`) -> подтверждены `education.achievement_unlocks` (`5`), `education.activity_journal_sync` (`4`), `education.homework_attempt_links` (`1`).
   - Пункт 6: Чтение `GET /api/activity/question-bank`, `GET /api/education/student/attempts` + SQL-проверка 0 неперенесённых записей в dev БД.
   - Пункт 7: Отказ чужому ученику при попытке чтения/отправки попытки по Activity Engine и Education API — HTTP `[403, 403, 404]`.
   - Пункт 8: Нулевой прирост (`0`) во всех 9 legacy-таблицах.
3. **Frontend checks (`apps/frontend`, после финальных правок frontend):**
   - `npm --prefix apps/frontend run typecheck` (`vue-tsc --noEmit` + `tsc --noEmit`) — exit `0`.
   - `npm --prefix apps/frontend run build` (`vite build`, 823 modules) — exit `0`.
   - `npx vitest run` (из `apps/frontend`, 11 целевых `.spec.ts` файлов для `StudentLogicView`, `educationHttp`, `AssessmentStudio`, `GroupActivitiesView`, `AchievementPages`, `HomeworkViews`, `liveContract`, `homework/api`, `ParentActivities`, `TypingArenaPlugin`, `typingEngine`) — **`Test Files 11 passed (11), Tests 41 passed (41)`**.
4. **DBML валидация & статический аудит:**
   - `npm run check:dbml --prefix scripts/documentation` — PASS по всем 11 схемам (примечание: статический экспорт `Education_v1.dbml` не перезаписывался; физические FK в БД переключены миграцией `024`).
   - `git grep -n "ActivityHandlerRegistry|LogicTrainer|syncEngineAttemptToLegacyForHomework|create_notification" apps/` — 0 совпадений в `src/`, `config/` и `migrations/` (только 3 исторические записи в `apps/backend/psalm-baseline.xml`).

---

## 7. Изменённые файлы

### Созданные
- `apps/backend/migrations/activity/004_activity_final_consolidation.sql`
- `apps/backend/migrations/education/024_homework_activity_consolidation.sql`
- `apps/frontend/src/modules/education/student/StudentLogicView.qa.spec.ts`
- `docs/audits/PLATFORM_19_A_ACCEPTANCE.md`

### Изменённые
- `apps/backend/config/common/activity-routes.php`
- `apps/backend/config/common/di/activity.php`
- `apps/backend/config/common/di/education.php`
- `apps/backend/src/Activity/Api/ActivityAction.php`
- `apps/backend/src/Activity/Application/ActivityEngineService.php`
- `apps/backend/src/Activity/Application/ActivityPluginRegistry.php`
- `apps/backend/src/Activity/Infrastructure/Plugins/LogicActivityPlugin.php`
- `apps/backend/src/Education/Api/EducationAction.php`
- `apps/backend/src/Education/Api/HomeworkIntegrationAction.php`
- `apps/backend/src/Education/Application/AchievementService.php`
- `apps/backend/src/Education/Application/ActivityCode.php`
- `apps/backend/src/Education/Application/ActivityResultView.php`
- `apps/backend/src/Education/Application/AssessmentService.php`
- `apps/backend/src/Education/Application/EducationService.php`
- `apps/backend/src/Education/Application/EducationStore.php`
- `apps/backend/src/Education/Application/HomeworkActivityService.php`
- `apps/backend/src/Education/Application/HomeworkAttemptLinker.php`
- `apps/backend/src/Education/Application/HomeworkService.php`
- `apps/backend/src/Education/Application/Input.php`
- `apps/backend/src/Education/Infrastructure/ActivityPrivacy.php`
- `apps/backend/src/Education/Infrastructure/EducationActivityJournalAdapter.php`
- `apps/backend/src/Education/Infrastructure/HomeworkPrivacy.php`
- `apps/backend/src/Education/Infrastructure/PersonalDataContributor.php`
- `apps/backend/src/Education/Infrastructure/PostgresEducationStore.php`
- `apps/backend/src/Education/Infrastructure/PostgresHomeworkExtensions.php`
- `apps/backend/src/Education/Infrastructure/PostgresHomeworkStore.php`
- `apps/backend/src/Education/Infrastructure/WorkspaceErasureHandler.php`
- `apps/backend/tests/Foundation/ActivityEngineFixture.php`
- `apps/backend/tests/Foundation/ActivityEngineHandlersTest.php`
- `apps/backend/tests/Foundation/ActivityEngineTest.php`
- `apps/backend/tests/Foundation/ActivityPluginTest.php`
- `apps/backend/tests/Foundation/AssessmentDomainTest.php`
- `apps/backend/tests/Foundation/HomeworkActivitiesTest.php`
- `apps/backend/tests/Foundation/ParentAccessTest.php`
- `apps/frontend/src/modules/activity/ActivityRunnerView.vue`
- `apps/frontend/src/modules/activity/activityApi.ts`
- `apps/frontend/src/modules/activity/plugins/LogicLabPlugin.vue`
- `apps/frontend/src/modules/education/activityApi.ts`
- `apps/frontend/src/modules/education/educationHttp.spec.ts`
- `apps/frontend/src/modules/education/student/StudentLogicView.vue`

### Удалённые
- `apps/backend/src/Education/Activity/ActivityPluginInterface.php`
- `apps/backend/src/Education/Application/ActivityHandler.php`
- `apps/backend/src/Education/Application/ActivityHandlerRegistry.php`
- `apps/backend/src/Education/Application/AssessmentActivityHandler.php`
- `apps/backend/src/Education/Application/LogicActivityHandler.php`
- `apps/backend/src/Education/Application/MathSprintActivityHandler.php`
- `apps/backend/src/Education/Application/TypingActivityHandler.php`
- `apps/backend/src/Education/Application/TypingExerciseSets.php`
- `apps/frontend/src/modules/education/student/LogicTrainer.vue`
- `apps/frontend/src/modules/education/student/LogicTrainer.qa.spec.ts`
