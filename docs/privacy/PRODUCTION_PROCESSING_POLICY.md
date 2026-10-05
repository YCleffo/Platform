# Production processing policy

This document describes the machine-readable deployment contract. The authoritative file is the JSON path in `PRIVACY_CONFIG_FILE`; the loader rejects incomplete files and template sentinel values. The complete shape is [privacy-deployment.example.json](../../docs/privacy-deployment.example.json).

The versioned example describes shape only. `apps/backend/tests/Fixtures/privacy/synthetic-production-policy.json` is a deliberately synthetic CI loader fixture. Neither is a production policy or legal evidence. The real operator-managed `deployment/privacy/deployment.json` remains outside Git and must be supplied through the deployment secret/configuration boundary.

## Repository-defined facts

The application already defines the vocabulary and behaviour for data categories, subject categories, purpose codes, legal-basis codes, processing roles, retention actions/units, processor and recipient declarations, transfer decisions, distribution-consent rules, legal holds, restriction, erasure, anonymization and audit evidence. Loaded definitions are passed into the existing registries; services do not contain a second policy.

The loader also enforces that every purpose has a non-empty description, legal basis, data and subject categories, retention policy, processing role, and processing-information definition. References must resolve within the same deployment file. Distribution remains deny-by-default, and cross-border transfers remain blocked unless the existing transfer policy permits them.

## Operator/legal deployment inputs

These values cannot be derived from source code and must be approved and supplied in the named JSON fields:

| Decision | Required field(s) | Expected value/domain |
| --- | --- | --- |
| Organizational purpose and explanation | `processingPurposeDefinitions[].code`, `.description` | Stable machine code and plain-language approved description |
| Data and subject scope | `dataCategoryDefinitions[]`, `subjectCategoryDefinitions[]`, `processingPurposeDefinitions[].dataCategories`, `.subjectCategories` | Complete reviewed vocabularies and references |
| Lawful basis | `processingPurposeDefinitions[].legalBasisCode`, `.requiresConsent` | One supported code; consent must agree with `requiresConsent` |
| Role and operations | `privacyProcessingRoleDefinitions[]` | Operator/processor role, references, categories and permitted operations |
| Retention | `retentionPolicyDefinitions[]` and each purpose’s `.retentionPolicyCode` | Reviewed trigger, duration/unit, action and legal-hold rule; no duration is inferred |
| Processors/recipients | `privacyProcessorDefinitions[]`, `privacyRecipientDefinitions[]` | Actual identity/reference, country, contract, categories and active dates |
| Cross-border processing | `processingPurposeDefinitions[].crossBorderPolicy`, `privacyTransferDefinitions[]` | Actual routing, country, notification/evidence and regulator decision |
| Public distribution | `processingPurposeDefinitions[].distributionAllowed`, `privacyDistributionConsentDefinitions[]` | Explicit approval, allowed categories/fields, document version and digest; otherwise deny |
| Erasure/anonymization and holds | Retention action fields plus existing legal-hold and erasure configuration | Reviewed action/handler semantics; holds remain blockers |
| Operator evidence | `privacyOperatorIdentity`, notification, security, residency, destruction and legal-evidence sections | Current organization records, references, dates and secrets |

Do not use the example file in production. Its angle-bracket values are intentionally rejected as unresolved placeholders. Legal basis, retention period, processor identity, transfer status, distribution permission and organizational purpose remain unresolved until the operator/legal owner supplies them.
