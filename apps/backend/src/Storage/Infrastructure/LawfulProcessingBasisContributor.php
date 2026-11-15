<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Core\Privacy\Application\LawfulProcessingBasisContributor as Contributor;
use App\Core\Privacy\Application\ProcessingPurposeRegistry;
use App\Core\Privacy\Domain\RetentionCandidate;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Derives the non-consent Storage basis from the real Core account/membership lifecycle. */
final readonly class LawfulProcessingBasisContributor implements Contributor
{
    public function __construct(
        private ConnectionInterface $db,
        private ProcessingPurposeRegistry $purposes,
    ) {}

    public function code(): string
    {
        return 'storage.space_basis';
    }

    public function supports(string $purposeCode, string $resourceType): bool
    {
        if ($resourceType !== 'storage.space') {
            return false;
        }
        $purpose = $this->purposes->get($purposeCode);
        return $purpose !== null
            && $purpose->requiresConsent === false
            && in_array('storage', $purpose->dataCategories, true);
    }

    public function resolve(RetentionCandidate $candidate): string
    {
        if (!$this->supports($candidate->purposeCode, $candidate->resourceType)) {
            return 'unknown';
        }
        $row = $this->db->createCommand(<<<'SQL'
            SELECT
                EXISTS(
                    SELECT 1 FROM core.accounts
                    WHERE person_id = CAST(:subject AS uuid) AND account_type = 'human'
                        AND status = 'active' AND archived_at IS NULL
                ) AS active_account,
                EXISTS(
                    SELECT 1 FROM core.organization_memberships
                    WHERE person_id = CAST(:subject AS uuid) AND status = 'active'
                ) AS active_membership,
                EXISTS(
                    SELECT 1 FROM core.persons WHERE id = CAST(:subject AS uuid)
                ) AS person_exists
            SQL, [':subject' => $candidate->subjectPersonId])->queryOne();
        if (!is_array($row) || !in_array($row['person_exists'], [true, 't', 1], true)) {
            return 'unknown';
        }
        if (in_array($row['active_account'], [true, 't', 1], true)
            || in_array($row['active_membership'], [true, 't', 1], true)) {
            return 'active';
        }
        return 'inactive';
    }
}
