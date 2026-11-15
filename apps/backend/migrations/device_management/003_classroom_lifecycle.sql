-- ============================================================================
-- 003 — Classroom lifecycle, archiving and unique workstation numbers
-- ============================================================================
ALTER TABLE device_management.classrooms ADD COLUMN IF NOT EXISTS archived_at timestamptz;
CREATE UNIQUE INDEX IF NOT EXISTS devices_classroom_number ON device_management.devices(classroom_id, number)
    WHERE classroom_id IS NOT NULL AND number <> '';

