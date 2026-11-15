# Education foundation

Education owns the organization-scoped learning catalog and teaching workflow. Core continues to own Person, Account, organization membership, authentication, RBAC and audit. `core.modules` describes installable Platform modules; `education.curriculum_modules` describes teaching content. Their IDs and lifecycles are independent.

## Learning model

An organization has a shared catalog of programs, ordered curriculum modules and ordered curriculum lessons. A group has an immutable creator plus explicitly assigned teachers and a roster of Education students referencing Core Person. Assigning a program records its complete catalog snapshot. A scheduled lesson may reference a lesson from that assigned snapshot. Attendance, completed sessions and corrected student results remain separate facts.

Group completion counts distinct curriculum lessons with a completed scheduled session. Student completion counts distinct curriculum lessons with a saved `mastered` result in a completed session. Repeating a session cannot inflate the count. Correcting a result changes the derived student progress. The API also exposes module and program states (`not_started`, `in_progress`, `completed`). No manually writable progress counter exists.

Catalog changes create a revision. Editing, reordering or archiving a catalog item leaves existing group and scheduled lesson snapshots intact. Reassigning a changed program ends the previous assignment and creates a new snapshot. Old results retain their original assignment. Archived catalog content cannot be newly assigned.

## Ownership and access

Existing `owner_account_id` fields and foreign keys are retained for compatibility. `group_teachers` adds collaboration without making every group visible to all organization members. Queries check the selected organization and the actual group assignment. For existing owner-keyed writes, the application resolves the record owner after checking the authenticated teacher's access. Audit events and result revisions retain the actual authenticated actor separately.

Only a group owner can change its teacher assignments, and only active members with an Education teacher assignment from that organization are eligible. Removing an assignment removes access to the group's API. Cross-owner group moves remain explicitly rejected by the existing ownership contract; no learning history is moved silently between owners.

`POST /students/new` checks `education.students.create`, active organization membership, teacher assignment and access to a mutable group. It calls Core `CreatePerson` and `OrganizationRepository.addMember`, then enrolls the new person within one transaction. It does not grant general Core administrator rights or create another identity system. Existing Core people can still be selected. Co-teachers use the same group-owned Education student records.

The student cabinet authenticates through Core. Its own curriculum progress derives `studentId` from `studentScope`; the request cannot supply another student's identity. The shared shell header accepts a verified student scope and returns only that person's metadata. Student sign-in opens the student cabinet. Teacher and student mobile navigation reuse Platform `AppBottomNav`.

## Migrations and API

`008_curriculum_foundation.sql` adds programs, modules, curriculum lessons, revisions, group teacher assignments, program snapshots and scheduled lesson bindings. Existing group owners are backfilled. It adds the bounded `education.students.create` permission to the teacher role.

`009_curriculum_integrity.sql` enforces organization/group/assignment/curriculum-lesson consistency at PostgreSQL trigger level. Existing unbound lessons remain valid. No existing migration is modified.

The [Education OpenAPI contract](../../contracts/openapi/education.yaml) documents the catalog, assignment, teacher, bounded person creation and progress endpoints, plus optional scheduled lesson bindings. All private HTTP responses retain `Cache-Control: no-store`.

Run the normal guarded Education migration command with the migration owner's connection before starting the new backend against an existing installation. Reapply that installation's existing runtime-role provisioning for the new Education tables. Local fixture startup does both schema initialization and fixture creation on its own PostgreSQL; it does not migrate the working installation.

## Old product reference

The previous source was inspected at `C:\OSPanel6\OSPanel\home\platform.local`, including the six requested staff views/stores, Yii2 GroupModule/Lesson models and API controllers, and SQL structures. The specified ZIP archives were not supplied; current `C:\Platform` was used as the implementation source. Images were inspected at the user-provided `C:\OSPanel6\OSPanel\home\MyPortfolioNew\docs\Prototype\Platform` path.

| Previous scenario | Current foundation |
| --- | --- |
| Group module planned/active/completed | Versioned group program snapshot and fact-derived module progress |
| Module lessons and lesson number | Ordered catalog lessons plus actual scheduled sessions |
| Group/date attendance selection | Group weekly attendance matrix and individual lesson roster |
| Module/group/student details | Program/module editor, group roster/teachers/program, student card/progress |
| Calendar week and time cells | Day/week time grid, real lesson/event links and details |
| Homework/results and training | Existing activities/attempts and lesson reports/results, preserved independently of attendance |

The old blanket blue background, swallowed API errors and manually completed progress flags were not carried into the new workflow. Layout, themes and accent colors use existing Platform semantic tokens.

## Local verification

See the [local acceptance report](../operations/EDUCATION_FOUNDATION_LOCAL.md), [Windows acceptance matrix](../../apps/agent/VM_ACCEPTANCE.md) and [isolated environment instructions](../../scripts/dev/education/README.md). This foundation is not a release audit or proof of native Windows/prelogin behavior.
