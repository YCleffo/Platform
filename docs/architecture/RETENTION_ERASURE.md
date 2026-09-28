# Retention and erasure boundary

`RetentionPolicyDefinition` and `RetentionPolicyRegistry` accept module/deployment policies with a trigger, optional duration, action, and legal-hold flag. An unset duration means deployment policy is required; it does not mean forever. Existing `auth/cleanup` remains the operational cleanup for auth challenges and recovery codes.

The controlled retention evaluator, legal holds, audit cleanup, erasure cases/items, and module erasure handlers are **not implemented in the foundation pass**. The next pass must make these operational before production privacy erasure is claimed. Until then PostgreSQL blocks ordinary deletion of legal representation and consent evidence. Person archive is a business lifecycle action and does not perform privacy erasure. Consent withdrawal does not erase an entire Account; each resource and purpose needs its own basis, retention, and hold check.

Pass B1 records data subject requests and processing blocks, but does not execute erasure or define retention periods. An erasure request cannot reach completed status through B1. A processing block is a temporary restriction signal to callers of `ProcessingBlockPolicy`, not destruction or a legal hold.

Pass B2 stores immutable terminal execution evidence for export and correction, but defines no new retention period or deletion executor. An erasure request remains non-completable; processing termination also awaits basis and retention orchestration. No export body or correction value is retained in execution evidence. The controlled retention evaluator and legal hold remain Pass B3 work.

Erasure evidence must contain minimal IDs, policy code, action, time, actor, and result, never a copy of destroyed personal data. Cross-module deletion must use observable handlers or events; no Core FK cascade should erase future Education, Chat, or Storage data.
