# Platform coding context

Before changing application code, migrations, API or event contracts, workers,
or deployment logic, read `docs/architecture/CORE_INVARIANTS.md` and identify
the affected requirement IDs. Preserve the documented boundaries and update
the source-of-truth contracts and tests for any behavior change.

Production readiness claims require runtime and operational evidence; a local
unit test pass alone is insufficient.
