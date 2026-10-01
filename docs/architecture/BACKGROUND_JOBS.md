# Background jobs

`backend-scheduler` runs `php yii scheduler:run`; `control-scheduler` runs `php bin/console control:scheduler:run`. Both perform one bounded call per due job, use a PostgreSQL session advisory lock keyed by `scheduler:<owner>:<job>`, release it after the call, and continue after a job failure. They log only job code, status, duration, count, and a machine error code. Invalid interval or limit configuration stops startup. Intervals and limits below are defaults; the corresponding variables in `.env.example` configure them.

| Job | Owner | Purpose | Cadence | Batch limit | Destructive | Lock key | Failure behaviour |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `auth_cleanup` | Core | Expired Auth artifacts | 3600 s | 500 per category | Yes, retention governed | `scheduler:core:auth_cleanup` | Retry next interval |
| `privacy_retention_evaluate` | Core | Persist retention decisions | 3600 s | 100 candidates | No | `scheduler:core:privacy_retention_evaluate` | Retry next interval |
| `privacy_erasure_execute` | Core | Retry executable erasure cases | 300 s | 10 cases, 25 items each | Yes, disabled by default | `scheduler:core:privacy_erasure_execute` | Retry next interval; executor owns case state |
| `privacy_cleanup` | Core | One configured category per tick | 3600 s | 100 rows | Yes, disabled by default | `scheduler:core:privacy_cleanup` | Skip without policy; retry next interval |
| `runtime_entitlement_sync` | Core | Pull signed entitlement | 300 s | 1 sync | No | `scheduler:core:runtime_entitlement_sync` | Retain current document; retry next interval |
| `prepare_renewals` | Control | Prepare renewal invoices | 300 s | 100 subscriptions | No | `scheduler:control:prepare_renewals` | Retry next interval |
| `advance_subscriptions` | Control | Advance paid periods | 300 s | 100 subscriptions | No | `scheduler:control:advance_subscriptions` | Retry next interval |
| `expire_subscriptions` | Control | Expire ended periods | 300 s | 100 subscriptions | No | `scheduler:control:expire_subscriptions` | Retry next interval |
| `reconcile_yookassa_payments` | Control | Reconcile payments | 120 s | 100 attempts | No | `scheduler:control:reconcile_yookassa_payments` | Provider retry remains in service |
| `reconcile_yookassa_refunds` | Control | Reconcile refunds | 120 s | 100 refunds | No | `scheduler:control:reconcile_yookassa_refunds` | Provider retry remains in service |
| `reconcile_yookassa_receipts` | Control | Reconcile receipts | 120 s | 100 receipts | No | `scheduler:control:reconcile_yookassa_receipts` | Provider retry remains in service |
| `control_privacy_cleanup` | Control | One configured category per tick | 3600 s | 100 rows | Yes, disabled by default | `scheduler:control:control_privacy_cleanup` | Skip without policy; retry next interval |

Renewal preparation, advancement, and expiration run in that order in each cycle. Each job has its own interval and a failure does not prevent the next job from running. Core erasure requires `CORE_PRIVACY_ERASURE_ENABLED=true`, a valid active system account in `PRIVACY_AUTOMATION_ACCOUNT_ID`, and configured destruction evidence and legal package policy. Cleanup requires its explicit enable flag and a category retention policy. No automatic job creates a policy or system credential.

`backend-email-worker` runs `php yii communication:process-email --loop` using the existing outbox processor. `control-outbox-worker` remains separate. Both sleep when the outbox is empty. The new compose services restart unless stopped and use the same application configuration and secrets as their API service.

Each local dedicated deployment runs its own Core scheduler and email worker from the exact HTTP runtime image digest. Both use that deployment's PostgreSQL and Redis; the email worker uses local Mailpit through the control network. Scheduler writes `__scheduler__` and the email worker writes `backend_email_worker` into the existing `platform_runtime.background_job_status` table. Runtime activation waits for both fresh heartbeats and Redis PING before reporting ready. Destructive privacy jobs stay disabled until deployment-specific policy is explicitly configured. Before final decommission backup, the provider stops all three Core processes so no background writer remains.

The Core `runtime_entitlement_sync` job resolves `CONTROL_PLANE_DEPLOYMENT_CREDENTIAL_REF` through `RuntimeSecretResolver`, exactly as `runtime:deployment:activate` does. A temporary Control Plane or authentication failure is logged as a failed job and leaves the last applied local entitlement untouched. A rotated deployment credential must be placed in the external secret source and loaded by restarting or recreating the runtime process before this scheduled sync can use it.
