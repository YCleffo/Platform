-- Education lifecycle: archived groups, explicit lesson completion/cancellation timestamps,
-- and indexes used by membership reconciliation and group history lookups.
ALTER TABLE education.groups ADD COLUMN archived_at timestamptz;
ALTER TABLE education.lessons ADD COLUMN completed_at timestamptz;
ALTER TABLE education.lessons ADD COLUMN cancelled_at timestamptz;
CREATE INDEX group_students_student ON education.group_students(student_id, group_id);
CREATE INDEX lessons_group_status_time ON education.lessons(group_id, status, starts_at, id);
