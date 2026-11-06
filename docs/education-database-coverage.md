# Покрытие таблиц Education

Генерируется `scripts/documentation/build-education-coverage.py` из сохранённого
каталога PostgreSQL и исходников Education.
Перечислены все физические таблицы схемы `education` и прямые строки
`education.<table>` в PHP-коде. Ссылки показывают место использования,
а не автоматически доказанное полное покрытие.
Прикладные тесты сценариев могут вызывать репозиторий без SQL-строки в самом тесте.

| Схема | Таблицы | Столбцы | Внешние ключи | Проверки CHECK | Индексы | Пользовательские триггеры |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `education` | 15 | 91 | 11 | 12 | 31 | 0 |

| Физическая таблица | Прямые ссылки на исходники (до 3) | Прямые ссылки на тесты (до 3) |
| --- | --- | --- |
| `education.attendance` | [PersonalDataContributor](../apps/backend/src/Education/Infrastructure/PersonalDataContributor.php), [PostgresEducationStore](../apps/backend/src/Education/Infrastructure/PostgresEducationStore.php), [WorkspaceErasureHandler](../apps/backend/src/Education/Infrastructure/WorkspaceErasureHandler.php) | [WorkspaceTest](../apps/backend/tests/Education/WorkspaceTest.php) |
| `education.events` | [PersonalDataContributor](../apps/backend/src/Education/Infrastructure/PersonalDataContributor.php), [PostgresEducationStore](../apps/backend/src/Education/Infrastructure/PostgresEducationStore.php), [RetentionCandidateContributor](../apps/backend/src/Education/Infrastructure/RetentionCandidateContributor.php) | [WorkspaceTest](../apps/backend/tests/Education/WorkspaceTest.php) |
| `education.files` | [EducationAction](../apps/backend/src/Education/Api/EducationAction.php), [PersonalDataContributor](../apps/backend/src/Education/Infrastructure/PersonalDataContributor.php), [PostgresEducationStore](../apps/backend/src/Education/Infrastructure/PostgresEducationStore.php) | [WorkspaceTest](../apps/backend/tests/Education/WorkspaceTest.php) |
| `education.group_students` | [PostgresEducationStore](../apps/backend/src/Education/Infrastructure/PostgresEducationStore.php), [WorkspaceErasureHandler](../apps/backend/src/Education/Infrastructure/WorkspaceErasureHandler.php) | [WorkspaceTest](../apps/backend/tests/Education/WorkspaceTest.php) |
| `education.groups` | [PersonalDataContributor](../apps/backend/src/Education/Infrastructure/PersonalDataContributor.php), [PostgresEducationStore](../apps/backend/src/Education/Infrastructure/PostgresEducationStore.php), [RetentionCandidateContributor](../apps/backend/src/Education/Infrastructure/RetentionCandidateContributor.php) | [WorkspaceTest](../apps/backend/tests/Education/WorkspaceTest.php) |
| `education.lessons` | [PersonalDataContributor](../apps/backend/src/Education/Infrastructure/PersonalDataContributor.php), [PostgresEducationStore](../apps/backend/src/Education/Infrastructure/PostgresEducationStore.php), [RetentionCandidateContributor](../apps/backend/src/Education/Infrastructure/RetentionCandidateContributor.php) | [WorkspaceTest](../apps/backend/tests/Education/WorkspaceTest.php) |
| `education.messages` | [EducationAction](../apps/backend/src/Education/Api/EducationAction.php), [PersonalDataContributor](../apps/backend/src/Education/Infrastructure/PersonalDataContributor.php), [PostgresEducationStore](../apps/backend/src/Education/Infrastructure/PostgresEducationStore.php) | [WorkspaceTest](../apps/backend/tests/Education/WorkspaceTest.php) |
| `education.module_migrations` | [MigrationRunner](../apps/backend/src/Education/Infrastructure/MigrationRunner.php) | Нет прямой SQL-строки; смотреть прикладные тесты сценариев |
| `education.notifications` | [PersonalDataContributor](../apps/backend/src/Education/Infrastructure/PersonalDataContributor.php), [PostgresEducationStore](../apps/backend/src/Education/Infrastructure/PostgresEducationStore.php), [RetentionCandidateContributor](../apps/backend/src/Education/Infrastructure/RetentionCandidateContributor.php) | [WorkspaceTest](../apps/backend/tests/Education/WorkspaceTest.php) |
| `education.privacy_receipts` | [WorkspaceErasureHandler](../apps/backend/src/Education/Infrastructure/WorkspaceErasureHandler.php) | [WorkspaceTest](../apps/backend/tests/Education/WorkspaceTest.php) |
| `education.profiles` | [PersonalDataContributor](../apps/backend/src/Education/Infrastructure/PersonalDataContributor.php), [PostgresEducationStore](../apps/backend/src/Education/Infrastructure/PostgresEducationStore.php), [ProfileCorrection](../apps/backend/src/Education/Infrastructure/ProfileCorrection.php) | [WorkspaceTest](../apps/backend/tests/Education/WorkspaceTest.php) |
| `education.students` | [PersonalDataContributor](../apps/backend/src/Education/Infrastructure/PersonalDataContributor.php), [PostgresEducationStore](../apps/backend/src/Education/Infrastructure/PostgresEducationStore.php), [RetentionCandidateContributor](../apps/backend/src/Education/Infrastructure/RetentionCandidateContributor.php) | [WorkspaceTest](../apps/backend/tests/Education/WorkspaceTest.php) |
| `education.teachers` | [PersonalDataContributor](../apps/backend/src/Education/Infrastructure/PersonalDataContributor.php), [PostgresEducationStore](../apps/backend/src/Education/Infrastructure/PostgresEducationStore.php), [RetentionCandidateContributor](../apps/backend/src/Education/Infrastructure/RetentionCandidateContributor.php) | [WorkspaceTest](../apps/backend/tests/Education/WorkspaceTest.php) |
| `education.thread_members` | [PostgresEducationStore](../apps/backend/src/Education/Infrastructure/PostgresEducationStore.php), [RetentionCandidateContributor](../apps/backend/src/Education/Infrastructure/RetentionCandidateContributor.php), [WorkspaceErasureHandler](../apps/backend/src/Education/Infrastructure/WorkspaceErasureHandler.php) | [WorkspaceTest](../apps/backend/tests/Education/WorkspaceTest.php) |
| `education.threads` | [PostgresEducationStore](../apps/backend/src/Education/Infrastructure/PostgresEducationStore.php), [WorkspaceErasureHandler](../apps/backend/src/Education/Infrastructure/WorkspaceErasureHandler.php) | [WorkspaceTest](../apps/backend/tests/Education/WorkspaceTest.php) |

## Границы владельцев

- Education владеет схемой `education`; учётные записи Core, организации и RBAC
  используются через прикладные интерфейсы, а не через внешние ключи между схемами.
- `education.module_migrations` — отдельный журнал необязательного модуля;
  Механизм миграций Core его не изменяет.
- `education.files` хранит метаданные и SHA-256; байты находятся во внешнем
  `FileStore`, поэтому один `pg_dump` не восстанавливает файлы.
- Модуль устанавливается отдельно и включается через жизненный цикл модуля Core,
  права БД и явные назначения преподавателей.

Точные поля и FK: [справочник](database/SCHEMA_REFERENCE.md).
Каталог и DBML: [база данных](database/README.md).
Границы модуля: [серверная часть Education](architecture/EDUCATION_BACKEND.md).
