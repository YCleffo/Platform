# Processors, recipients and outbound transfer

Core defines processor and recipient registrations with purpose, category, country, contract/evidence reference, security and localization confirmations, and effective dates. Transfer decisions are separate and default to deny. `CrossBorderTransferPolicy` rejects unknown SMTP providers and any missing or unapproved transfer decision before sending. No contract text is stored in the database.

| Integration | Possible outbound data | Deployment decision |
| --- | --- | --- |
| SMTP | Recipient email/name and rendered message, which may contain security links | Register provider, purposes, categories, country and transfer decision. |
| Google auth | Login request, callback identifiers and profile claims | Register identity provider and classify permitted claims before enabling. |
| Telegram auth | Telegram identifier and callback profile fields | Register provider and permitted fields before enabling. |
| GeoIP | Network address sent to a lookup provider if external | Register provider/location or keep lookup local. |
| YooKassa (Control Plane) | Payment, billing contact, receipt and fiscal fields | Maintain a separate Control Plane provider decision and contractual evidence. |

Deployment must verify actual provider routing and country. A country code in application configuration alone does not prove routing. For `processor_by_instruction`, the role record must include instruction/contract reference, permitted categories and operations, security confirmation, incident notification and deletion/return obligation. Unknown role or transfer status is a readiness failure.

Control Plane has a separate `ControlProviderEgressPolicy` at concrete SMTP and YooKassa payment, refund and fiscal receipt gateways. An allowed decision must match purpose and data categories, name a country and evidence reference, and mark a non-RU transfer as notified. Missing or blocked decisions stop the outbound request. Its deployment configuration is independent of Core's registry.
