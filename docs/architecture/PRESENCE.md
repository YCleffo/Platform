# Presence and Privacy

Authenticated `POST /api/auth/me/presence/heartbeat` refreshes an ephemeral
Redis `presence:{accountId}` key with a 90-second TTL. The key records last
seen and a client instance hint. TTL expiry means offline; logout may
best-effort clear the key. Heartbeats create neither SQL rows nor Audit/Outbox
events. Redis failure yields an unknown state without breaking authentication.

No public `isOnline(accountId)` API is exposed. Internal consumers must use
`PresenceVisibilityPolicy`, which returns hidden when
`privacy.show_online_status=false`. Domain modules remain responsible for
relationship-level access (conversation membership, blocks, school roles).
