# Изолированная нагрузка и HTTP-проверка

`compose.load.yaml` — самостоятельный проект `platform-load`. Его DB,
Redis, Mailpit и volumes не используются development или штатными tests.
Пароли и signing/MFA-параметры здесь синтетические и предназначены только
для локального теста. Данные не отправляются внешнему SMTP или банку.

## Подготовка

```powershell
New-Item -ItemType Directory -Force runtime/load-results | Out-Null
docker compose -p platform-load -f compose.load.yaml build core control
docker compose -p platform-load -f compose.load.yaml up -d --wait core-db commercial-db redis mailpit
docker compose -p platform-load -f compose.load.yaml run --rm core-migrate
docker compose -p platform-load -f compose.load.yaml run --rm --no-deps control php bin/console doctrine:migrations:migrate --no-interaction
docker compose -p platform-load -f compose.load.yaml up -d core control

Get-Content scripts/load/seed-core.sql -Raw | docker compose -p platform-load -f compose.load.yaml exec -T core-db psql -X -U load_core -d platform_load_core -v ON_ERROR_STOP=1
docker compose -p platform-load -f compose.load.yaml cp scripts/load/seed-commercial.php control:/tmp/seed-commercial.php
docker compose -p platform-load -f compose.load.yaml exec -T control php /tmp/seed-commercial.php
```

Seed требует пустую именованную load DB и отказывается работать с другой.
Создаются 10 000 разных identities и sessions в каждом приложении, 10 000
Commercial Accounts и subscriptions, три тарифа free/standard/premium.
Subscription/invoice/payment fixtures создаются существующими application
services; положительные payments получают локальный fixture settlement,
который **не является проверкой провайдера**. Токены детерминированы только в
этом disposable стенде и хранятся в DB как хеши.

Повтор seed в непустой DB отклоняется. Для полного удаления только этого
стенда используйте явно именованный `docker compose -p platform-load -f
compose.load.yaml down --volumes`. Это удаляет синтетические load-данные.

## Запуск

```powershell
python scripts/load/run.py --vus 10 --label smoke
python scripts/load/run.py --vus 100 --hold 60 --pace 5 --label online-100
python scripts/load/run.py --vus 10000 --hold 60 --pace 5 --label online-10000

# Отдельно, когда k6 остановлен:
python scripts/load/http-business-smoke.py
```

Сценарий k6 читает шесть реальных endpoints: Core `/api/auth/me`, settings,
Commercial account, entitlements, sessions и published catalog. Проверяются
HTTP status и принадлежность ответа текущему пользователю. В workload нет
mock HTTP responses, повторного login одним общим пользователем или обхода
rate limits. HTTP smoke отдельно проверяет регистрацию, SMTP/Mailpit links,
password reset, rotation/replay, MFA step-up, billing, free purchase,
idempotent payment, unpaid upgrade, cancellation и logout.

Для 10 000 VU: рост до 100, затем 1 000, затем 10 000, плато и снижение.
`pace=5` означает один запрос на пользователя за пять секунд, если endpoint
успевает. Медленный ответ сам уменьшает offered load: это closed workload,
не обещание 2 000 успешных RPS. VU не означает постоянное socket-соединение.
Профили меньше 10 000 используют отдельное компактное плато; smoke занимает
около 25 секунд. Timeout запроса — 10 секунд.

Пороги заданы до запуска: HTTP/business errors <1%, p95 <1 с, p99 <3 с.
Код k6 99 означает пересечение порогов, а не успешную приёмку. Исторический
JSON summary k6 хранит `thresholds: true` для пересечённого порога — учитывайте
exit code и значения метрик при разборе.

Генератор ограничен 4 CPU и 12 GiB, чтобы не исчерпать весь Docker host.
Результаты, exit code и snapshots ресурсов сохраняются в `runtime/load-results`.
Одновременные tests/builds и другие workloads влияют на результат; для
production capacity нужны отдельный генератор, стабильные квоты серверов,
репрезентативные данные, длительный soak и измерение workers/БД.

После проверки можно остановить стенд, сохранив данные:

```powershell
docker compose -p platform-load -f compose.load.yaml stop
```

Методика сценария опирается на [официальный ramping-vus executor k6](https://grafana.com/docs/k6/latest/using-k6/scenarios/executors/ramping-vus/).
Фактические результаты: [аудит 2026-10-28](../../docs/audit/2026-10-28/REPORT.md).
