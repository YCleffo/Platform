-- ============================================================================
-- 002 — Device privacy receipts
-- Idempotent evidence for an erasure item handled by DeviceManagement.
-- ============================================================================
CREATE TABLE device_management.privacy_receipts (
    item_id uuid PRIMARY KEY, result varchar(32) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now()
);
