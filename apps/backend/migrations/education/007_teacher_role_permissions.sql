-- 007 — Grant newer Education permissions to the teacher role
-- The education.teacher wildcard grant from 001 predates the student portal and
-- activities permissions; grant them explicitly without touching old migrations.
INSERT INTO core.role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM core.roles r CROSS JOIN core.permissions p
WHERE r.code='education.teacher' AND p.code IN ('education.student.use','education.activities.use',
    'education.activities.manage','education.devices.manage')
    ON CONFLICT DO NOTHING;
