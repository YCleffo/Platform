# Modularity rules

These are project laws, not suggestions.

1. **Core is universal.** It contains no education, game, chat, file, device or product-specific entities.
2. **Dependency direction is toward Core:** `Education -> Core`, `Games -> Core`, etc. Never `Core -> Games`.
3. A module owns its data, business rules and infrastructure adapters.
4. One module must not directly manipulate another module's internal tables as an implementation shortcut.
5. Cross-module collaboration goes through application APIs/ports or domain/integration events.
6. Replaceable external technologies sit behind provider/port interfaces where replacement is plausible.
7. PostgreSQL is deliberately the transactional source of truth. We do not pretend swapping the primary relational database is a routine requirement.
8. Redis is ephemeral infrastructure, not business source of truth.
9. Technical telemetry does not go into Core audit tables.
10. Optional modules must be removable/disableable without collapsing Core.

## Conceptual dependency

```text
Education ----\
Games ---------\
Chat ----------- > Core
Storage -------/
Search --------/

Core -X-> any optional domain module
```
