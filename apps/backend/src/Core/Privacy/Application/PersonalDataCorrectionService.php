<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Domain\MachineCode;
use DomainException;
use JsonException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PersonalDataCorrectionService
{
    public function __construct(private ConnectionInterface $db,
        private PersonalDataCorrectionContributorRegistry $contributors,
        private DataSubjectRequestExecutionService $executions) {}

    public function confirmEvidence(AuthenticatedIdentity $actor, string $requestId): bool
    {
        return $this->executions->confirmCorrectionEvidence($actor, $requestId);
    }

    public function correct(AuthenticatedIdentity $actor, string $requestId,
        CorrectionCommand $command): string
    {
        MachineCode::assert($command->contributorCode);
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $command->resourceId) !== 1
            || $command->changes === [] || count($command->changes) > 10) {
            throw new DomainException('Invalid correction command.');
        }
        try {
            $size = strlen(json_encode($command->changes, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new DomainException('Invalid correction command.');
        }
        if ($size > 16384) {
            throw new DomainException('Correction command is too large.');
        }
        return $this->db->transaction(function () use ($actor, $requestId, $command): string {
            $row = $this->db->createCommand(<<<'SQL'
                SELECT subject_person_id::text, request_type, status
                FROM core.data_subject_requests WHERE id = CAST(:id AS uuid) FOR UPDATE
                SQL, [':id' => $requestId])->queryOne();
            if (!is_array($row) || $row['request_type'] !== 'correction' || $row['status'] !== 'in_progress') {
                throw new DomainException('Correction request is unavailable.');
            }
            $confirmed = $this->db->createCommand(<<<'SQL'
                SELECT id FROM core.data_subject_request_deadlines
                WHERE request_id = CAST(:id AS uuid) AND deadline_code = 'confirmed_correction'
                SQL, [':id' => $requestId])->queryScalar();
            if ($confirmed === false || $confirmed === null) {
                throw new DomainException('Correction evidence has not been confirmed.');
            }
            $contributor = $this->contributors->get($command->contributorCode);
            $result = $contributor->apply(new CorrectionContext($requestId, (string) $row['subject_person_id']), $command);
            if ($result->resourceId !== $command->resourceId
                || !in_array($result->resourceType, $contributor->supportedResourceTypes(), true)) {
                throw new DomainException('Correction result is invalid.');
            }
            return $this->executions->recordCorrectionCompleted($actor, $requestId,
                $contributor->code(), $result->resourceType, $result->resourceId, $result->changedPaths);
        });
    }
}
