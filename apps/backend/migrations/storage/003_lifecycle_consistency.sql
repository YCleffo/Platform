ALTER TABLE storage.tickets ADD COLUMN base_version_id uuid;
ALTER TABLE storage.tickets ADD COLUMN base_deleted_at timestamptz;
-- Old outstanding tickets lack optimistic concurrency evidence and must be reissued.
UPDATE storage.tickets SET expires_at=now() WHERE used_at IS NULL;
ALTER TABLE storage.conflicts ADD COLUMN manifest_id uuid REFERENCES storage.manifests(id);
ALTER TABLE storage.spaces ADD COLUMN subject_person_id uuid;
UPDATE storage.spaces s SET subject_person_id=st.person_id FROM education.students st WHERE st.id=s.student_id;
CREATE TABLE storage.binary_cleanup (
    storage_key uuid PRIMARY KEY, queued_at timestamptz NOT NULL DEFAULT now(),
    completed_at timestamptz, attempts integer NOT NULL DEFAULT 0, last_error varchar(160)
);
CREATE INDEX storage_cleanup_pending ON storage.binary_cleanup(queued_at) WHERE completed_at IS NULL;
