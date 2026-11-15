# STORAGE_02: эксплуатация и приёмка

Срез: 15 ноября 2026. Код перенесён в основной `C:\Platform`, без коммитов.
Фактические результаты и ограничения: [STORAGE_02_ACCEPTANCE.md](STORAGE_02_ACCEPTANCE.md).

## Локальный запуск

На этой машине Docker Desktop запущен. В `.env` сохранены три Compose-файла:
`compose.yaml`, `compose.s3.yaml`, `compose.storage.yaml`. Основной S3 использует
отдельный том `platform_seaweedfs_data`; удалять его вместе с тестовыми томами нельзя.

```powershell
docker compose up -d --no-build --pull never
docker compose ps
```

Для первого развёртывания нужно собрать образы из текущего исходного кода.
`postgres` имеет `pull_policy: build`; `--no-build --pull never` при обычном
перезапуске сохраняет уже собранный образ и не запускает лишнюю сборку PostgreSQL.

Education: <http://localhost:5174/education/login>.
Админка: <http://localhost:5174/admin/settings>, вкладка оформления платформы.
Изменение названия и favicon требует `core.settings.manage`, просмотр вкладки —
`core.settings.read`. PNG/ICO: до 512 КиБ, до 1024×1024. Название меняет общий
бренд интерфейса RU/EN и заголовок вкладки; названия учебных активностей остаются
`Platform Typing` и `Platform Logic`.

Core API: 8083; собранный Core fast: 8082; Commercial: 8090. S3: 127.0.0.1:8333.
Порт 8080 на этой машине занят OSPanel. Проверки:

```powershell
curl.exe http://localhost:8083/health/ready
curl.exe http://localhost:8083/health/storage
curl.exe http://localhost:8082/health/storage
curl.exe http://localhost:5174/api/branding
```

SeaweedFS 4.53 закреплён digest. Каталог `runtime/s3` содержит закрытые credentials;
генератор `scripts/dev/storage02/s3-config.py` не перезаписывает существующую пару
конфигураций. У приложения права только на два bucket; initializer использует
отдельную учётную запись. CORS разрешает перечисленные frontend origin.
Административные порты SeaweedFS наружу не публикуются. Опубликованный S3-порт
привязан к loopback; дополнительная сеть `s3_endpoint` нужна для host port binding,
`s3_net` — для внутреннего обращения backend. Сервис слушает обе сети.

## VPS и внешний S3

Для внешнего провайдера используется базовый Compose и все `S3_*` переменные из
`.env.example`; локальные `compose.s3.yaml` и `compose.storage.yaml` не подключаются.
Для локального SeaweedFS на VPS используются все три файла. Пример native Caddy:
`infra/s3/Caddyfile.example`; домен/DNS/TLS и `S3_PUBLIC_ENDPOINT=https://...`
настраиваются до выдачи подписанных URL. URL подписывается сразу для публичного
host, подмена host после подписи запрещена. Публичный HTTP в production отвергается.

До выпуска нужно проверить реальные DNS/TLS, CORS, выдачу URL с внешнего Windows
компьютера, доступность bucket, место на диске и вынос резервных копий с VPS.
Эта локальная проверка не подтверждает внешний production или native Windows.

## Резервная копия и восстановление

Исполняемый runner: `scripts/storage/backup.py`. Нужны Docker, Python и PHP с sodium.
Закрытый ключ — отдельный файл ровно 32 байта вне каталога копии. Не класть ключ
рядом с архивом и не отправлять его в Git. На этой Windows машине ACL ключа и S3
конфигураций ограничены текущим пользователем и SYSTEM.

```powershell
python scripts/storage/backup.py backup --backend platform-backend --postgres platform-postgres --database platform --user platform_app --directory C:\secure-backups\snapshot-NEW --key-file C:\private\storage.key
```

Копия содержит зашифрованный `pg_dump -Fc`, зашифрованные объекты и inventory.
Snapshot PostgreSQL удерживается до завершения. Общая advisory-блокировка не даёт
cleanup физически удалить объекты из snapshot. Для каждого объекта размер и полный
SHA-256 проверяются по metadata БД; `READY` появляется только после успеха.
Шифрование — authenticated secretstream с обязательным FINAL tag. Обрыв и подмена
отвергаются. Подписанные URL, незавершённые загрузки и локальные Documents не являются
резервной копией опубликованных объектов.

Для восстановления подготовить **новый** backend-контейнер, направленный на новое
имя БД и новые пустые S3 buckets. Backend должен видеть целевой PostgreSQL. Запустить:

```powershell
python scripts/storage/backup.py restore --backend restore-backend --postgres restore-postgres --database platform_restored --user restore_owner --directory C:\secure-backups\snapshot-NEW --key-file C:\private\storage.key
```

Runner отказывается от существующей БД, проверяет целевое имя в backend, ciphertext,
а затем размер и SHA-256 каждого восстановленного объекта. Другой существующий
объект не перезаписывается. После восстановления проверить права runtime/maintenance
ролей, обновить endpoint/credentials и аннулировать старые незавершённые передачи,
если исходные multipart sessions не были восстановлены. Неподтверждённые Documents
на Agent сохраняются для повторной передачи.

Политика `retainUntil` записывается в manifest (по умолчанию 30 дней). Автоматическое
удаление архивов и offsite-репликация не включены: их расписание, retention и отдельное
хранение ключа настраиваются оператором. Проверять восстановление регулярно.

## Миграция и уборка объектов

```powershell
docker exec platform-backend php yii storage:migrate-objects --module=storage --source=/explicit/old/files --report=/private/report-NEW.json
docker exec platform-backend php yii storage:migrate-objects --module=storage --source=/explicit/old/files --apply --report=/private/apply-NEW.json
docker exec platform-backend php yii storage:cleanup
docker exec platform-backend php yii education:cleanup-objects
```

Для Education указать `--module=education` и её прежний каталог. Миграция по умолчанию
только проверяет; пропущенный, повреждённый или неучтённый источник означает ошибку.
Применение повторяемо и подтверждается ledger и полным SHA-256; исходники никогда
автоматически не удаляются. Storage и Education используют S3, filesystem fallback
удалён. Временный ограниченный spool при проверке/выгрузке не является постоянным
backend-хранилищем.

Cleanup повторно проверяет ссылки metadata и блокировку backup перед физическим
удалением. Эти команды нужно поставить в расписание оператора; отдельный автоматический
S3 cleanup worker на этой машине не включён. Размещение копий, privacy retention,
legal holds и аварийное восстановление требуют политики конкретной установки.

В основной БД на момент перехода: 0 `storage.versions`, 0 `education.files`,
0 `education.homework_files`. Старые локальные 62 файла (21 034 427 байт) сохранены
с SHA-256 в `backups/storage02-legacy-20261114T192326`; они не имели ссылок из основной
БД. Их нельзя объявлять успешно сопоставленными пользовательскими объектами или
удалять до классификации владельцем. Основные исходные каталоги сохранены.

## Совместимость бренда и документация

Активный код, проекты Agent, настройки сервиса, frontend, контракты и текущая
документация используют Platform. Education 016 переименовала activity identifiers,
FK-ссылки и поля событий достижений. Snapshot завершённых упражнений не переписывается.
Старые имена оставлены только в применённых исторических SQL и явных upgrade maps
`ActivityCode`, `LegacyInstallation`/native compatibility. Архивы не переписываются.
Старые ignored Agent `bin/obj` не удалены: автоматическая проверка запретила действие.

Схемы PostgreSQL обновлены из реального каталога: Education 53 таблицы, Storage 15.
Контракты: `contracts/openapi/storage.yaml`, `education.yaml`, `branding.yaml`.
В исходниках вне миграций остались только четыре целевых SQL: каталог схемы,
CI maintenance probe, CI load catalog и dev demo catalog. Это рабочие инструменты,
локальных SQL-дампов среди файлов проекта не найдено; их удаления не требовалось.
