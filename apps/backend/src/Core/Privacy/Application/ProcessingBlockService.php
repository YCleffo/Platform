<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Audit\Application\AuditService;
use App\Core\Audit\Application\WriteAuditEventCommand;
use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Domain\MachineCode;
use App\Core\Time\Application\ClockInterface;
use DateTimeImmutable;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class ProcessingBlockService implements ProcessingBlockPolicy
{
    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
        private ProcessingPurposeRegistry $purposes,
        private AuditService $audit,
    ) {}

    public function createBlock(
        AuthenticatedIdentity $actor,
        string $subjectPersonId,
        string $scopeType,
        ?string $purposeCode,
        ?string $resourceType,
        ?string $resourceId,
        string $reasonCode,
        ?string $sourceRequestId = null,
        ?DateTimeImmutable $expiresAt = null,
    ): string {
        MachineCode::assert($reasonCode);
        if ($purposeCode !== null) {
            MachineCode::assert($purposeCode);
            if ($this->purposes->get($purposeCode) === null) {
                throw new DomainException('Unknown processing purpose.');
            }
        }
        if ($resourceType !== null) {
            MachineCode::assert($resourceType);
        }
        if (!match ($scopeType) {
            'subject' => $purposeCode === null && $resourceType === null && $resourceId === null,
            'purpose' => $purposeCode !== null && $resourceType === null && $resourceId === null,
            'resource' => $resourceType !== null && $resourceId !== null,
            default => false,
        }) {
            throw new DomainException('Invalid processing block scope.');
        }
        return $this->db->transaction(function () use ($actor, $subjectPersonId, $scopeType, $purposeCode, $resourceType, $resourceId, $reasonCode, $sourceRequestId, $expiresAt): string {
            $now = $this->clock->now();
            if ($expiresAt !== null && $expiresAt <= $now) {
                throw new DomainException('Block expiration must follow creation.');
            }
            $subject = $this->db->createCommand(
                'SELECT id FROM core.persons WHERE id = CAST(:id AS uuid) FOR SHARE', [':id' => $subjectPersonId],
            )->queryScalar();
            if ($subject === false || $subject === null) {
                throw new DomainException('Subject not found.');
            }
            if ($sourceRequestId !== null) {
                $request = $this->db->createCommand(<<<'SQL'
                    SELECT id FROM core.data_subject_requests
                    WHERE id = CAST(:id AS uuid) AND subject_person_id = CAST(:subject AS uuid)
                    FOR SHARE
                    SQL, [':id' => $sourceRequestId, ':subject' => $subjectPersonId])->queryScalar();
                if ($request === false || $request === null) {
                    throw new DomainException('Source request does not belong to subject.');
                }
            }
            $this->db->createCommand('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
                [':key' => implode(':', [$subjectPersonId, $scopeType, $purposeCode ?? '', $resourceType ?? '', $resourceId ?? ''])])->queryScalar();
            $existing = $this->db->createCommand(<<<'SQL'
                SELECT id::text FROM core.privacy_processing_blocks
                WHERE subject_person_id = CAST(:subject AS uuid) AND scope_type = :scope
                  AND purpose_code IS NOT DISTINCT FROM :purpose
                  AND resource_type IS NOT DISTINCT FROM :resource_type
                  AND resource_id IS NOT DISTINCT FROM CAST(:resource_id AS uuid)
                  AND released_at IS NULL AND (expires_at IS NULL OR expires_at > CAST(:now AS timestamptz))
                LIMIT 1 FOR UPDATE
                SQL, [':subject' => $subjectPersonId, ':scope' => $scopeType, ':purpose' => $purposeCode,
                ':resource_type' => $resourceType, ':resource_id' => $resourceId, ':now' => $now->format(DATE_ATOM)])->queryScalar();
            if (is_string($existing)) {
                return $existing;
            }
            $id = $this->db->createCommand(<<<'SQL'
                INSERT INTO core.privacy_processing_blocks
                    (subject_person_id, scope_type, purpose_code, resource_type, resource_id,
                     reason_code, source_request_id, started_at, expires_at, created_by_account_id)
                VALUES (CAST(:subject AS uuid), :scope, :purpose, :resource_type, CAST(:resource_id AS uuid),
                        :reason, CAST(:request AS uuid), CAST(:now AS timestamptz),
                        CAST(:expires AS timestamptz), CAST(:actor AS uuid)) RETURNING id::text
                SQL, [':subject' => $subjectPersonId, ':scope' => $scopeType, ':purpose' => $purposeCode,
                ':resource_type' => $resourceType, ':resource_id' => $resourceId, ':reason' => $reasonCode,
                ':request' => $sourceRequestId, ':now' => $now->format(DATE_ATOM),
                ':expires' => $expiresAt?->format(DATE_ATOM), ':actor' => $actor->accountId])->queryScalar();
            if (!is_string($id)) {
                throw new DomainException('Block could not be saved.');
            }
            $this->record($actor, 'privacy.processing_block.created', $id,
                array_filter(['scopeType' => $scopeType, 'purposeCode' => $purposeCode, 'reasonCode' => $reasonCode]));
            return $id;
        });
    }

    public function releaseBlock(AuthenticatedIdentity $actor, string $id, string $reasonCode): bool
    {
        MachineCode::assert($reasonCode);
        return $this->db->transaction(function () use ($actor, $id, $reasonCode): bool {
            $row = $this->db->createCommand(
                'SELECT released_at, scope_type, purpose_code FROM core.privacy_processing_blocks WHERE id = CAST(:id AS uuid) FOR UPDATE',
                [':id' => $id],
            )->queryOne();
            if (!is_array($row)) {
                throw new DomainException('Block not found.');
            }
            if ($row['released_at'] !== null) {
                return false;
            }
            $this->db->createCommand(<<<'SQL'
                UPDATE core.privacy_processing_blocks
                SET released_at = CAST(:now AS timestamptz), released_by_account_id = CAST(:actor AS uuid),
                    release_reason_code = :reason WHERE id = CAST(:id AS uuid)
                SQL, [':now' => $this->clock->now()->format(DATE_ATOM), ':actor' => $actor->accountId,
                ':reason' => $reasonCode, ':id' => $id])->execute();
            $this->record($actor, 'privacy.processing_block.released', $id,
                array_filter(['scopeType' => (string) $row['scope_type'], 'purposeCode' => $row['purpose_code'], 'reasonCode' => $reasonCode]));
            return true;
        });
    }

    /** @return list<array<array-key, mixed>> */
    public function listActiveForSubject(string $subjectPersonId): array
    {
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT id::text, scope_type, purpose_code, resource_type, resource_id::text,
                   reason_code, source_request_id::text, started_at, expires_at
            FROM core.privacy_processing_blocks
            WHERE subject_person_id = CAST(:subject AS uuid) AND released_at IS NULL
              AND (expires_at IS NULL OR expires_at > CAST(:now AS timestamptz))
            ORDER BY started_at, id
            SQL, [':subject' => $subjectPersonId, ':now' => $this->clock->now()->format(DATE_ATOM)])->queryAll();
        return $rows;
    }

    public function isBlocked(string $subjectPersonId, ?string $purposeCode = null, ?string $resourceType = null, ?string $resourceId = null): bool
    {
        $found = $this->db->createCommand(<<<'SQL'
            SELECT 1 FROM core.privacy_processing_blocks
            WHERE subject_person_id = CAST(:subject AS uuid) AND released_at IS NULL
              AND started_at <= CAST(:now AS timestamptz)
              AND (expires_at IS NULL OR expires_at > CAST(:now AS timestamptz))
              AND (scope_type = 'subject'
                OR (scope_type = 'purpose' AND purpose_code = :purpose)
                OR (scope_type = 'resource' AND resource_type = :resource_type
                    AND resource_id = CAST(:resource_id AS uuid)
                    AND (purpose_code IS NULL OR purpose_code = :purpose)))
            LIMIT 1
            SQL, [':subject' => $subjectPersonId, ':purpose' => $purposeCode,
            ':resource_type' => $resourceType, ':resource_id' => $resourceId,
            ':now' => $this->clock->now()->format(DATE_ATOM)])->queryScalar();
        return $found !== false && $found !== null;
    }

    /** @param array<string, mixed> $metadata */
    private function record(AuthenticatedIdentity $actor, string $action, string $id, array $metadata): void
    {
        $this->audit->record(new WriteAuditEventCommand('account', $actor->accountId, $action,
            resourceType: 'core.privacy_processing_block', resourceId: $id, metadata: $metadata));
    }
}
