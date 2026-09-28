# Cross-process Event Contracts v1

`contracts/events/envelope.v1.schema.json` defines identity, type, version,
timestamp, producer, aggregate, correlation/causation and payload. Specific
payload schemas and valid fixtures currently cover
`control_user.email_verification_requested`, `deployment.provision_requested`
and the future `entitlement.issued`. Internal Audit events are not automatically
integration events. Changing a required payload field requires a new schema
version and consumer support; fixtures are validated in the Yii unit suite.

Commercial outbox migration 020 adds `schema_version=1`, leases, bounded
attempts and dead-letter state. Existing internal events keep their payloads.
The email worker is the first production consumer. Other cross-process
publishers/consumers remain explicitly deferred, and Yii uses
`core.inbox_events` keyed by `(event_id, consumer)` where external events are
eventually accepted. Delivery is at-least-once, not exactly-once.
