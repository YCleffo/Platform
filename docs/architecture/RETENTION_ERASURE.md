# Retention and erasure boundary

Pass B3 implements retention evaluation, scoped legal holds, and processing termination where registered candidate and restriction contributors cover processing. Candidate evaluation is purpose-specific and produces append-only eligibility evidence. Unknown policy, trigger, basis, hold state, or dependency blocks destructive eligibility. A multi-purpose resource may be deleted only when every applicable purpose permits it. A decision marked `eligible_delete` is not evidence that data was deleted.

The retention policy declares `duration` and an explicit `durationUnit`: `calendar_days`, `working_days`, `hours`, or `event_only`. A null duration for a timed unit is missing deployment policy. No production retention duration is supplied by Core. Semantic trigger timestamps come from contributors, not a presumed creation time.

Legal hold blocks delete, anonymize, and pseudonymize eligibility. It does not block access, export, correction, or a processing block. Processing termination uses existing `ProcessingBlockService` and registered restriction contributors; missing or failed contributors leave the request open. Execution evidence lists stopped and retained purpose codes and satisfies the deadline only after success.

Physical erasure, destruction evidence, and cleanup workers are **not implemented**. Account disable, Person archive, and consent withdrawal do not erase data. B4 must re-evaluate retention, basis, legal hold, dependencies, and all applicable purposes immediately before any destructive operation. Existing `auth/cleanup` remains separate authentication housekeeping.
