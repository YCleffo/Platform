# Cross-process Event Contracts v1

`contracts/events/envelope.v1.schema.json` defines identity, type, version,
timestamp, producer, aggregate, correlation/causation and payload. Specific
payload schemas and valid fixtures currently cover
`control_user.email_verification_requested`, `deployment.provision_requested`
and `entitlement.issued`. Internal Audit events are not automatically
integration events. Changing a required payload field requires a new schema
version and consumer support; fixtures are validated in the Yii unit suite.

Commercial outbox migration 020 adds `schema_version=1`, leases, bounded
attempts and dead-letter state. Existing internal events keep their payloads.
The email worker is the first production consumer. Other cross-process
publishers/consumers remain explicitly deferred, and Yii uses
`core.inbox_events` keyed by `(event_id, consumer)` where external events are
eventually accepted. Delivery is at-least-once, not exactly-once.

Phase 4.6 writes `entitlement.issued` with `schema_version=1` in the same
transaction as a new signed deployment document. Its payload contains only
`entitlement_document_id`, `installation_id`, and `document_sha256`; no document
bytes, signature or private key material enter the outbox. Repeated fetches of
an unchanged source do not create another event. The runtime applies the
document through its authenticated M2M endpoint; the outbox event is not a
substitute for fetching and verifying the document.

`subscription.activated` and `subscription.cancelled` remain internal
Commercial outbox events. They have no versioned cross-process payload
contract and no Tenant consumer. Tenant rights converge through authenticated
pull of the latest signed entitlement document, including after repeated or
delayed sync. Duplicate delivery of those internal events to Tenant is not a
Core acceptance path; do not add a consumer or transport to simulate it.
