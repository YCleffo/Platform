# Operational status

Phase 5.2 provides durable local status for scheduled jobs and continuous email workers. Each application keeps one row per job in `background_job_status`; schedulers also update `__scheduler__` every 10 seconds. Records contain timestamps, bounded counts, duration, failure count, and a machine-safe error code. Exception text, payloads, recipients, and secrets are never stored there.

Core and Control Plane expose `/health/live` and `/health/ready`. Live checks only the HTTP process. Ready checks local database schema, required worker heartbeats, and (for Core) local Redis. It returns only `{"status":"ok"}` or `{"status":"not_ready"}`. Control Plane `/health` remains a readiness alias; Core `/health` keeps its existing compatibility response without infrastructure details.

Operators can inspect `php yii ops:status` and `php bin/console control:ops:status`. The read-only commands show job states, heartbeat age, safe counts, and backlog age. `ops:health <component>` and `control:ops:health <component>` are used by worker container healthchecks and exit nonzero when a required heartbeat is absent or stale. Set `CORE_*_HEALTH_TIMEOUT_SECONDS` and `CONTROL_*_HEALTH_TIMEOUT_SECONDS` above the normal heartbeat cadence. Disabled components are excluded from readiness through their `*_ENABLED` settings.

Prometheus, Grafana, Loki, OpenTelemetry, and Sentry are not implemented.
