-- 006 — Per-attempt configuration for Education activities
-- Stores the validated start configuration (duration, language, …) of each
-- attempt so the finish handler can verify parameters without trusting the client.
ALTER TABLE education.activity_attempts ADD COLUMN config jsonb NOT NULL DEFAULT '{}';
