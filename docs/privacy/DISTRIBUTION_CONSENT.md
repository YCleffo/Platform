# Consent for public distribution

Core migration 026 stores distribution consent separately from ordinary purpose consent. A grant identifies subject and actor or verified representative, purpose, category and field codes, conditions, restrictions, document version/hash, grant time and withdrawal time. The default decision is deny. `DistributionConsentService::allows()` checks an active dedicated grant, requested category/field, channel restrictions and required conditions.

Modules that later publish avatars, names, achievements or profiles must call this decision along with their own access checks. Ordinary processing consent and interface settings are insufficient for public distribution.
