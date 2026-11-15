# STORAGE_02: фактическая приёмка 2026-11-15

VERDICT: PARTIAL. Локальный код и основное окружение готовы; native Windows,
внешнее production-развёртывание и полная матрица производительности не приняты.

| Поле | Результат |
|---|---|
| MULTIAGENT | Реальные `/root/storage02_backend`, `/root/storage02_agent`, `/root/storage02_infra`, `/root/storage02_qa`; worktrees `C:\Platform-worktrees\storage02-{backend,agent,infra,qa}-20261114`. Runtime model/session UUID инструмент не раскрывал. Из-за лимита три дочерних агента работали одновременно, QA начат после освобождения слота. |
| STORAGE_BACKEND | AWS SDK, один S3 backend, прежние metadata/RBAC/manifest правила сохранены. |
| S3_ENGINE | Реальный SeaweedFS 4.53, pinned digest, два приватных bucket, отдельные init/application identities. |
| DOCKER | Main backend/fast/workers и S3 здоровы. Fast image собран из текущего кода. Удалены 11 тестовых контейнеров, 6 томов, 8 сетей; build cache: 1.714 GB. Tagged images, необходимые текущему Compose и MyBots, сохранены. |
| EDUCATION_FILES | FileStore/материалы/аватары/homework переведены на S3, direct upload/download. Два новых реальных S3 сценария Education: 2 теста / 31 проверка. |
| WINDOWS_AGENT | Проекты/namespace/service/IPC переименованы. Явная миграция прежнего состояния с backup и проверкой, без удаления Documents. Native install/upgrade не выполнялся. |
| STREAMING | Agent: 260 MiB на настоящем AgentClient с контролируемым HTTP transport, bounded allocations <64 MiB. PHP/S3: потоковая проверка; Education Blob ограничен 10 MiB, favicon 512 KiB. |
| MULTIPART | Реальные signed parts, ListParts, complete и полный финальный SHA-256. Исправлена необходимость передавать сохранённые part checksum при неполном ListParts SeaweedFS. |
| RESUME | Durable sessions отдельно от URL TTL; Agent journal, part retry/restart и Range проверены контролируемым transport. Реальный API/S3 refresh/ListParts также проверен. |
| MANIFESTS | Сохранены существующие версии, manifest confirmation и запрет cleanup до StorageConfirmed. |
| CONFLICTS | Реальный API проверяет concurrent reservation и устаревшую business version; Agent сохраняет уникальные recovery copies. |
| SECURITY | Текущая организация/actor/resource проверяются; signed staging не меняет final object; S3 не получает Core/device bearer. Иконки проверяются по фактическим bytes. Полный security scan не запускался. |
| PRIVACY | S3 adapters интегрированы с прежними contributor/erasure/outbox механизмами. Юридическая политика и offsite retention конкретной установки не подтверждены. |
| CLEANUP | Recheck ссылок, backup advisory lock, outbox/reconciliation и команды cleanup. Расписание S3 cleanup должен включить оператор. |
| LEGACY_REMOVAL | LocalFilesystemStorageAdapter и PrivateFileStore удалены; активного filesystem fallback нет. Исторические SQL/upgrade maps и исходные orphan bytes сохранены. |
| DATA_MIGRATION | Реальный dry-run/apply/hash/replay/missing/corrupt/untracked smoke прошёл. Main имел 0 опубликованных binary references. 62 legacy файла сохранены с hash inventory, их владельцы не определены. |
| BACKUP_RESTORE | Зашифрованные согласованные snapshot БД+S3; отдельная БД и отдельный S3 восстановлены, все 7 объектов прошли SHA-256. Main pre-change и post-switch snapshot созданы. Три старые тестовые БД дополнительно заархивированы и проверены расшифровкой до удаления. |
| FRONTEND | Direct transfers, chunked hashing/progress/cancel, глобальные название/favicon. Typecheck и 10 целевых Vitest проверок прошли. Визуальная/native browser приёмка не выполнялась. |
| OPENAPI | Storage/DeviceManagement route parity: 2 теста прошли; Education transfer и Branding endpoints описаны. |
| DATABASE | Main Storage 004, Education 016; применённые старые SQL не изменены. Каталоги/DBML: Storage 15, Education 53 таблицы. Runtime права на новые transfer таблицы проверены. |
| QUICK_TESTS | PHP lint 74 файла; backend группы: 31/382, 35/462, 25/637, Education direct 2/31; реальный S3 7/257. Часть групп пересекается, числа не суммируются. .NET lifecycle 31, regression 20, Storage transport 15 сценариев прошли. |
| FAULT_TESTS | Реальные API/S3: 7 сценариев single/multipart/resume/checksum/RBAC/quota/conflict/restore. .NET transport: обрыв 10/50/99%, неверный Range/hash, игнорирование Range, timeout/expired URL, изменение источника, потеря ответа complete. Tamper/truncated encrypted stream отвергаются. |
| PERFORMANCE | 260 MiB bounded-memory transport доказан; реальный HTTP MPU 12 582 932 байта. End-to-end .NET→PHP→S3 >256 MiB и холодные 1/5/10 GiB scans не измерены. Полные manifest scans всё ещё пересчитывают SHA-256. |
| WINDOWS_NATIVE_ACCEPTANCE | NOT_RUN: нет подтверждённой VM/service/reboot/prelogin/Credential Provider приёмки. TestSessionManager не является Windows logon. |
| GIT_STATUS | main, без commit/push/reset/clean/stash. Начальные 306 изменений сохранены, поздние форматирование/type alias объединены, 11 внешних удалений сохранены. Изолированные worktrees и private manifest оставлены для проверки/восстановления. |
| REMAINING | Native Windows acceptance; внешний VPS/DNS/TLS/CORS/offsite restore; end-to-end Agent large-file faults и cold-scan performance; включение оператором backup/cleanup расписания; классификация 62 orphan legacy files. Удаление старых ignored Agent bin/obj заблокировано автоматической политикой. |

Доступ и команды эксплуатации: [STORAGE_02.md](STORAGE_02.md).
Прежний общий `education-contract-smoke.mjs` привязан к отдельному стенду 8088 и
`runtime/education-local`; его запуск без этого стенда завершился connection refused.
Он не засчитан как пройденный. Для повторения старой общей Education приёмки нужно
отдельно настроить ей тестовый S3. Перечисленные выше новые S3/API и целевые тесты
прошли на изолированном STORAGE_02 стенде 8089; после проверки этот стенд удалён.
Закрытые evidence, hashes и migration/cleanup inventories находятся в
`runtime/storage02` и `backups`; секреты и персональные данные в этот отчёт не включены.
