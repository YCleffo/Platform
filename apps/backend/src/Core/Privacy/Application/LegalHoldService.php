<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Audit\Application\AuditService;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Domain\MachineCode;
use App\Core\Privacy\Domain\RetentionCandidate;
use App\Core\Time\Application\ClockInterface;
use DateTimeImmutable;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class LegalHoldService
{
    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
        private AuditService $audit,
    ) {}

    public function create(
        AuthenticatedIdentity $actor,
        ?string $subjectPersonId,
        string $scopeType,
        ?string $purposeCode,
        ?string $resourceType,
        ?string $resourceId,
        string $reasonCode,
        ?string $sourceType = null,
        ?string $sourceReference = null,
        ?DateTimeImmutable $expiresAt = null,
    ): string {
        foreach ([$purposeCode, $resourceType, $reasonCode, $sourceType] as $code) {
            if ($code !== null) {
                MachineCode::assert($code);
            }
        }
        if (!match ($scopeType) {
            'subject' => $subjectPersonId !== null && $purposeCode === null && $resourceType === null && $resourceId === null,
            'purpose' => $purposeCode !== null && $resourceType === null && $resourceId === null,
            'resource' => $resourceType !== null && $resourceId !== null,
            default => false,
        }) {
            throw new DomainException('Invalid legal hold scope.');
        }
        if ($sourceReference !== null && (strlen($sourceReference) > 160
            || preg_match('/^[a-zA-Z0-9._:\/-]+$/D', $sourceReference) !== 1)) {
            throw new DomainException('Invalid legal hold source reference.');
        }
        $now = $this->clock->now();
        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new DomainException('Hold expiration must follow creation.');
        }
        return $this->db->transaction(function () use (
            $actor,
            $subjectPersonId,
            $scopeType,
            $purposeCode,
            $resourceType,
            $resourceId,
            $reasonCode,
            $sourceType,
            $sourceReference,
            $expiresAt,
            $now
        ): string {
            if ($subjectPersonId !== null) {
                $subject = $this->db->createCommand(
                    'SELECT id FROM core.persons WHERE id = CAST(:id AS uuid) FOR SHARE',
                    [':id' => $subjectPersonId],
                )->queryScalar();
                if ($subject === false || $subject === null) {
                    throw new DomainException('Legal hold subject not found.');
                }
            }
            $key = implode(':', [$subjectPersonId ?? '', $scopeType, $purposeCode ?? '', $resourceType ?? '',
                $resourceId ?? '', $reasonCode, $sourceType ?? '', $sourceReference ?? '']);
            $this->db->createCommand('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', [':key' => $key])->queryScalar();
            $params = [':subject' => $subjectPersonId, ':scope' => $scopeType, ':purpose' => $purposeCode,
                ':resource_type' => $resourceType, ':resource_id' => $resourceId, ':reason' => $reasonCode,
                ':source_type' => $sourceType, ':source_reference' => $sourceReference,
                ':now' => $now->format(DATE_ATOM), ':expires' => $expiresAt?->format(DATE_ATOM),
                ':actor' => $actor->accountId];
            $existing = $this->db->createCommand(<<<'SQL'
                SELECT id::text FROM core.privacy_legal_holds
                WHERE subject_person_id IS NOT DISTINCT FROM CAST(:subject AS uuid) AND scope_type = :scope
                  AND purpose_code IS NOT DISTINCT FROM :purpose AND resource_type IS NOT DISTINCT FROM :resource_type
                  AND resource_id IS NOT DISTINCT FROM CAST(:resource_id AS uuid) AND reason_code = :reason
                  AND source_type IS NOT DISTINCT FROM :source_type
                  AND source_reference IS NOT DISTINCT FROM :source_reference
                  AND released_at IS NULL AND (expires_at IS NULL OR expires_at > CAST(:now AS timestamptz))
                LIMIT 1 FOR UPDATE
                SQL, array_intersect_key($params, array_flip([':subject', ':scope', ':purpose', ':resource_type',
                ':resource_id', ':reason', ':source_type', ':source_reference', ':now'])))->queryScalar();
            if (is_string($existing)) {
                return $existing;
            }
            $id = $this->db->createCommand(<<<'SQL'
                INSERT INTO core.privacy_legal_holds (subject_person_id, scope_type, purpose_code, resource_type,
                    resource_id, reason_code, source_type, source_reference, created_at, created_by_account_id, expires_at)
                VALUES (CAST(:subject AS uuid), :scope, :purpose, :resource_type, CAST(:resource_id AS uuid),
                    :reason, :source_type, :source_reference, CAST(:now AS timestamptz), CAST(:actor AS uuid),
                    CAST(:expires AS timestamptz)) RETURNING id::text
                SQL, $params)->queryScalar();
            if (!is_string($id)) {
                throw new DomainException('Legal hold could not be saved.');
            }
            $this->audit(
                $actor,
                'privacy.legal_hold.created',
                $id,
                ['scopeType' => $scopeType, 'purposeCode' => $purposeCode, 'reasonCode' => $reasonCode],
            );
            return $id;
        });
    }

    public function release(AuthenticatedIdentity $actor, string $id, string $reasonCode): bool
    {
        MachineCode::assert($reasonCode);
        return $this->db->transaction(function () use ($actor, $id, $reasonCode): bool {
            $row = $this->db->createCommand(
                'SELECT released_at FROM core.privacy_legal_holds WHERE id = CAST(:id AS uuid) FOR UPDATE',
                [':id' => $id],
            )->queryOne();
            if (!is_array($row)) {
                throw new DomainException('Legal hold not found.');
            }
            if ($row['released_at'] !== null) {
                return false;
            }
            $this->db->createCommand(<<<'SQL'
                UPDATE core.privacy_legal_holds SET released_at = CAST(:now AS timestamptz),
                    released_by_account_id = CAST(:actor AS uuid), release_reason_code = :reason
                WHERE id = CAST(:id AS uuid)
                SQL, [':id' => $id, ':now' => $this->clock->now()->format(DATE_ATOM),
                ':actor' => $actor->accountId, ':reason' => $reasonCode])->execute();
            $this->audit($actor, 'privacy.legal_hold.released', $id, ['reasonCode' => $reasonCode]);
            return true;
        });
    }

    public function get(string $id): ?array
    {
        $row = $this->db->createCommand(
            'SELECT id::text, subject_person_id::text, scope_type, purpose_code, resource_type, resource_id::text, reason_code, source_type, source_reference, created_at, expires_at, released_at, release_reason_code FROM core.privacy_legal_holds WHERE id = CAST(:id AS uuid)',
            [':id' => $id],
        )->queryOne();
        return is_array($row) ? $row : null;
    }

    public function list(?string $subjectPersonId = null, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new DomainException('Invalid legal hold list limit.');
        }
        $where = $subjectPersonId === null ? '' : 'WHERE subject_person_id = CAST(:subject AS uuid)';
        $params = $subjectPersonId === null ? [':limit' => $limit]
            : [':subject' => $subjectPersonId, ':limit' => $limit];
        return $this->db->createCommand(<<<SQL
            SELECT id::text, subject_person_id::text, scope_type, purpose_code, resource_type,
                resource_id::text, reason_code, source_type, source_reference, created_at, expires_at, released_at
            FROM core.privacy_legal_holds
            {$where}
            ORDER BY created_at DESC, id DESC LIMIT :limit
            SQL, $params)->queryAll();
    }

    public function findApplicable(RetentionCandidate $candidate): ?string
    {
        $id = $this->db->createCommand(<<<'SQL'
            SELECT id::text FROM core.privacy_legal_holds
            WHERE (subject_person_id IS NULL OR subject_person_id = CAST(:subject AS uuid))
              AND released_at IS NULL AND created_at <= CAST(:now AS timestamptz)
              AND (expires_at IS NULL OR expires_at > CAST(:now AS timestamptz))
              AND (scope_type = 'subject' OR (scope_type = 'purpose' AND purpose_code = :purpose)
                OR (scope_type = 'resource' AND resource_type = :resource_type
                    AND resource_id = CAST(:resource_id AS uuid)
                    AND (purpose_code IS NULL OR purpose_code = :purpose)))
            ORDER BY created_at, id LIMIT 1
            SQL, [':subject' => $candidate->subjectPersonId, ':purpose' => $candidate->purposeCode,
            ':resource_type' => $candidate->resourceType, ':resource_id' => $candidate->resourceId,
            ':now' => $this->clock->now()->format(DATE_ATOM)])->queryScalar();
        return is_string($id) ? $id : null;
    }

    private function audit(AuthenticatedIdentity $actor, string $action, string $id, array $metadata): void
    {
        $this->audit->record(new WriteAuditEventCommand(
            'account',
            $actor->accountId,
            $action,
            resourceType: 'core.privacy_legal_hold',
            resourceId: $id,
            metadata: array_filter($metadata, static fn($value): bool => $value !== null),
        ));
    }
}
