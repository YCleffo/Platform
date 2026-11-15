-- Education stores the relationship; identity and contacts remain in Core.
CREATE TABLE education.student_guardians (
    organization_id uuid NOT NULL,
    student_id uuid NOT NULL,
    guardian_person_id uuid NOT NULL REFERENCES core.persons(id),
    relationship varchar(12) NOT NULL CHECK(relationship IN ('mother','father','guardian')),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY(student_id,guardian_person_id),
    FOREIGN KEY(student_id,organization_id) REFERENCES education.students(id,organization_id)
);
CREATE INDEX student_guardian_person ON education.student_guardians(guardian_person_id,organization_id);
