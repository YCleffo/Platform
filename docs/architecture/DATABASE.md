# Database architecture — phase 1

One PostgreSQL database is used. Modules own PostgreSQL schemas:

```text
platform database
├── public.*       technical migration history only
├── core.*         universal Core v1 — exactly 23 tables
├── education.*    later
├── games.*        later
├── chat.*         later
├── storage.*      later
├── search.*       later
└── ...
```

## Core v1 freeze

`core.*` currently contains exactly 23 base tables. Adding Games, Education,
Search or branding does **not** mean adding product-specific Core tables.
Generic installation settings use `core.system_settings`.

## Large tables later

Attendance, lesson-participant snapshots, chat messages, analytics events,
audit/events and similar tables may eventually be large. PostgreSQL is designed
for that. We start with normal tables + correct indexes and introduce partitioning
when real volume/query plans justify it.

## Technical telemetry

Prometheus/OpenTelemetry/Grafana are independent infrastructure. They do not write
CPU, latency, traces or request metrics into `core.audit_events`.
