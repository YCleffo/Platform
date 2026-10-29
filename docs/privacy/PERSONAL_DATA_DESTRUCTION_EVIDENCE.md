# Свидетельства уничтожения персональных данных

Each successfully completed item has one immutable `privacy_destruction_evidence` row. It records case/item IDs, resource type, an HMAC-SHA256 reference to resource type and ID, policy/action/result codes, execution time, executor account and audit journal event ID. It excludes names, contact values, birth dates, old values, JSON snapshots and deleted entities. The HMAC key and evidence retention policy are deployment configuration; absence blocks execution. `evidence_retain_until` is independent of the destroyed resource's policy and is not an automatic deletion job.

`DestructionEvidenceExporter` produces structured technical data for an organizational act or journal review. It does not issue a signature or claim a legally signed act. Export consumers must apply restricted access and record their own approval and signature process. Audit and outbox cleanup follow their own category policies.

The database blocks ordinary UPDATE and DELETE of destruction evidence. The controlled privacy cleanup path checks the evidence retention policy, stored retention date and applicable holds before disposal. It is separate from deletion of the underlying resource and starts in dry-run mode.
