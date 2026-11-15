<?php

declare(strict_types=1);

namespace App\Education\Infrastructure;

use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\RetentionCandidateContributor as Contributor;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use App\Core\Privacy\Domain\RetentionCandidate;
use App\Education\Application\Input;
use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Enumerates the optional Education workspace as one subject-owned resource. */
final readonly class RetentionCandidateContributor implements Contributor
{
    public function __construct(
        private ConnectionInterface $db,
        private ProcessingPurposeRegistry $purposes,
        private RetentionPolicyRegistry $policies,
    ) {}

    public function code(): string
    {
        return 'education.workspace_retention';
    }

    public function resourceType(): string
    {
        return 'education.workspace';
    }

    public function supportsPurpose(string $purposeCode): bool
    {
        $purpose = $this->purposes->get($purposeCode);
        return $purpose !== null
            && in_array('education', $purpose->dataCategories, true)
            && $this->policies->get($purpose->retentionPolicyCode) !== null;
    }

    public function candidates(int $limit, ?string $subjectPersonId = null): iterable
    {
        if ($limit < 1) {
            return [];
        }
        $subject = $subjectPersonId === null ? null : Input::uuid($subjectPersonId);
        if ($subject !== null && !$this->hasWorkspace($subject)) {
            return [];
        }
        $subjects = $subject === null ? $this->subjects($limit) : [$subject];
        $result = [];
        foreach ($subjects as $personId) {
            foreach ($this->purposes->codes() as $purposeCode) {
                if (!$this->supportsPurpose($purposeCode)) {
                    continue;
                }
                $purpose = $this->purposes->get($purposeCode);
                /** @var \App\Core\Privacy\Domain\ProcessingPurposeDefinition $purpose */
                $policy = $this->policies->get($purpose->retentionPolicyCode);
                if ($policy === null) {
                    continue;
                }
                $result[] = new RetentionCandidate(
                    $personId,
                    $this->resourceType(),
                    $personId,
                    $purposeCode,
                    $policy->code,
                    $policy->trigger,
                    $this->triggeredAt($personId, $purposeCode, $policy->trigger),
                    $this->code(),
                );
                if (count($result) === $limit) {
                    return $result;
                }
            }
        }
        return $result;
    }

    /** @return list<string> */
    private function subjects(int $limit): array
    {
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT person_id::text FROM (
                SELECT person_id FROM education.teachers
                UNION SELECT person_id FROM education.students
                UNION SELECT person_id FROM education.profiles
                UNION SELECT owner_person_id FROM education.groups
                UNION SELECT owner_person_id FROM education.lessons
                UNION SELECT owner_person_id FROM education.events
                UNION SELECT owner_person_id FROM education.files
                UNION SELECT owner_person_id FROM education.lesson_reports
                UNION SELECT sender_person_id FROM education.messages
                UNION SELECT person_id FROM education.thread_members
                UNION SELECT person_id FROM education.notifications
                UNION SELECT guardian_person_id FROM education.student_guardians
                UNION SELECT guardian_person_id FROM education.parent_accounts
                UNION SELECT guardian_person_id FROM education.parent_invites
                UNION SELECT guardian_person_id FROM education.family_history
            ) subjects ORDER BY person_id LIMIT :limit
            SQL, [':limit' => $limit])->queryAll();
        return array_map(static fn(array $row): string => (string) $row['person_id'], $rows);
    }

    private function hasWorkspace(string $subject): bool
    {
        return $this->db->createCommand(<<<'SQL'
            SELECT 1 FROM education.teachers WHERE person_id = CAST(:subject AS uuid)
            UNION ALL SELECT 1 FROM education.students WHERE person_id = CAST(:subject AS uuid)
            UNION ALL SELECT 1 FROM education.profiles WHERE person_id = CAST(:subject AS uuid)
            UNION ALL SELECT 1 FROM education.groups WHERE owner_person_id = CAST(:subject AS uuid)
            UNION ALL SELECT 1 FROM education.lessons WHERE owner_person_id = CAST(:subject AS uuid)
            UNION ALL SELECT 1 FROM education.events WHERE owner_person_id = CAST(:subject AS uuid)
            UNION ALL SELECT 1 FROM education.files WHERE owner_person_id = CAST(:subject AS uuid)
            UNION ALL SELECT 1 FROM education.lesson_reports WHERE owner_person_id = CAST(:subject AS uuid)
            UNION ALL SELECT 1 FROM education.messages WHERE sender_person_id = CAST(:subject AS uuid)
            UNION ALL SELECT 1 FROM education.thread_members WHERE person_id = CAST(:subject AS uuid)
            UNION ALL SELECT 1 FROM education.notifications WHERE person_id = CAST(:subject AS uuid)
            UNION ALL SELECT 1 FROM education.student_guardians WHERE guardian_person_id = CAST(:subject AS uuid)
            UNION ALL SELECT 1 FROM education.parent_accounts WHERE guardian_person_id = CAST(:subject AS uuid)
            UNION ALL SELECT 1 FROM education.parent_invites WHERE guardian_person_id = CAST(:subject AS uuid)
            UNION ALL SELECT 1 FROM education.family_history WHERE guardian_person_id = CAST(:subject AS uuid)
            LIMIT 1
            SQL, [':subject' => $subject])->queryScalar() !== false;
    }

    private function triggeredAt(string $subject, string $purposeCode, string $trigger): ?DateTimeImmutable
    {
        $value = match ($trigger) {
            'withdrawal' => $this->db->createCommand(<<<'SQL'
                SELECT max(withdrawn_at)::text FROM core.privacy_consents
                WHERE subject_person_id = CAST(:subject AS uuid) AND purpose_code = :purpose
                    AND legacy_record = false AND withdrawn_at IS NOT NULL
                SQL, [':subject' => $subject, ':purpose' => $purposeCode])->queryScalar(),
            'account_archived' => $this->db->createCommand(<<<'SQL'
                SELECT greatest(
                    (SELECT archived_at FROM core.persons WHERE id = CAST(:subject AS uuid)),
                    (SELECT max(archived_at) FROM core.accounts WHERE person_id = CAST(:subject AS uuid))
                )::text
                SQL, [':subject' => $subject])->queryScalar(),
            'relationship_end' => $this->db->createCommand(<<<'SQL'
                SELECT max(left_at)::text FROM core.organization_memberships
                WHERE person_id = CAST(:subject AS uuid)
                SQL, [':subject' => $subject])->queryScalar(),
            'event_only' => $this->db->createCommand(<<<'SQL'
                SELECT greatest(
                    (SELECT max(created_at) FROM education.groups WHERE owner_person_id = CAST(:subject AS uuid)),
                    (SELECT max(created_at) FROM education.lessons WHERE owner_person_id = CAST(:subject AS uuid)),
                    (SELECT max(created_at) FROM education.files WHERE owner_person_id = CAST(:subject AS uuid)),
                    (SELECT max(created_at) FROM education.messages WHERE sender_person_id = CAST(:subject AS uuid)),
                    (SELECT max(created_at) FROM education.notifications WHERE person_id = CAST(:subject AS uuid)),
                    (SELECT max(updated_at) FROM education.student_guardians WHERE guardian_person_id = CAST(:subject AS uuid)),
                    (SELECT max(created_at) FROM education.parent_invites WHERE guardian_person_id = CAST(:subject AS uuid)),
                    (SELECT max(created_at) FROM education.family_history WHERE guardian_person_id = CAST(:subject AS uuid))
                )::text
                SQL, [':subject' => $subject])->queryScalar(),
            default => null,
        };
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
