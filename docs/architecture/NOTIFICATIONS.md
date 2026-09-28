# Notification Policy

`NotificationPreferenceResolver` classifies delivery as `security`,
`transactional` or `product`. Security and transactional email cannot be
disabled by `notifications.email`; ordinary product email, browser and sound
delivery consult the versioned Account Settings registry. Modules call the
central resolver instead of interpreting sparse JSON overrides themselves.

`AccountSecurityNotifier` is an explicit security-email consumer. Its notices
(password, MFA, passkey and recovery changes) remain queued when a user turns
off product email. Message transport/Outbox lifecycle is described in
`COMMUNICATION.md`; this policy is not a separate delivery ledger.
