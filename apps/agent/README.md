# KiberOne Agent

Отдельное приложение для операций Windows, которые браузер и сервер выполнить не могут: управляемые временные профили учеников, синхронизация Documents с Storage и жизненный цикл учебной сессии.

## Компоненты

- **KiberOne.Agent** — домен: конечный автомат сессий (зеркало серверной модели), манифесты и diff, атомарный журнал состояния, HTTP-клиент протокола, абстракция управления профилями.
- **KiberOne.Agent.Service** — привилегированный хост: Windows Service через SCM (P/Invoke, без NuGet) и консольный режим `--console`; локальный named-pipe IPC для UI.
- **KiberOne.Agent.Ui** — непривилегированный консольный интерфейс; читает только статус через named pipe, административных операций не выполняет.
- **KiberOne.Agent.Tests** — автономный тестовый прогон (без NuGet): конечный автомат, manifest/diff, журнал, изолированный менеджер профилей.

## Сборка и проверка

```powershell
dotnet build KiberOne.Agent.sln
dotnet run --project KiberOne.Agent.Tests
```

## Конфигурация (переменные окружения)

| Переменная | Назначение |
| --- | --- |
| `KIBERONE_AGENT_BASE_URL` | Адрес Platform (по умолчанию `http://127.0.0.1:8080`) |
| `KIBERONE_AGENT_STATE_DIR` | Каталог журнала и секретов агента (защитить ACL) |
| `KIBERONE_AGENT_DOCUMENTS_ROOT` | Тестовая папка Documents (development mode) |
| `KIBERONE_AGENT_ALLOW_INSECURE_HTTP` | Явное разрешение локального HTTP для разработки |
| `KIBERONE_AGENT_DANGEROUS_WINDOWS_MODE` | Включает настоящие операции профилей — только в выделенной тестовой VM |

## Границы и незавершённое

- Имитация Windows-профилей полностью изолирована в `TestSessionManager` и не является доказательством корректности Windows Logon.
- `WindowsSessionManager` — скелет; реальные операции требуют включения опасного режима в специальной тестовой VM и пока не реализованы.
- Pre-login сценарий (Credential Provider / kiosk shell) **не реализован**: агент не блокирует Windows до авторизации и не выдаёт это за готовое.
- Кейлоггер, запись рабочего стола и скрытое наблюдение не входят в состав агента; результаты передают только доверенные нативные приложения через контракт Education Activities.
- Аварийный административный доступ для восстановления компьютера должен быть обеспечен на уровне развёртывания (локальная административная учётная запись вне агента).

## Жизненный цикл

`AUTHORIZED → PREPARING → WINDOWS_PROFILE_READY → SYNCING_IN → ACTIVE → ENDING → SYNCING_OUT → STORAGE_CONFIRMED → CLEANUP → CLOSED`, аварийные `SYNC_PENDING / RECOVERY_REQUIRED / FAILED`. Локальные документы не удаляются, пока сервер не подтвердил принятый манифест (`ConfirmSyncAsync`), а очистка профиля выполняется только после `STORAGE_CONFIRMED`.
