# Backend — first phase

At this exact phase the folder intentionally contains **database migrations only**.
The actual Yii3 API application is bootstrapped from the official `yiisoft/app-api`
template by `scripts/bootstrap-backend.ps1` when we start step 2.

Why not generate a fake hand-written Yii skeleton now:

- the official template evolves;
- we want current Yii3 conventions, not Yii2 habits;
- Core database can already be deployed and inspected independently;
- the SQL contract in `migrations/sql` is deterministic and easy to review in DBeaver.

After Yii3 bootstrap we will move/translate these migrations into the chosen Yii3
migration layout without changing the Core v1 database contract.
