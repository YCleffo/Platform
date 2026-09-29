# Legal holds

Migration 024 adds `core.privacy_legal_holds`. Holds cover a subject, purpose, or semantic resource. A hold is active when created, unreleased, and unexpired according to `ClockInterface`. Duplicate active creation returns the same ID; release is idempotent. Expiry and release remove its blocking effect from future retention evaluations while preserving evidence.

Management endpoints are `POST/GET /api/core/privacy/legal-holds`, `GET /api/core/privacy/legal-holds/{id}`, and `POST /api/core/privacy/legal-holds/{id}/release`. Read and manage permissions are separate. Inputs permit only UUIDs, machine codes, timestamps, and a bounded machine-readable source reference. A hold blocks destructive eligibility and can coexist with a processing block.
