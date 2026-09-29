<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Privacy\Domain\RetentionCandidate;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class LawfulProcessingBasisResolver
{
    public function __construct(private ProcessingPurposeRegistry $purposes,
        private LawfulProcessingBasisContributorRegistry $contributors,
        private ConnectionInterface $db) {}

    public function resolve(RetentionCandidate $candidate): string
    {
        $purpose = $this->purposes->get($candidate->purposeCode);
        if ($purpose === null) {
            return 'unknown';
        }
        if (!$purpose->requiresConsent) {
            return $this->contributors->resolve($candidate);
        }
        try {
            $active = $this->db->createCommand(<<<'SQL'
                SELECT 1 FROM core.privacy_consents
                WHERE subject_person_id = CAST(:subject AS uuid) AND purpose_code = :purpose
                  AND legacy_record = false AND withdrawn_at IS NULL
                LIMIT 1
                SQL, [':subject' => $candidate->subjectPersonId,
                    ':purpose' => $candidate->purposeCode])->queryScalar();
        } catch (\Throwable) {
            return 'unknown';
        }
        if ($active !== false && $active !== null) {
            return 'active';
        }
        return $this->contributors->hasSupporting($candidate)
            ? $this->contributors->resolve($candidate) : 'inactive';
    }
}
