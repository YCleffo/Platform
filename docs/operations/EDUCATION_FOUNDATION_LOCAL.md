# Education foundation: локальная приёмка, 13 ноября 2026

## 1. VERDICT

`FOUNDATION_LOCAL_VERIFIED_WITH_LIMITATIONS`.

Главный учебный сценарий пройден целиком через браузер и настоящие Core/Education API с PostgreSQL: программа → модуль → урок программы → группа → новый Core Person/ученик → назначение программы → занятие → посещаемость → завершение → результат → сохранённый прогресс группы и ученика. Отдельный вход ученика открывает собственный кабинет. Демонстрационные API-ответы и ручная правка учебных строк SQL для этого сценария не использовались.

Это локальная приёмка фундамента. Нативный вход Windows и LogonUI не подтверждены. HTTP p95 ещё превышает ориентир 300–500 мс. Релизный аудит не выполнялся.

## 2. Реальные исполнители

Идентификаторы проверены по `session_meta` текущего дерева сессий, а не придуманы по ролям.

| Исполнитель | Область | Session/thread ID |
| --- | --- | --- |
| `/root` | Координация, fixtures, совместимость миграций, браузер, интеграционные исправления, OpenAPI и отчёт | `01a1d466-111f-7260-abd6-6d698fd777b2` |
| `/root/backend_education` / Pascal | Education API, PostgreSQL, Core Person, назначения и прогресс | `01a1d466-83c4-7341-9d2a-509e6258d31b` |
| `/root/frontend_education` / Boole | Компоновка, формы, календарь, посещаемость, кэш | `01a1d466-ae7c-7041-94db-04130f0bd9f0` |
| `/root/windows_qa` / Arendt | Agent, IPC, Credential Provider, VMware HTTPS и матрица VM | `01a1d466-e3b2-7613-9703-b28c2862e5cb` |

После сохранения реализации сессии трёх исполнителей остановились по лимиту использования. Координатор продолжил интеграцию, исправил обнаруженные дефекты и выполнил окончательные проверки.

## 3. Найденные дефекты

- В Education отсутствовала цепочка каталога программ, учебных модулей и уроков; расписание и результаты существовали отдельно.
- Единоличный `owner_account_id` препятствовал совместной работе назначенных преподавателей. Владение сохранено для совместимости, доступ дополнен явными назначениями и проверкой группы; действующий автор отдельно сохраняется в аудите.
- Добавление ученика требовало заранее созданного Core Person. Добавлен ограниченный прикладной сценарий создания и зачисления, без глобальных административных прав Core.
- Компоновка не использовала полноценную настольную боковую навигацию; представления повторно загружали данные, а обновление уничтожало текущий экран.
- В Windows-контуре найдены некорректные C++ escape-последовательности Named Pipe, устаревший plain-text IPC консольного UI, неверный путь установки и отсутствие полноценной установки COM provider. После reboot состояние ACTIVE требовало повторной проверки WTS.
- Интеграционный браузерный прогон дополнительно выявил неполную пагинацию ответа программ, повреждённые русские подписи и синтаксическую ошибку нового календаря. Исправлено.
- Вход ученика был связан с преподавательским заголовком и переходом на `/today`. Исправлены маршрутизация после входа и ограниченный student scope заголовка.
- Уточнена инвалидация связанных материалов, непрочитанного заголовка и собственного прогресса. Старый тест очистки имел глобальные счётчики; они ограничены проверяемым субъектом/receipt, чтобы чужие локальные fixtures не влияли на результат.

Старые исходники изучены по пути `C:\OSPanel6\OSPanel\home\platform.local`; запрошенные ZIP не были доступны. Изображения использованы по указанному пользователем пути `C:\OSPanel6\OSPanel\home\MyPortfolioNew\docs\Prototype\Platform`. Сопоставление старых сценариев описано в [архитектуре](../architecture/EDUCATION_FOUNDATION.md).

## 4. Реализованные функции

Каталог программ, упорядоченные модули/уроки, редактирование/архивация с ревизиями, неизменяемые назначения группе, связь расписания с уроком программы, вычисляемый прогресс и явные преподаватели группы. Создание ученика вызывает Core `CreatePerson` и добавление членства в одной транзакции с зачислением; существующего человека по-прежнему можно выбрать.

В интерфейсе доступны каталог и редактор программы/модуля, группа с составом/программой/преподавателями, карточка и прогресс ученика, день/неделя с временной сеткой, недельная матрица посещаемости с оптимистическим сохранением, журнал, результаты, материалы и кабинет ученика. Desktop использует sidebar/context header, mobile — общий `AppBottomNav`, включая кабинет ученика. Сохранены общие токены, темы, фокус и semantic accent palette.

Добавлены session/org cache, объединение параллельных чтений, предзагрузка соседних разделов, сохранение предыдущих данных при обновлении, защита от поздних ответов после logout/смены организации, инвалидация зависимых данных и измерения. HTTP `no-store` сохранён.

Явный локальный seed создаёт базовые две группы, четырёх учеников, преподавателей двух организаций, программу с двумя модулями/четырьмя уроками, реальные занятия/посещаемость/результаты, материал, активированный аккаунт ученика и два offline тестовых компьютера. Он идемпотентен и сохраняет последующие изменения. Браузерные проверки дополнительно создают собственные записи в этой изолированной БД.

## 5. Новые миграции

| Миграция | Назначение |
| --- | --- |
| `008_curriculum_foundation.sql` | Каталог и ревизии, group teachers, снимки назначений, ссылки занятий, индексы/FK/checks, backfill владельцев и ограниченное право создания ученика |
| `009_curriculum_integrity.sql` | PostgreSQL trigger проверяет, что урок принадлежит снимку именно этой группы/организации |

Применённые старые файлы не менялись. Миграции проверены на чистом изолированном окружении и повторно по checksum. Отдельный upgrade-прогон на старой заполненной схеме подтвердил неизменность Core identities/organization/group/roster/lesson/attendance/result и backfill владельца. БД доказательства: `education_upgrade_20261113_145935_8f79c1` внутри отдельного локального PostgreSQL. Существующие базы не удалялись/не пересоздавались.

Рабочая `platform` не мигрировалась и не наполнялась seed. Перед запуском новой версии на рабочей установке нужно штатно применить Education migrations от migration owner и повторить существующее provisioning прав runtime-роли. Production/fast image не пересобирался для развёртывания.

## 6. Изменённые файлы

Основные файлы текущей работы:

- Backend: Education `CurriculumAction`, `CurriculumService`, `CurriculumStore`, `PostgresCurriculumStore`; `EducationAction`, `EducationService`, `Scope`, `PostgresEducationStore`, `PostgresMutation`, Configuration/DI/routes; новые 008/009; contributors/erasure integration; `CurriculumFoundationTest` и scoped assertions `WorkspaceTest`.
- Frontend: `EducationLayout`, `EducationLoginView`, `CalendarView`/`CalendarTimeGrid`, `AttendanceView`, `ProgramsView`/`ProgramView`, group program/teachers panels, student curriculum progress, teacher create/detail pages, student layout/progress, `api`, `cache`, `workspace`, `resources`, i18n/styles/routes, boundary/cache tests и общий HTTP `no-store`.
- Контракт: `contracts/openapi/education.yaml`, 18 новых операций и схемы curriculum/lesson binding.
- Agent: `AgentLoop`, Service/UI entrypoints, `StateDirectorySecurity`, provider C++ source, VM-only authorization/guard/install/test/uninstall scripts, IPC smoke, README и Windows acceptance.
- Локальная проверка: `compose.education-local.yaml`, `scripts/dev/education-local.py`, `scripts/dev/education-smoke.mjs`, `scripts/dev/education-contract-smoke.mjs`, `scripts/dev/education/*`, `scripts/vmware/*`, архитектура и этот отчёт.

Рабочее дерево изначально содержало значительные незакоммиченные DeviceManagement/Storage/Agent/frontend изменения. Полный status в п. 12 включает их; весь diff не приписывается текущей работе.

## 7. Адресные проверки

| Проверка | Результат |
| --- | --- |
| Vue typecheck | PASS |
| Vite build с `VITE_EDUCATION_ENABLED=1` | PASS |
| Frontend: cache/resources/API boundary/HTTP/status/student layout | 42 tests, 6 files, PASS |
| CurriculumFoundationTest | 5 tests / 38 assertions, PASS; реальная PostgreSQL и rollback |
| StudentPortalTest | 5 tests / 60 assertions, PASS |
| Education group/lesson/history/privacy regression (пять выбранных методов WorkspaceTest) | 5 tests / 141 assertions, PASS |
| Upgrade 001–007 → 008/009 с сохранёнными старыми фактами | PASS |
| Повторный migrate и seed | PASS; нет дубликатов базовых fixtures/сброса пользовательских правок |
| OpenAPI references + шесть реальных HTTP DTO | PASS; программы/детали/назначения/прогресс/преподаватели/занятие; no-store |
| Узкий браузерный сценарий с реальными Core auth/API/PostgreSQL | PASS |
| Ширины 375/768/1280/1920, light/dark | PASS; горизонтального переполнения страницы нет, weekly grid прокручивается внутри своего контейнера |
| Axe на выбранных calendar/group/program/accent состояниях | 0 violations |
| Клавиатурный skip-to-content, длинное имя, реальный 404, пустая организация, saving state | PASS |
| Agent.Tests / RegressionTests | 28 / 20 PASS, безопасный test session adapter |
| Service/UI/HTTPS bridge targeted .NET builds | PASS, 0 warnings/errors |
| PowerShell VM scripts parser | PASS; скрипты не устанавливались на host |
| Python launcher compile / PHP fixture syntax / targeted diff check | PASS |

Полный CI, полный E2E и все тесты репозитория не запускались.

## 8. Фактическая производительность

До — отдельный экспорт frontend из Git HEAD на 5179; после — текущий frontend на 5178. Оба обращаются к изолированному API 8088. Исходное грязное рабочее дерево не выдаётся за снятый runtime baseline. Запуски разделены перерывом сессии; это малая локальная выборка, а не промышленный benchmark.

| Измерение | До | После |
| --- | ---: | ---: |
| Возврат на прогретый Today, медиана (5 возвратов) | 335,9 мс | 36,6 мс |
| Возврат на Today, эмпирический p95 | 1346,3 мс | 40,5 мс |
| HTTP запросы за эти 5 возвратов | 20 | 0 |
| `/groups`, прогретая выборка 11 чтений: медиана | 291,0 мс | 256,6 мс |
| `/groups`, эмпирический p95 | 1950,9 мс | 1683,7 мс |
| Следующий paint после Save result: кнопка disabled | Не измерялось | 4,0 мс |

Цели реакции и прогретого переключения достигнуты в проверенном сценарии. Ориентир HTTP p95 300–500 мс не достигнут. Выбросы dev backend не скрываются; их источник не установлен этим прогоном. Первое чтение исключено только из явно обозначенной прогретой HTTP выборки. Сырые результаты: `runtime/education-local/smoke/baseline.json`, `current.json`, `metrics.json`; снимки экранов лежат рядом.

## 9. Ручная проверка в браузере

Изолированный интерфейс: `http://127.0.0.1:5178/education/login`; API: `http://127.0.0.1:8088`. Локальные credentials находятся в игнорируемом Git `runtime/education-local/data/access.json`; пароль в отчёт не включён.

Преподаватель: `/programs` → создать программу/модуль/урок; `/groups/new` → создать группу; добавить нового/существующего ученика; назначить программу и коллегу; создать занятие со ссылкой на урок; после начала отметить посещаемость; завершить; сохранить результат; открыть прогресс и журнал. Коллега видит только назначенную группу. Outsider демонстрирует первичную настройку пустой организации. Student account открывает `/student/home` и `/student/progress`; преподавательские endpoint по student ID запрещены.

Команды запуска, проверки и безопасной остановки — в [README окружения](../../scripts/dev/education/README.md). `stop` сохраняет контейнеры, БД, тома и файлы. Seed не запускается автоматически при старте рабочей установки.

## 10. Windows: что реально исполнено

| Класс | Статус и граница доказательства |
| --- | --- |
| `UNIT_VERIFIED` | PASS: 28 + 20 безопасных Agent tests; test adapters/temp documents |
| `API_VERIFIED` | PASS: 6 HTTP сценариев через production Device/Storage actions и credential middleware, отдельная `classroom02_test`; teacher control — CLI |
| `IPC_VERIFIED` | PASS: настоящий disposable Named Pipe service с test adapter, JSON/status/session binding/malformed input/identity injection |
| `STORAGE_RECOVERY_VERIFIED` | PASS на HTTP/PostgreSQL/test-profile уровне: A → B → A, сохранённые байты, изоляция B, version conflict/keep-server/local copy и восстановление обеих версий |
| `VM_NATIVE_VERIFIED` | `NOT_VERIFIED` |
| `PRELOGIN_VERIFIED` | `NOT_VERIFIED` |

Нативных операций пользователей/профилей, регистрации COM provider, смены firewall/trust на машине разработчика не выполнялось. Стандартные Windows Credential Providers не отключались. TestSessionManager не считается Windows-входом.

MSVC v143 x64: явная попытка build завершилась MSB4019, отсутствует `Microsoft.Cpp.Default.props`/C++ toolchain. .NET build не заменяет C++ build.

Подготовлены реальные guarded VM-only install/check/uninstall, short-lived MachineGuid permit, bridge HTTPS на выбранном VMware adapter, SAN/certificate generation и guest TCP/chain/name/expiry проверки. TLS bypass отсутствует. Живые VMware HTTPS, сертификаты/trust/firewall и LogonUI пока не проверены. Полный воспроизводимый сценарий и матрица A/B/reboot/offline/cleanup: [VM_ACCEPTANCE.md](../../apps/agent/VM_ACCEPTANCE.md).

## 11. Оставшиеся проверки и границы

- Нужна выделенная разрешённая Windows VM и MSVC v143 x64/Windows SDK для нативной сборки и полной матрицы входа, Known Folders/SID/ACL, выхода, reboot, recovery и prelogin.
- Нужна отдельная диагностика HTTP tail latency; текущий dev p95 выше ориентира.
- Обновление рабочей БД/runtime и развёртывание fast image не выполнялись; текущая локальная приёмка использует отдельную реальную БД.
- Перевод между группами разных владельцев сохраняет прежнее явное ограничение `group_owner_mismatch`; совместная работа внутри назначенной группы подтверждена, чужая история не переносится автоматически.
- ZIP архивы не были найдены; доступные распакованные старые исходники и указанные изображения исследованы.

Коммитов, push, merge, reset/clean не выполнялось. Существующие БД/volumes/пользователи/файлы не удалялись и не пересоздавались. Рабочие данные не наполнялись fixtures.

## 12. git status --short

Снимок включает изначальные изменения и текущую работу:

```text
 M apps/agent/Platform.Agent.Service/Program.cs
 M apps/agent/Platform.Agent.Tests/FakeAgentServer.cs
 M apps/agent/Platform.Agent.Tests/Program.cs
 M apps/agent/Platform.Agent.Ui/Platform.Agent.Ui.csproj
 M apps/agent/Platform.Agent.Ui/Program.cs
 M apps/agent/Platform.Agent.sln
 M apps/agent/Platform.Agent/AgentApiClient.cs
 M apps/agent/Platform.Agent/AgentLoop.cs
 M apps/agent/Platform.Agent/ManifestEngine.cs
 M apps/agent/Platform.Agent/SessionManagers.cs
 M apps/agent/Platform.Agent/SessionStateMachine.cs
 M apps/agent/Platform.Agent/StateDirectorySecurity.cs
 M apps/agent/Platform.Agent/StateJournal.cs
 M apps/agent/README.md
 M apps/backend/config/common/device-routes.php
 M apps/backend/config/common/di/education.php
 M apps/backend/config/common/di/storage.php
 M apps/backend/config/common/education-routes.php
 M apps/backend/config/common/storage-routes.php
 M apps/backend/config/console/commands.php
 M apps/backend/src/Core/Privacy/Api/PersonalDataExportAction.php
 M apps/backend/src/Core/Privacy/Application/PersonalDataExportService.php
 M apps/backend/src/Core/Privacy/Application/PersonalDataExporter.php
 M apps/backend/src/DeviceManagement/Api/DeviceAction.php
 M apps/backend/src/DeviceManagement/Application/DeviceAccess.php
 M apps/backend/src/DeviceManagement/Application/DeviceService.php
 M apps/backend/src/DeviceManagement/Application/DeviceStore.php
 M apps/backend/src/DeviceManagement/Application/SessionTransitions.php
 M apps/backend/src/DeviceManagement/Infrastructure/Configuration.php
 M apps/backend/src/DeviceManagement/Infrastructure/PostgresDeviceStore.php
 M apps/backend/src/Education/Api/EducationAction.php
 M apps/backend/src/Education/Application/EducationService.php
 M apps/backend/src/Education/Application/Scope.php
 M apps/backend/src/Education/Infrastructure/Configuration.php
 M apps/backend/src/Education/Infrastructure/PersonalDataContributor.php
 M apps/backend/src/Education/Infrastructure/PostgresEducationStore.php
 M apps/backend/src/Education/Infrastructure/PostgresMutation.php
 M apps/backend/src/Education/Infrastructure/WorkspaceErasureHandler.php
 M apps/backend/src/Storage/Api/StorageAction.php
 M apps/backend/src/Storage/Application/StorageService.php
 M apps/backend/src/Storage/Application/StorageStore.php
 M apps/backend/src/Storage/Infrastructure/Configuration.php
 M apps/backend/src/Storage/Infrastructure/LocalFilesystemStorageAdapter.php
 M apps/backend/src/Storage/Infrastructure/PostgresStorageStore.php
 M apps/backend/tests/Education/WorkspaceTest.php
 M apps/backend/tests/Unit/Core/Security/PersonalDataExporterTest.php
 M apps/frontend/scripts/device-storage-contract.test.mjs
 M apps/frontend/src/modules/education/CalendarView.vue
 M apps/frontend/src/modules/education/CreateView.vue
 M apps/frontend/src/modules/education/EducationLayout.vue
 M apps/frontend/src/modules/education/EducationLoginView.vue
 M apps/frontend/src/modules/education/GroupView.vue
 M apps/frontend/src/modules/education/LessonView.vue
 M apps/frontend/src/modules/education/MoreView.vue
 M apps/frontend/src/modules/education/PeopleView.vue
 M apps/frontend/src/modules/education/StudentView.vue
 M apps/frontend/src/modules/education/TodayView.vue
 M apps/frontend/src/modules/education/api.spec.ts
 M apps/frontend/src/modules/education/api.ts
 M apps/frontend/src/modules/education/devices/DevicesView.vue
 M apps/frontend/src/modules/education/devices/api.spec.ts
 M apps/frontend/src/modules/education/devices/api.ts
 M apps/frontend/src/modules/education/education.css
 M apps/frontend/src/modules/education/i18n.ts
 M apps/frontend/src/modules/education/resources.spec.ts
 M apps/frontend/src/modules/education/resources.ts
 M apps/frontend/src/modules/education/routes.ts
 M apps/frontend/src/modules/education/student/StudentDocumentsView.vue
 M apps/frontend/src/modules/education/student/StudentLayout.vue
 M apps/frontend/src/modules/education/student/StudentProgressView.vue
 M apps/frontend/src/modules/education/student/storage.ts
 M apps/frontend/src/modules/education/workspace.ts
 M apps/frontend/src/shared/api/http.ts
 M contracts/openapi/core-privacy.yaml
 M contracts/openapi/device-management.yaml
 M contracts/openapi/education.yaml
 M contracts/openapi/storage.yaml
?? apps/agent/Authorize-TestVm.ps1
?? apps/agent/CREDENTIAL_PROVIDER.md
?? apps/agent/Install-Service.ps1
?? apps/agent/Platform.Agent.HttpScenario/
?? apps/agent/Platform.Agent.RegressionTests/
?? apps/agent/Platform.Agent.Ui/MainForm.cs
?? apps/agent/Platform.Agent/AgentIpc.cs
?? apps/agent/Platform.Agent/AgentLogonBroker.cs
?? apps/agent/Platform.Agent/AgentPipeIdentity.cs
?? apps/agent/Platform.Agent/ManagedPaths.cs
?? apps/agent/Platform.Agent/WindowsProfilePrivilegeScope.cs
?? apps/agent/Platform.Agent/WindowsSessionManager.cs
?? apps/agent/Platform.CredentialProvider/
?? apps/agent/Test-Service.ps1
?? apps/agent/TestVmGuard.ps1
?? apps/agent/Uninstall-Service.ps1
?? apps/agent/VM_ACCEPTANCE.md
?? apps/agent/tests/
?? apps/backend/migrations/device_management/003_classroom_lifecycle.sql
?? apps/backend/migrations/device_management/004_session_evidence.sql
?? apps/backend/migrations/education/008_curriculum_foundation.sql
?? apps/backend/migrations/education/009_curriculum_integrity.sql
?? apps/backend/migrations/storage/003_lifecycle_consistency.sql
?? apps/backend/src/Core/Privacy/Application/PagedPersonalDataContributor.php
?? apps/backend/src/Core/Privacy/Application/PersonalDataExportArtifact.php
?? apps/backend/src/DeviceManagement/Application/DeviceAvailableActions.php
?? apps/backend/src/DeviceManagement/Application/SessionEvidence.php
?? apps/backend/src/Education/Api/CurriculumAction.php
?? apps/backend/src/Education/Application/CurriculumService.php
?? apps/backend/src/Education/Application/CurriculumStore.php
?? apps/backend/src/Education/Infrastructure/PostgresCurriculumStore.php
?? apps/backend/src/Storage/Console/CleanupCommand.php
?? apps/backend/src/Storage/Infrastructure/BinaryCleanup.php
?? apps/backend/src/Storage/Infrastructure/LawfulProcessingBasisContributor.php
?? apps/backend/src/Storage/Infrastructure/PersonalDataContributor.php
?? apps/backend/src/Storage/Infrastructure/ProcessingRestrictionContributor.php
?? apps/backend/src/Storage/Infrastructure/RecoveryDependencyContributor.php
?? apps/backend/src/Storage/Infrastructure/RetentionCandidateContributor.php
?? apps/backend/src/Storage/Infrastructure/SpaceErasureHandler.php
?? apps/backend/tests/Classroom/
?? apps/backend/tests/Foundation/CurriculumFoundationTest.php
?? apps/backend/tests/Unit/DeviceManagement/
?? apps/backend/tests/Unit/Storage/
?? apps/backend/tests/classroom-bootstrap.php
?? apps/frontend/src/modules/education/AttendanceView.vue
?? apps/frontend/src/modules/education/CalendarTimeGrid.vue
?? apps/frontend/src/modules/education/GroupCurriculumPanel.vue
?? apps/frontend/src/modules/education/GroupTeachersPanel.vue
?? apps/frontend/src/modules/education/ProgramView.vue
?? apps/frontend/src/modules/education/ProgramsView.vue
?? apps/frontend/src/modules/education/StudentCurriculumProgress.vue
?? apps/frontend/src/modules/education/cache.spec.ts
?? apps/frontend/src/modules/education/cache.ts
?? apps/frontend/src/modules/education/devices/DeviceDetailsPanel.spec.ts
?? apps/frontend/src/modules/education/devices/DeviceDetailsPanel.vue
?? apps/frontend/src/modules/education/student/StudentDocumentsView.spec.ts
?? apps/frontend/src/modules/education/student/StudentLayout.spec.ts
?? apps/frontend/src/modules/education/student/storage.spec.ts
?? compose.education-local.yaml
?? docs/architecture/EDUCATION_FOUNDATION.md
?? docs/operations/EDUCATION_FOUNDATION_LOCAL.md
?? scripts/dev/education-contract-smoke.mjs
?? scripts/dev/education-local.py
?? scripts/dev/education-smoke.mjs
?? scripts/dev/education/
?? scripts/vmware/
```
