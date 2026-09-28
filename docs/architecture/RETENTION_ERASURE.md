# Retention and erasure boundary

`RetentionPolicyDefinition` and `RetentionPolicyRegistry` accept module/deployment policies with a trigger, optional duration, action, and legal-hold flag. An unset duration means deployment policy is required; it does not mean forever. Existing `auth/cleanup` remains the operational cleanup for auth challenges and recovery codes.

The controlled retention evaluator, legal holds, audit cleanup, erasure cases/items, and module erasure handlers are **not implemented in the foundation pass**. The next pass must make these operational before production privacy erasure is claimed. Until then PostgreSQL blocks ordinary deletion of legal representation and consent evidence. Person archive is a business lifecycle action and does not perform privacy erasure. Consent withdrawal does not erase an entire Account; each resource and purpose needs its own basis, retention, and hold check.

Erasure evidence must contain minimal IDs, policy code, action, time, actor, and result, never a copy of destroyed personal data. Cross-module deletion must use observable handlers or events; no Core FK cascade should erase future Education, Chat, or Storage data.
