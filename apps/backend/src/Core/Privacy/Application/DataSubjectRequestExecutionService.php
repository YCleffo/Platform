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

/** Execution evidence contains references and digests, never response or correction values. */
final readonly class DataSubjectRequestExecutionService
{
    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
        private PrivacyDeadlinePolicy $deadlinePolicy,
        private DataSubjectRequestStateMachine $states,
        private AuditService $audit,
        private PrivacyRequestExecutionCapabilityRegistry $capabilities,
    ) {}

    public function confirmCorrectionEvidence(AuthenticatedIdentity $actor, string $requestId): bool
    {
        return $this->db->transaction(function () use ($actor, $requestId): bool {
            $request = $this->lockRequest($requestId, 'correction');
            if (!in_array($request['status'], ['in_review', 'in_progress'], true)) {
                throw new DomainException('Correction evidence cannot be confirmed in this state.');
            }
            $existing = $this->db->createCommand(<<<'SQL'
                SELECT id FROM core.data_subject_request_deadlines
                WHERE request_id = CAST(:id AS uuid) AND deadline_code = 'confirmed_correction'
                SQL, [':id' => $requestId])->queryScalar();
            if ($existing !== false && $existing !== null) {
                return false;
            }
            $now = $this->clock->now();
            $rule = $this->deadlinePolicy->definition('confirmed_correction');
            $due = $this->deadlinePolicy->dueAt($rule['calendarType'], $rule['amount'], $now);
            $this->db->createCommand(<<<'SQL'
                INSERT INTO core.data_subject_request_deadlines
                    (request_id, deadline_code, triggered_at, calendar_type, due_at, extension_allowed)
                VALUES (CAST(:id AS uuid), 'confirmed_correction', CAST(:now AS timestamptz),
                        :calendar, CAST(:due AS timestamptz), false)
                SQL, [':id' => $requestId, ':now' => $now->format(DATE_ATOM),
                ':calendar' => $rule['calendarType'], ':due' => $due->format(DATE_ATOM)])->execute();
            $this->event($requestId, 'correction_evidence_confirmed', null, null, $actor, $now);
            $this->audit($actor, 'privacy.request.correction_confirmed', $requestId,
                ['deadlineCode' => 'confirmed_correction']);
            return true;
        });
    }

    public function recordExportIssued(AuthenticatedIdentity $actor, string $requestId,
        string $schemaVersion, string $sha256, int $sizeBytes): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1 || $sizeBytes < 0
            || strlen($schemaVersion) > 40 || $schemaVersion === '') {
            throw new DomainException('Invalid export evidence.');
        }
        return $this->db->transaction(function () use ($actor, $requestId, $schemaVersion, $sha256, $sizeBytes): string {
            $request = $this->lockRequest($requestId);
            $type = (string) $request['request_type'];
            $this->capabilities->assertAvailable($type);
            if (!in_array($type, ['access', 'export'], true) || $request['status'] !== 'in_progress') {
                throw new DomainException('Request cannot issue personal data in this state.');
            }
            $code = $type === 'access' ? 'access_response' : 'portable_export';
            $now = $this->clock->now();
            $id = $this->insertExecution($requestId, $code, 'completed', $actor, $now,
                schemaVersion: $schemaVersion, sha256: $sha256, sizeBytes: $sizeBytes);
            if ($type === 'access') {
                $this->satisfyDeadline($requestId, 'access_response', $id, $now);
            }
            $this->complete($actor, $requestId, $request, $now);
            $this->audit($actor, 'privacy.data.export.issued', $requestId, ['executionCode' => $code]);
            return $id;
        });
    }

    public function recordExportFailed(AuthenticatedIdentity $actor, string $requestId, string $errorCode): string
    {
        MachineCode::assert($errorCode);
        return $this->db->transaction(function () use ($actor, $requestId, $errorCode): string {
            $request = $this->lockRequest($requestId);
            $type = (string) $request['request_type'];
            $this->capabilities->assertAvailable($type);
            if (!in_array($type, ['access', 'export'], true) || $request['status'] !== 'in_progress') {
                throw new DomainException('Request cannot execute personal data export in this state.');
            }
            $code = $type === 'access' ? 'access_response' : 'portable_export';
            $now = $this->clock->now();
            $id = $this->insertExecution($requestId, $code, 'failed', $actor, $now, errorCode: $errorCode);
            $this->audit($actor, 'privacy.request.execution_failed', $requestId,
                ['executionCode' => $code, 'reasonCode' => $errorCode]);
            return $id;
        });
    }

    /** @param list<string> $changedPaths */
    public function recordCorrectionCompleted(AuthenticatedIdentity $actor, string $requestId,
        string $contributorCode, string $resourceType, string $resourceId, array $changedPaths): string
    {
        MachineCode::assert($contributorCode);
        MachineCode::assert($resourceType);
        if ($changedPaths === [] || count($changedPaths) > 32) {
            throw new DomainException('Correction requires bounded changed paths.');
        }
        foreach ($changedPaths as $path) {
            MachineCode::assert($path);
        }
        return $this->db->transaction(function () use ($actor, $requestId, $contributorCode, $resourceType, $resourceId, $changedPaths): string {
            $request = $this->lockRequest($requestId, 'correction');
            $this->capabilities->assertAvailable('correction');
            if ($request['status'] !== 'in_progress') {
                throw new DomainException('Correction is not in progress.');
            }
            $now = $this->clock->now();
            $id = $this->insertExecution($requestId, 'data_correction', 'completed', $actor, $now,
                contributorCode: $contributorCode, resourceType: $resourceType,
                resourceId: $resourceId, changedPaths: $changedPaths);
            $this->satisfyDeadline($requestId, 'confirmed_correction', $id, $now);
            $this->complete($actor, $requestId, $request, $now);
            $this->audit($actor, 'privacy.data.correction.completed', $requestId,
                ['executionCode' => 'data_correction', 'contributorCode' => $contributorCode,
                 'resourceType' => $resourceType, 'changedPaths' => $changedPaths]);
            return $id;
        });
    }

    /** @return array<array-key, mixed> */
    private function lockRequest(string $requestId, ?string $expectedType = null): array
    {
        $row = $this->db->createCommand(
            'SELECT request_type, status FROM core.data_subject_requests WHERE id = CAST(:id AS uuid) FOR UPDATE',
            [':id' => $requestId],
        )->queryOne();
        if (!is_array($row) || ($expectedType !== null && $row['request_type'] !== $expectedType)) {
            throw new DomainException('Request not found for this execution.');
        }
        return $row;
    }

    /** @param list<string>|null $changedPaths */
    private function insertExecution(string $requestId, string $code, string $status,
        AuthenticatedIdentity $actor, DateTimeImmutable $now, ?string $schemaVersion = null,
        ?string $sha256 = null, ?int $sizeBytes = null, ?string $errorCode = null,
        ?string $contributorCode = null, ?string $resourceType = null, ?string $resourceId = null,
        ?array $changedPaths = null): string
    {
        $id = $this->db->createCommand(<<<'SQL'
            INSERT INTO core.data_subject_request_executions
                (request_id, execution_code, status, actor_account_id, contributor_code,
                 resource_type, resource_id, schema_version, result_sha256, result_size_bytes,
                 changed_paths, error_code, started_at, completed_at)
            VALUES (CAST(:request AS uuid), :code, :status, CAST(:actor AS uuid), :contributor,
                    :resource_type, CAST(:resource_id AS uuid), :schema_version, :sha256, :size,
                    CAST(:paths AS text[]), :error, CAST(:now AS timestamptz), CAST(:now AS timestamptz))
            RETURNING id::text
            SQL, [':request' => $requestId, ':code' => $code, ':status' => $status,
            ':actor' => $actor->accountId, ':contributor' => $contributorCode,
            ':resource_type' => $resourceType, ':resource_id' => $resourceId,
            ':schema_version' => $schemaVersion, ':sha256' => $sha256, ':size' => $sizeBytes,
            ':paths' => $changedPaths === null ? null : '{' . implode(',', $changedPaths) . '}',
            ':error' => $errorCode, ':now' => $now->format(DATE_ATOM)])->queryScalar();
        if (!is_string($id)) {
            throw new DomainException('Execution evidence could not be saved.');
        }
        return $id;
    }

    private function satisfyDeadline(string $requestId, string $deadlineCode, string $executionId, DateTimeImmutable $now): void
    {
        $updated = $this->db->createCommand(<<<'SQL'
            UPDATE core.data_subject_request_deadlines d
            SET satisfied_at = CAST(:now AS timestamptz)
            WHERE d.request_id = CAST(:request AS uuid) AND d.deadline_code = :code
              AND d.satisfied_at IS NULL AND EXISTS (
                SELECT 1 FROM core.data_subject_request_executions e
                WHERE e.id = CAST(:execution AS uuid) AND e.request_id = d.request_id AND e.status = 'completed')
            SQL, [':request' => $requestId, ':code' => $deadlineCode,
            ':execution' => $executionId, ':now' => $now->format(DATE_ATOM)])->execute();
        if ($updated !== 1) {
            throw new DomainException('Required deadline is missing or already satisfied.');
        }
    }

    /** @param array<array-key, mixed> $request */
    private function complete(AuthenticatedIdentity $actor, string $requestId, array $request, DateTimeImmutable $now): void
    {
        $from = (string) $request['status'];
        $this->states->assertTransition($from, 'completed');
        $this->db->createCommand(<<<'SQL'
            UPDATE core.data_subject_requests
            SET status = 'completed', completed_at = CAST(:now AS timestamptz),
                resolution_code = 'executed', updated_at = CAST(:now AS timestamptz)
            WHERE id = CAST(:id AS uuid)
            SQL, [':id' => $requestId, ':now' => $now->format(DATE_ATOM)])->execute();
        $this->event($requestId, 'completed', $from, 'completed', $actor, $now);
        $this->audit($actor, 'privacy.request.execution_completed', $requestId,
            ['fromStatus' => $from, 'toStatus' => 'completed']);
    }

    private function event(string $requestId, string $code, ?string $from, ?string $to,
        AuthenticatedIdentity $actor, DateTimeImmutable $now): void
    {
        $this->db->createCommand(<<<'SQL'
            INSERT INTO core.data_subject_request_events
                (request_id, event_code, from_status, to_status, actor_account_id, occurred_at)
            VALUES (CAST(:request AS uuid), :code, :from_status, :to_status,
                    CAST(:actor AS uuid), CAST(:now AS timestamptz))
            SQL, [':request' => $requestId, ':code' => $code, ':from_status' => $from,
            ':to_status' => $to, ':actor' => $actor->accountId, ':now' => $now->format(DATE_ATOM)])->execute();
    }

    /** @param array<string, mixed> $metadata */
    private function audit(AuthenticatedIdentity $actor, string $action, string $requestId, array $metadata): void
    {
        $this->audit->record(new WriteAuditEventCommand('account', $actor->accountId,
            $action, resourceType: 'core.data_subject_request', resourceId: $requestId, metadata: $metadata));
    }
}
