ALTER TABLE education.threads ADD COLUMN IF NOT EXISTS context_type varchar(30);
ALTER TABLE education.threads ADD COLUMN IF NOT EXISTS context_id uuid;
ALTER TABLE education.threads ADD COLUMN IF NOT EXISTS last_activity_at timestamptz NOT NULL DEFAULT now();

UPDATE education.threads t
SET last_activity_at = COALESCE(
    (SELECT max(m.created_at) FROM education.messages m WHERE m.thread_id = t.id),
    t.created_at
)
WHERE last_activity_at = t.created_at;

CREATE INDEX IF NOT EXISTS threads_last_activity ON education.threads(organization_id, last_activity_at DESC);
