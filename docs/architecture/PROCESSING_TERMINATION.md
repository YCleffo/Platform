# Processing termination

`POST /api/core/privacy/requests/{id}/processing-termination/execute` handles an `in_progress` request. It collects subject candidates, evaluates lawful basis and retention, creates purpose-scoped processing blocks for purposes that can stop, and invokes every matching restriction contributor. It writes machine-code execution evidence and satisfies the termination deadline before marking the request completed or partially completed.

An active basis is recorded as a retained purpose, yielding partial completion when other purposes stop. Unknown basis, missing candidates, missing restriction contributor, or contributor failure leaves the request open. A retry reuses the same block ID and terminal requests return existing execution evidence. Contributors must make `applyRestriction` idempotent by block ID. The service does not disable Accounts, archive Persons, or delete data.
