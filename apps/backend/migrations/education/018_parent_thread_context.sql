-- Migration 018: Enforce parent thread context isolation
-- Back-fill context_id for threads that were started without it (pre-EDUCATION_07 threads).
-- For threads where the sender is a known guardian, infer context from their unique student link.
-- Threads with ambiguous (multiple students) or no guardian sender remain untouched and become
-- inaccessible to the parent scope (see ParentReadService.threads requiring context_id = student).

UPDATE education.threads t
SET context_id   = sq.student_id,
    context_type = 'student'
FROM (
    SELECT
        t2.id      AS thread_id,
        sg.student_id
    FROM education.threads t2
    JOIN education.thread_members tm
        ON tm.thread_id = t2.id
    JOIN education.student_guardians sg
        ON sg.guardian_person_id = tm.person_id
        AND sg.organization_id   = t2.organization_id
        AND sg.status            = 'confirmed'
    WHERE t2.context_id   IS NULL
      AND t2.organization_id IS NOT NULL
    GROUP BY t2.id, sg.student_id
    HAVING count(DISTINCT sg.student_id) = 1
) sq
WHERE t.id = sq.thread_id
  AND t.context_id IS NULL;
