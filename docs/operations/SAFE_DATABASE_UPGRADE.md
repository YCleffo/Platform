# Обновление баз данных

Core и Commercial обновляются независимо. Структуру определяют SQL-файлы `apps/backend/migrations/sql` и Doctrine migrations `apps/control-plane/migrations`. Записи истории миграций не заменяют проверку фактического каталога PostgreSQL.

## Порядок

Остановите HTTP и фоновые процессы перед изменением схемы:

```powershell
docker compose --profile control stop backend backend-email-worker backend-scheduler control-plane control-scheduler control-outbox-worker
```

Проверьте доступность актуальной резервной копии и способ восстановления перед необратимыми изменениями. Автоматического создания локальных копий нет. Образы приложения должны соответствовать исходникам миграций; Commercial CLI выполняется из собственного образа.

```powershell
docker compose --profile control up -d --wait postgres control-postgres redis mailpit
python scripts/database/migrate.py core
docker compose --profile control up -d --no-deps control-plane
python scripts/database/migrate.py commercial
docker compose --profile control up -d --wait --wait-timeout 120
```

Core runner применяет каждый новый SQL-файл транзакционно и записывает историю после успешного выполнения. Неизвестная миграция в истории блокирует выполнение. Commercial использует штатный Doctrine runner. Скрипт проверяет принадлежность постоянных контейнеров проекту и имя целевой БД; дополнительные контейнеры и базы не создаются.

## Проверка

```powershell
docker compose exec -T backend php yii db:check
python scripts/database/migrate.py all
python scripts/database/export_schema.py --plane core --check
python scripts/database/export_schema.py --plane commercial --check
```

Повтор миграций должен быть пустым. Сверьте readiness HTTP, heartbeat фоновых процессов, ограничения и триггеры. Восстановление доступности HTTP само по себе не подтверждает сохранность финансовых данных.

Если изменение схемы намеренное, обновите JSON-каталоги и DBML без `--check`, затем выполните генераторы справочника и проверку ссылок. DBML строится по реальному каталогу; связи между отдельными базами не становятся SQL FK.

## Доступ к БД

Локальный Compose использует bootstrap-роли PostgreSQL. Для промышленной установки runtime-роли должны быть отделены от владельца схемы и мигратора, без `SUPERUSER`, `CREATEDB`, `CREATEROLE`, `BYPASSRLS`. Прохождение миграций под bootstrap-ролью не подтверждает минимальные привилегии приложения.

Очистка рабочих данных выполняется отдельно от миграций: только по подтверждённым идентификаторам и после проверки зависимостей. Финансовая история и append-only ограничения сохраняются.
