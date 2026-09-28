<?php

declare(strict_types=1);

namespace App\Core\Privacy\Infrastructure;

use App\Core\Privacy\Application\RepresentativeAuthorityPolicy;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresRepresentativeAuthorityPolicy implements RepresentativeAuthorityPolicy
{
    public function __construct(private ConnectionInterface $db) {}

    public function assertActiveForAction(string $id, string $subjectPersonId, string $representativePersonId, string $actionCode): void
    {
        $valid = $this->db->createCommand(<<<'SQL'
            SELECT r.id FROM core.legal_representations r
            JOIN core.legal_representation_scopes s ON s.representation_id = r.id
            WHERE r.id = CAST(:id AS uuid) AND s.scope_code = :scope
              AND r.subject_person_id = CAST(:subject AS uuid)
              AND r.representative_person_id = CAST(:representative AS uuid)
              AND r.status = 'verified' AND r.verified_at IS NOT NULL AND r.revoked_at IS NULL
              AND r.valid_from <= now() AND (r.valid_until IS NULL OR r.valid_until >= now())
            FOR SHARE OF r
            SQL, [':id' => $id, ':subject' => $subjectPersonId, ':representative' => $representativePersonId, ':scope' => $actionCode])->queryScalar();
        if ($valid === false || $valid === null) {
            throw new DomainException('Verified representative authority with the required scope is required.');
        }
    }
}
