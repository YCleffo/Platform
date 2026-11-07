-- Education lesson outcomes: one report per completed lesson and one result per student,
-- kept strictly apart from attendance. Result corrections append revisions instead of
-- overwriting history; result rows survive group roster changes because they reference
-- the stable lesson and student rows.
CREATE TABLE education.lesson_reports (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL,
    owner_account_id uuid NOT NULL, owner_person_id uuid NOT NULL, lesson_id uuid NOT NULL,
    topic varchar(240) NOT NULL CHECK (btrim(topic) <> ''),
    summary text NOT NULL CHECK (length(summary) BETWEEN 1 AND 4000),
    teacher_comment text CHECK (teacher_comment IS NULL OR length(teacher_comment) BETWEEN 1 AND 4000),
    updated_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (lesson_id, organization_id, owner_account_id) REFERENCES education.lessons(id, organization_id, owner_account_id),
    UNIQUE (lesson_id, organization_id, owner_account_id),
    UNIQUE (id, organization_id, owner_account_id)
);
CREATE INDEX lesson_reports_lesson ON education.lesson_reports(lesson_id);
CREATE TABLE education.lesson_results (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL,
    owner_account_id uuid NOT NULL, lesson_id uuid NOT NULL, student_id uuid NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','mastered','developing','needs_support')),
    comment text CHECK (comment IS NULL OR length(comment) BETWEEN 1 AND 1000),
    updated_at timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (lesson_id, organization_id, owner_account_id) REFERENCES education.lessons(id, organization_id, owner_account_id),
    FOREIGN KEY (student_id, organization_id, owner_account_id) REFERENCES education.students(id, organization_id, owner_account_id),
    UNIQUE (lesson_id, student_id)
);
CREATE INDEX lesson_results_student ON education.lesson_results(student_id, lesson_id);
CREATE TABLE education.result_revisions (
    id uuid PRIMARY KEY DEFAULT uuidv7(), organization_id uuid NOT NULL,
    owner_account_id uuid NOT NULL, result_id uuid NOT NULL REFERENCES education.lesson_results(id),
    status varchar(20) NOT NULL CHECK (status IN ('pending','mastered','developing','needs_support')),
    comment text CHECK (comment IS NULL OR length(comment) BETWEEN 1 AND 1000),
    revised_at timestamptz NOT NULL DEFAULT now(), revised_by_account_id uuid NOT NULL
);
CREATE INDEX result_revisions_result ON education.result_revisions(result_id, revised_at, id);
