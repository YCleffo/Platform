<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Core\Privacy\Application\PersonalDataContributor as Contributor;
use App\Core\Privacy\Application\PersonalDataExportContext;
use App\Core\Privacy\Application\PersonalDataSection;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PersonalDataContributor implements Contributor
{
    public function __construct(private ConnectionInterface $db) {}
    public function code(): string
    {
        return 'education.workspace';
    }
    public function categories(): array
    {
        return ['education'];
    }

    public function collect(PersonalDataExportContext $context): PersonalDataSection
    {
        // Exercise snapshots contain private answer keys; only the subject's input and confirmed metrics are exported.
        $queries = [
            'file_transfer' => 'SELECT id::text,organization_id::text AS "organizationId",kind,name,expected_size AS "expectedSize",
                expected_sha256 AS "expectedSha256",status,created_at::text AS "createdAt",confirmed_at::text AS "confirmedAt"
                FROM education.file_transfers WHERE person_id=CAST(:subject AS uuid)',

            'activity_attempt' => 'SELECT a.id::text,a.organization_id::text AS "organizationId",d.code AS "activityCode",
                a.contract_version AS "contractVersion",a.config,a.client_state AS "clientState",a.state_version AS "stateVersion",
                a.status,a.started_at::text AS "startedAt",a.finished_at::text AS "finishedAt",a.revoked_at::text AS "revokedAt"
                FROM education.activity_attempts a JOIN education.students s ON s.id=a.student_id
                JOIN education.activity_definitions d ON d.id=a.activity_id WHERE s.person_id=CAST(:subject AS uuid)',
            'activity_result' => 'SELECT r.attempt_id::text AS "attemptId",r.type AS "activityType",r.version,r.metrics,r.passed,r.created_at::text AS "createdAt"
                FROM education.activity_results r JOIN education.students s ON s.id=r.student_id WHERE s.person_id=CAST(:subject AS uuid)',
            'achievement_event' => 'SELECT e.id::text,e.source_type AS "sourceType",e.source_id::text AS "sourceId",
                e.occurred_at::text AS "occurredAt",e.revoked_at::text AS "revokedAt"
                FROM education.achievement_events e JOIN education.students s ON s.id=e.student_id WHERE s.person_id=CAST(:subject AS uuid)',
            'achievement_unlock' => 'SELECT u.id::text,d.code,d.rule_version AS "ruleVersion",d.name,d.description,
                u.source_event_id::text AS "sourceEventId",u.unlocked_at::text AS "unlockedAt",u.revoked_at::text AS "revokedAt"
                FROM education.achievement_unlocks u JOIN education.achievement_definitions d ON d.id=u.definition_id
                JOIN education.students s ON s.id=u.student_id WHERE s.person_id=CAST(:subject AS uuid)',

            'student_guardian' => 'SELECT g.student_id::text AS "studentId",g.guardian_person_id::text AS "guardianPersonId",g.organization_id::text AS "organizationId",g.relationship,g.status,
                    g.confirmed_at::text AS "confirmedAt",g.revoked_at::text AS "revokedAt",g.updated_at::text AS "updatedAt"
                FROM education.student_guardians g JOIN education.students s ON s.id=g.student_id
                WHERE s.person_id=CAST(:subject AS uuid) OR g.guardian_person_id=CAST(:subject AS uuid)',
            'parent_invitation' => 'SELECT i.id::text,i.organization_id::text AS "organizationId",i.student_id::text AS "studentId",
                    i.guardian_person_id::text AS "guardianPersonId",i.created_at::text AS "createdAt",i.expires_at::text AS "expiresAt",
                    i.used_at::text AS "usedAt",i.revoked_at::text AS "revokedAt"
                FROM education.parent_invites i JOIN education.students s ON s.id=i.student_id
                WHERE i.guardian_person_id=CAST(:subject AS uuid) OR s.person_id=CAST(:subject AS uuid)',
            'parent_account' => 'SELECT organization_id::text AS "organizationId",account_id::text AS "accountId",
                    activated_at::text AS "activatedAt",revoked_at::text AS "revokedAt"
                FROM education.parent_accounts WHERE guardian_person_id=CAST(:subject AS uuid)',
            'family_history' => 'SELECT h.id::text,h.organization_id::text AS "organizationId",h.student_id::text AS "studentId",
                    h.guardian_person_id::text AS "guardianPersonId",h.action,h.relationship,h.status,h.created_at::text AS "createdAt"
                FROM education.family_history h JOIN education.students s ON s.id=h.student_id
                WHERE h.guardian_person_id=CAST(:subject AS uuid) OR s.person_id=CAST(:subject AS uuid)
                    OR h.actor_account_id IN (SELECT id FROM core.accounts WHERE person_id=CAST(:subject AS uuid))',
            'homework_recipient' => 'SELECT h.id::text,h.title,h.status,h.due_at::text AS "dueAt",r.assigned_at::text AS "assignedAt"
                FROM education.homework_recipients r JOIN education.homework_assignments h ON h.id=r.assignment_id WHERE r.person_id=CAST(:subject AS uuid)',
            'homework_answer' => 'SELECT v.id::text,v.text,v.sequence,v.assignment_snapshot AS "assignmentSnapshot",v.activity_attempt_id::text AS "activityAttemptId",v.submitted_at::text AS "submittedAt",v.late
                FROM education.homework_submission_versions v JOIN education.homework_submissions s ON s.id=v.submission_id
                JOIN education.homework_recipients r ON r.id=s.recipient_id WHERE r.person_id=CAST(:subject AS uuid)',
            'homework_review' => 'SELECT rv.id::text,rv.decision,rv.result_status AS "resultStatus",rv.comment,rv.created_at::text AS "createdAt"
                FROM education.homework_reviews rv JOIN education.homework_submissions s ON s.id=rv.submission_id
                JOIN education.homework_recipients r ON r.id=s.recipient_id WHERE r.person_id=CAST(:subject AS uuid)',
            'homework_draft' => 'SELECT s.id::text,s.draft_text AS text,s.state,s.updated_at::text AS "updatedAt"
                FROM education.homework_submissions s JOIN education.homework_recipients r ON r.id=s.recipient_id WHERE r.person_id=CAST(:subject AS uuid)',
            'group_teacher_assignment' => 'SELECT gt.group_id::text AS "groupId",gt.organization_id::text AS "organizationId",gt.assigned_at::text AS "assignedAt"
                FROM education.group_teachers gt JOIN core.accounts a ON a.id=gt.account_id WHERE a.person_id=CAST(:subject AS uuid)',
            'program_assignment_author' => 'SELECT gp.id::text,gp.group_id::text AS "groupId",gp.program_id::text AS "programId",
                gp.assigned_at::text AS "assignedAt",gp.ended_at::text AS "endedAt"
                FROM education.group_programs gp JOIN core.accounts a ON a.id=gp.assigned_by_account_id WHERE a.person_id=CAST(:subject AS uuid)',
            'teacher' => 'SELECT id::text,organization_id::text AS "organizationId",created_at::text AS "createdAt"
                FROM education.teachers WHERE person_id=CAST(:subject AS uuid)',
            'profile' => 'SELECT id::text,display_name AS "displayName",avatar_file_id::text AS "avatarFileId"
                FROM education.profiles WHERE person_id=CAST(:subject AS uuid)',
            'enrollment' => 'SELECT id::text,organization_id::text AS "organizationId",created_at::text AS "createdAt"
                FROM education.students WHERE person_id=CAST(:subject AS uuid)',
            'attendance' => 'SELECT a.lesson_id::text AS "lessonId",a.student_id::text AS "studentId",a.status
                FROM education.attendance a JOIN education.students s ON s.id=a.student_id WHERE s.person_id=CAST(:subject AS uuid)',
            'group' => 'SELECT id::text,organization_id::text AS "organizationId",name,archived_at::text AS "archivedAt"
                FROM education.groups WHERE owner_person_id=CAST(:subject AS uuid)',
            'lesson' => 'SELECT id::text,title,starts_at::text AS "startsAt",ends_at::text AS "endsAt",location,status,
                completed_at::text AS "completedAt",cancelled_at::text AS "cancelledAt"
                FROM education.lessons WHERE owner_person_id=CAST(:subject AS uuid)',
            'event' => 'SELECT id::text,title,kind,starts_at::text AS "startsAt",ends_at::text AS "endsAt",location
                FROM education.events WHERE owner_person_id=CAST(:subject AS uuid)',
            'file' => 'SELECT id::text,name,mime,purpose,size_bytes AS "sizeBytes",sha256 FROM education.files WHERE owner_person_id=CAST(:subject AS uuid)',
            'message' => 'SELECT id::text,thread_id::text AS "threadId",body,created_at::text AS "createdAt"
                FROM education.messages WHERE sender_person_id=CAST(:subject AS uuid)',
            'notification' => 'SELECT id::text,thread_id::text AS "threadId",created_at::text AS "createdAt",read_at::text AS "readAt"
                FROM education.notifications WHERE person_id=CAST(:subject AS uuid)',
            'report' => 'SELECT id::text,lesson_id::text AS "lessonId",topic,summary,teacher_comment AS "teacherComment",updated_at::text AS "updatedAt"
                FROM education.lesson_reports WHERE owner_person_id=CAST(:subject AS uuid)',
            'result_author' => 'SELECT r.id::text,r.lesson_id::text AS "lessonId",r.student_id::text AS "studentId",r.status,r.comment,r.updated_at::text AS "updatedAt"
                FROM education.lesson_results r JOIN core.accounts a ON a.id=r.owner_account_id WHERE a.person_id=CAST(:subject AS uuid)',
            'result_student' => 'SELECT r.id::text,r.lesson_id::text AS "lessonId",r.student_id::text AS "studentId",r.status,r.comment,r.updated_at::text AS "updatedAt"
                FROM education.lesson_results r JOIN education.students s ON s.id=r.student_id WHERE s.person_id=CAST(:subject AS uuid)',
            'result_revision' => 'SELECT v.id::text,v.result_id::text AS "resultId",v.status,v.comment,v.revised_at::text AS "revisedAt"
                FROM education.result_revisions v JOIN core.accounts a ON a.id=v.revised_by_account_id WHERE a.person_id=CAST(:subject AS uuid)',
        ];
        $records = [];
        foreach ($queries as $type => $sql) {
            $rows = $this->db->createCommand($sql . ' LIMIT 1001', [':subject' => $context->subjectPersonId])->queryAll();
            if (count($rows) > 1000) {
                throw new DomainException('Education export requires a dedicated large-export workflow.');
            }
            /** @var list<array<string,mixed>> $rows */
            foreach ($rows as $row) {
                $records[] = ['type' => $type, ...$row];
            }
        }
        return new PersonalDataSection($this->code(), $this->categories(), $records);
    }
}
