<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Application\RetentionCandidateContributor;
use App\Core\Privacy\Application\RetentionPolicyRegistry;
use App\Core\Privacy\Domain\RetentionCandidate;
use DateTimeImmutable;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Bounded Core resource adapter. An absent lifecycle event never becomes an invented trigger. */
final readonly class CoreResourceRetentionCandidateContributor implements RetentionCandidateContributor
{
    private const CATEGORIES = [
        'core.person_contact' => 'identity',
        'core.account_settings' => 'preferences',
        'core.organization_membership' => 'identity',
        'core.person' => 'identity',
        'core.human_account' => 'identity',
    ];

    public function __construct(
        private ConnectionInterface $db,
        private ProcessingPurposeRegistry $purposes,
        private RetentionPolicyRegistry $policies,
        private string $resourceType,
    ) {
        if (!isset(self::CATEGORIES[$resourceType])) {
            throw new DomainException('Unsupported Core retention resource.');
        }
    }

    public function code(): string
    {
        return $this->resourceType . '.retention';
    }
    public function resourceType(): string
    {
        return $this->resourceType;
    }

    public function supportsPurpose(string $purposeCode): bool
    {
        $purpose = $this->purposes->get($purposeCode);
        if ($purpose === null) {
            return false;
        }
        $policy = $this->policies->get($purpose->retentionPolicyCode);
        $root = in_array($this->resourceType, ['core.person', 'core.human_account'], true);
        return $policy !== null && ($root ? $policy->action === 'anonymize'
            : $policy->action !== 'anonymize')
            && in_array(self::CATEGORIES[$this->resourceType], $purpose->dataCategories, true);
    }

    public function candidates(int $limit, ?string $subjectPersonId = null): iterable
    {
        if ($limit < 1) {
            return [];
        }
        $sql = match ($this->resourceType) {
            'core.person_contact' => <<<'SQL'
                SELECT c.id::text AS resource_id, c.person_id::text AS subject_id,
                    p.archived_at::text AS lifecycle_at
                FROM core.person_contacts c JOIN core.persons p ON p.id = c.person_id
                WHERE (CAST(:subject AS uuid) IS NULL OR c.person_id = CAST(:subject AS uuid))
                ORDER BY c.id LIMIT :limit
                SQL,
            'core.account_settings' => <<<'SQL'
                SELECT s.account_id::text AS resource_id, a.person_id::text AS subject_id,
                    a.archived_at::text AS lifecycle_at
                FROM core.account_settings s JOIN core.accounts a ON a.id = s.account_id
                WHERE a.account_type = 'human' AND a.person_id IS NOT NULL
                    AND (CAST(:subject AS uuid) IS NULL OR a.person_id = CAST(:subject AS uuid))
                ORDER BY s.account_id LIMIT :limit
                SQL,
            'core.organization_membership' => <<<'SQL'
                SELECT m.id::text AS resource_id, m.person_id::text AS subject_id,
                    m.left_at::text AS lifecycle_at
                FROM core.organization_memberships m
                WHERE (CAST(:subject AS uuid) IS NULL OR m.person_id = CAST(:subject AS uuid))
                ORDER BY m.id LIMIT :limit
                SQL,
            'core.person' => <<<'SQL'
                SELECT p.id::text AS resource_id, p.id::text AS subject_id,
                    p.archived_at::text AS lifecycle_at
                FROM core.persons p
                WHERE (CAST(:subject AS uuid) IS NULL OR p.id = CAST(:subject AS uuid))
                ORDER BY p.id LIMIT :limit
                SQL,
            'core.human_account' => <<<'SQL'
                SELECT a.id::text AS resource_id, a.person_id::text AS subject_id,
                    a.archived_at::text AS lifecycle_at
                FROM core.accounts a
                WHERE a.account_type = 'human' AND a.person_id IS NOT NULL
                    AND (CAST(:subject AS uuid) IS NULL OR a.person_id = CAST(:subject AS uuid))
                ORDER BY a.id LIMIT :limit
                SQL,
        };
        $rows = $this->db->createCommand(
            $sql,
            [':subject' => $subjectPersonId, ':limit' => $limit],
        )->queryAll();
        $result = [];
        foreach ($rows as $row) {
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
                $triggeredAt = null;
                if ($policy->trigger === 'withdrawal') {
                    $withdrawn = $this->db->createCommand(<<<'SQL'
                        SELECT max(withdrawn_at)::text FROM core.privacy_consents
                        WHERE subject_person_id = CAST(:subject AS uuid) AND purpose_code = :purpose
                            AND legacy_record = false AND withdrawn_at IS NOT NULL
                        SQL, [':subject' => $row['subject_id'], ':purpose' => $purposeCode])->queryScalar();
                    $triggeredAt = is_string($withdrawn) ? new DateTimeImmutable($withdrawn) : null;
                } elseif (($this->resourceType === 'core.organization_membership' && $policy->trigger === 'relationship_end')
                    || (in_array($this->resourceType, ['core.account_settings', 'core.human_account'], true)
                        && $policy->trigger === 'account_archived')
                    || (in_array($this->resourceType, ['core.person_contact', 'core.person'], true)
                        && $policy->trigger === 'person_archived')) {
                    $triggeredAt = is_string($row['lifecycle_at']) ? new DateTimeImmutable($row['lifecycle_at']) : null;
                }
                $result[] = new RetentionCandidate(
                    (string) $row['subject_id'],
                    $this->resourceType,
                    (string) $row['resource_id'],
                    $purposeCode,
                    $policy->code,
                    $policy->trigger,
                    $triggeredAt,
                    $this->code(),
                );
                if (count($result) === $limit) {
                    return $result;
                }
            }
        }
        return $result;
    }
}
