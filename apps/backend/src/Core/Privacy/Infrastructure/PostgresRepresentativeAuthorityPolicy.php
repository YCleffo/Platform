<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\RepresentativeAuthorityPolicy;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresRepresentativeAuthorityPolicy implements RepresentativeAuthorityPolicy
{
    public function __construct(private ConnectionInterface $db) {}

    public function assertActiveForConsent(string $id, string $subjectPersonId, string $representativePersonId): void
    {
        $valid = $this->db->createCommand(<<<'SQL'
            SELECT id FROM core.legal_representations
            WHERE id = CAST(:id AS uuid)
              AND subject_person_id = CAST(:subject AS uuid)
              AND representative_person_id = CAST(:representative AS uuid)
              AND status = 'verified' AND verified_at IS NOT NULL AND revoked_at IS NULL
              AND valid_from <= now() AND (valid_until IS NULL OR valid_until >= now())
            FOR SHARE
            SQL, [':id' => $id, ':subject' => $subjectPersonId, ':representative' => $representativePersonId])->queryScalar();
        if ($valid === false || $valid === null) {
            throw new DomainException('Verified representative authority is required.');
        }
    }
}
