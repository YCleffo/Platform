# Эксплуатационное состояние и наблюдаемость

Этап 5.2 добавляет постоянные локальные статусы плановых задач и непрерывных почтовых обработчиков. Каждое приложение хранит по строке задачи в `background_job_status`; планировщики также обновляют `__scheduler__` каждые 10 секунд. Записи содержат время, ограниченные счётчики, длительность, число ошибок и безопасный машинный код. Тексты исключений, содержимое сообщений, получатели и секреты туда не попадают.

Core и Control Plane предоставляют `/health/live`, `/health/ready` и защищённый токеном `/metrics`. Первая проверка контролирует только HTTP-процесс; вторая — локальную схему БД, обязательные сигналы активности обработчиков и Redis у Core. `/metrics` включается только при `METRICS_TOKEN` не короче 32 символов; без него возвращается 404. Порт метрик Caddy `2019` внутренний и не публикуется на узле.

## Локальный набор сервисов

Необязательная дополнительная конфигурация Compose запускает Prometheus, Alertmanager, Grafana, экспортёры PostgreSQL/Redis/узла и локальный приёмник оповещений:

```bash
# Windows
pwsh -File scripts/observability/start-local.ps1

# Linux/macOS
export METRICS_TOKEN="$(openssl rand -hex 32)"
export GRAFANA_ADMIN_PASSWORD="$(openssl rand -hex 24)"
mkdir -p infra/observability/secrets
printf '%s' "$METRICS_TOKEN" > infra/observability/secrets/metrics-token
printf '%s' 'http://alert-sink:8080/alerts' > infra/observability/secrets/alert-webhook-url
printf '%s' "$GRAFANA_ADMIN_PASSWORD" > infra/observability/secrets/grafana-admin-password
chmod 600 infra/observability/secrets/*
docker compose -f compose.yaml -f compose.observability.yaml --profile control up -d --build
```

`start-local.ps1` создаёт недостающие `infra/observability/secrets/metrics-token`, `alert-webhook-url`, `grafana-admin-password`. Каталог секретов исключён из Git. Prometheus и Alertmanager получают каждый только свой файл, а не весь каталог.

Локальные адреса:

- Prometheus: `http://127.0.0.1:9090`.
- Alertmanager: `http://127.0.0.1:9093`.
- Grafana: `http://127.0.0.1:3000`.
- Приёмник оповещений: `http://127.0.0.1:8085`, только для локальной проверки.
- Метрики Core: `http://127.0.0.1:8080/metrics`, с `Authorization: Bearer <token>`.
- Метрики Commercial: `http://127.0.0.1:8090/metrics`.

Остановка и удаление локальных контейнеров наблюдаемости и их томов:

```bash
pwsh -File scripts/observability/stop-local.ps1
```

## Сигналы и оповещения

Prometheus собирает:

- счётчики и гистограммы HTTP Caddy обеих систем на `:2019`;
- `platform_ready`, активность обработчиков, размер очередей исходящих сообщений и финансовых задач из `/metrics`;
- показатели PostgreSQL, Redis и узла через экспортёры.

Правила в `infra/observability/prometheus/rules/platform.yml` охватывают долю 5xx, задержку p95, готовность, доступность зависимостей, активность критичных процессов, размер/возраст очереди, финансовую очередь и ошибки получения метрик. Grafana автоматически подключает панель `Platform operations overview` — «Обзор эксплуатации Platform».

Доставка остаётся локальной, пока оператор не заменит `infra/observability/secrets/alert-webhook-url` реальным промышленным получателем и не подтвердит получение. Проверка локального пути:

```bash
node scripts/observability/verify-alert-delivery.mjs
```

Скрипт отправляет синтетическое оповещение в Alertmanager и проверяет его получение приёмником. Сохранение оповещения в Alertmanager само по себе не доказывает доставку.

## Базовые измерения производительности

`scripts/observability/capacity-baseline.mjs` измеряет целевой сервис и сохраняет срез Prometheus. Он требует явного окружения и отказывается от промышленного запуска без `CAPACITY_ALLOW_PRODUCTION=true`. Локальная проверка на 5 секунд не доказывает производительность; принимаемый датированный отчёт должен использовать представительные объёмы испытательного окружения. Цели и определения: [показатели эксплуатации](../../docs/operations/SLO.md).

## Ограничения

- Набор сервисов и пороги оповещений остаются предложением до утверждения владельцем продукта.
- Локальная конфигурация не подтверждает внешнюю доставку, реальные промышленные объёмы, допустимую потерю данных и время восстановления, Loki/OTel/Sentry или высокую доступность.
- `/metrics` отдаёт служебные счётчики, а не персональные данные или содержимое сообщений.
- Порт Caddy `2019` должен оставаться во внутренней сети.
