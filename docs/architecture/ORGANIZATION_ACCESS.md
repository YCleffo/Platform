# Organization access foundation

`Person != Account`: a person can belong to several organizations without an account. An authenticated account resolves to its person for organization membership checks. `Organization != Commercial Account`: the latter belongs to the Control Plane, with no cross-database foreign key or duplicate source of truth. Provisioning mapping is outside this phase.

`Membership != domain role`: membership records only the person's active or ended association. It does not grant student, teacher, parent, or administrative roles. `RBAC != organization scope`: organization operations require both the relevant permission and active membership in an active organization. `core.superadmin` retains platform-wide access; an arbitrary API `organizationId` never grants scope.

The existing `/api/core/persons` and contact routes are platform administration. Education APIs must introduce their own organization and domain relationship checks before exposing student, teacher, or parent records; they must not reuse the global Person list as a domain endpoint.

Membership export uses the existing personal data contributor contract. Explicit erasure uses its own ownership-checked handler; the database restricts Person deletion and does not cascade memberships. The existing Privacy workflow determines legal holds and retention eligibility before invoking a handler. Corrections to association state use the membership lifecycle endpoint; no automatic retention period or background cleanup is introduced here.
