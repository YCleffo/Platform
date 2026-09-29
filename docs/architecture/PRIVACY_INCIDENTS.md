# Privacy incidents

Core migration 026 stores incidents, affected category codes, subject scope and immutable status events. `PrivacyIncidentService` supports detected, investigating, contained and resolved states, records safe reason codes, and sets initial and investigation notification due times at 24 and 72 hours from detection. External notification timestamps are recorded only after an authorized person reports the action. The service never sends an authority notification.

Incident records store no leaked payload, password, token, or raw contact list. `export()` provides structured evidence for a responsible person. Operator runbooks must separately verify incident scope, notification duty, submission channel, responsible staff and actual external receipt. The deadline fields are reminders and evidence, not proof of submission.
