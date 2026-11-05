<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Time\Application\ClockInterface;
use App\Core\Audit\Application\AuditService;
use App\Core\Audit\Application\WriteAuditEventCommand;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class LegalRepresentationService
{
    public function __construct(
        private ConnectionInterface $db,
        private AuditService $audit,
        private RepresentationScopeRegistry $scopes,
        private ClockInterface $clock,
    ) {}

    /** @param list<string> $scopeCodes */
    public function declare(
        AuthenticatedIdentity $actor,
        string $subjectPersonId,
        string $representativePersonId,
        string $authorityType,
        string $validFrom,
        ?string $validUntil,
        ?string $evidenceType,
        ?string $evidenceReference,
        ?string $evidenceSha256Hex,
        array $scopeCodes,
    ): string {
        self::assertManage($actor);
        $this->scopes->assertKnownSet($scopeCodes);
        return $this->db->transaction(function () use ($actor, $subjectPersonId, $representativePersonId, $authorityType, $validFrom, $validUntil, $evidenceType, $evidenceReference, $evidenceSha256Hex, $scopeCodes): string {
            $id = $this->db->createCommand(<<<'SQL'
                INSERT INTO core.legal_representations
                    (subject_person_id, representative_person_id, authority_type, valid_from, valid_until,
                     evidence_type, evidence_reference, evidence_sha256)
                VALUES (CAST(:subject AS uuid), CAST(:representative AS uuid), :type, CAST(:valid_from AS timestamptz),
                        CAST(:valid_until AS timestamptz), :evidence_type, :evidence_reference, decode(:hash, 'hex'))
                RETURNING id::text
                SQL, [
                ':subject' => $subjectPersonId,
                ':representative' => $representativePersonId,
                ':type' => $authorityType,
                ':valid_from' => $validFrom,
                ':valid_until' => $validUntil,
                ':evidence_type' => $evidenceType,
                ':evidence_reference' => $evidenceReference,
                ':hash' => $evidenceSha256Hex,
            ])->queryScalar();
            if (!is_string($id)) {
                throw new DomainException('Representation could not be declared.');
            }
            foreach ($scopeCodes as $scope) {
                $this->db->createCommand(
                    'INSERT INTO core.legal_representation_scopes (representation_id, scope_code) VALUES (CAST(:id AS uuid), :scope)',
                    [':id' => $id, ':scope' => $scope],
                )->execute();
            }
            $this->record($actor, 'privacy.representation.declared', $id);
            return $id;
        });
    }

    public function verify(AuthenticatedIdentity $actor, string $id): bool
    {
        self::assertManage($actor);
        return $this->db->transaction(function () use ($actor, $id): bool {
            $row = $this->db->createCommand(
                'SELECT status, valid_from, valid_until, evidence_type, evidence_reference, evidence_sha256 IS NOT NULL AS has_hash FROM core.legal_representations WHERE id = CAST(:id AS uuid) FOR UPDATE',
                [':id' => $id],
            )->queryOne();
            if (!is_array($row)) {
                throw new DomainException('Representation not found.');
            }
            if ($row['status'] === 'verified') {
                return false;
            }
            if (!in_array($row['status'], ['declared', 'verification_pending'], true)) {
                throw new DomainException('Representation cannot be verified in this state.');
            }
            if ($row['evidence_type'] === null || ($row['evidence_reference'] === null && !in_array($row['has_hash'], [true, 't', 1], true))) {
                throw new DomainException('Representation evidence is required before verification.');
            }
            $now = $this->clock->now()->format(DATE_ATOM);
            $valid = $this->db->createCommand(
                'SELECT valid_from <= CAST(:now AS timestamptz) AND (valid_until IS NULL OR valid_until >= CAST(:now AS timestamptz)) FROM core.legal_representations WHERE id = CAST(:id AS uuid)',
                [':id' => $id, ':now' => $now],
            )->queryScalar();
            if (!in_array($valid, [true, 't', 1], true)) {
                throw new DomainException('Representation is outside its validity interval.');
            }
            $this->db->createCommand(<<<'SQL'
                UPDATE core.legal_representations
                SET status = 'verified', verified_at = CAST(:now AS timestamptz),
                    verified_by_account_id = CAST(:actor AS uuid), updated_at = CAST(:now AS timestamptz)
                WHERE id = CAST(:id AS uuid)
                SQL, [':actor' => $actor->accountId, ':id' => $id, ':now' => $now])->execute();
            $this->record($actor, 'privacy.representation.verified', $id);
            return true;
        });
    }

    public function revoke(AuthenticatedIdentity $actor, string $id, string $reasonCode): bool
    {
        self::assertManage($actor);
        return $this->db->transaction(function () use ($actor, $id, $reasonCode): bool {
            $row = $this->db->createCommand(
                'SELECT status FROM core.legal_representations WHERE id = CAST(:id AS uuid) FOR UPDATE',
                [':id' => $id],
            )->queryOne();
            if (!is_array($row)) {
                throw new DomainException('Representation not found.');
            }
            if ($row['status'] === 'revoked') {
                return false;
            }
            if ($row['status'] !== 'verified') {
                throw new DomainException('Only verified representation can be revoked.');
            }
            $now = $this->clock->now()->format(DATE_ATOM);
            $this->db->createCommand(<<<'SQL'
                UPDATE core.legal_representations
                SET status = 'revoked', revoked_at = CAST(:now AS timestamptz),
                    revoked_by_account_id = CAST(:actor AS uuid),
                    revocation_reason_code = :reason, updated_at = CAST(:now AS timestamptz)
                WHERE id = CAST(:id AS uuid)
                SQL, [':actor' => $actor->accountId, ':id' => $id, ':reason' => $reasonCode, ':now' => $now])->execute();
            $this->record($actor, 'privacy.representation.revoked', $id);
            return true;
        });
    }

    private static function assertManage(AuthenticatedIdentity $actor): void
    {
        if (!$actor->hasRole('core.superadmin')
            && !in_array('core.privacy.representations.manage', $actor->permissions, true)) {
            throw new DomainException('Representation management permission is required.');
        }
    }

    private function record(AuthenticatedIdentity $actor, string $action, string $id): void
    {
        $this->audit->record(new WriteAuditEventCommand(
            actorType: 'account',
            actorAccountId: $actor->accountId,
            action: $action,
            resourceType: 'core.legal_representation',
            resourceId: $id,
        ));
    }
}
