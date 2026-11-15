<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Time\Application\ClockInterface;
use DomainException;
use Throwable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Transaction\TransactionInterface;

final readonly class PersonalDataExportService
{
    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
        private RepresentativeAuthorityPolicy $authority,
        private PersonalDataExporter $exporter,
        private DataSubjectRequestExecutionService $executions,
    ) {}

    public function exportSelf(AuthenticatedIdentity $actor, string $requestId): PersonalDataExportResult
    {
        return $this->export($actor, $requestId, null);
    }

    public function exportRepresentative(
        AuthenticatedIdentity $actor,
        string $requestId,
        string $representationId,
    ): PersonalDataExportResult {
        return $this->export($actor, $requestId, $representationId);
    }

    public function exportOperator(AuthenticatedIdentity $actor, string $requestId): PersonalDataExportResult
    {
        return $this->export($actor, $requestId, null, true);
    }

    public function streamSelf(AuthenticatedIdentity $actor, string $requestId): PersonalDataExportArtifact
    {
        return $this->export($actor, $requestId, null, stream: true);
    }

    public function streamRepresentative(AuthenticatedIdentity $actor, string $requestId, string $representationId): PersonalDataExportArtifact
    {
        return $this->export($actor, $requestId, $representationId, stream: true);
    }

    public function streamOperator(AuthenticatedIdentity $actor, string $requestId): PersonalDataExportArtifact
    {
        return $this->export($actor, $requestId, null, true, stream: true);
    }

    private function export(
        AuthenticatedIdentity $actor,
        string $requestId,
        ?string $representationId,
        bool $operatorIntake = false,
        bool $stream = false,
    ): PersonalDataExportResult|PersonalDataExportArtifact {
        $authorized = false;
        try {
            $isolation = $this->db->getTransaction() === null ? TransactionInterface::REPEATABLE_READ : null;
            return $this->db->transaction(function () use ($actor, $requestId, $representationId, $operatorIntake, $stream, &$authorized): PersonalDataExportResult|PersonalDataExportArtifact {
                $row = $this->db->createCommand(<<<'SQL'
                    SELECT r.subject_person_id::text, r.request_type, r.status,
                           r.legal_representation_id::text, r.intake_channel
                    FROM core.data_subject_requests r WHERE r.id = CAST(:id AS uuid) FOR UPDATE
                    SQL, [':id' => $requestId])->queryOne();
                if (!is_array($row) || !in_array($row['request_type'], ['access', 'export'], true)
                    || $row['status'] !== 'in_progress' || $actor->personId === null) {
                    throw new DomainException('Personal data response is unavailable.');
                }
                $person = $this->db->createCommand(<<<'SQL'
                    SELECT person_id::text FROM core.accounts
                    WHERE id = CAST(:account AS uuid) AND account_type = 'human'
                    SQL, [':account' => $actor->accountId])->queryScalar();
                if (!is_string($person) || $person !== $actor->personId) {
                    throw new DomainException('Personal data response is unavailable.');
                }
                $subject = (string) $row['subject_person_id'];
                if ($operatorIntake) {
                    if ($row['intake_channel'] === null || $representationId !== null) {
                        throw new DomainException('Operator intake response is unavailable.');
                    }
                } elseif ($representationId === null) {
                    if ($subject !== $actor->personId) {
                        throw new DomainException('Personal data response is unavailable.');
                    }
                } else {
                    if ($subject === $actor->personId || $row['legal_representation_id'] !== $representationId) {
                        throw new DomainException('Personal data response is unavailable.');
                    }
                    $this->authority->assertActiveForAction(
                        $representationId,
                        $subject,
                        $actor->personId,
                        'privacy.request.view',
                    );
                    $this->authority->assertActiveForAction(
                        $representationId,
                        $subject,
                        $actor->personId,
                        $row['request_type'] === 'access' ? 'privacy.data.access' : 'privacy.data.export',
                    );
                }
                $authorized = true;
                $context = new PersonalDataExportContext(
                    $subject,
                    $requestId,
                    $actor->personId,
                    $actor->accountId,
                    $representationId,
                    $this->clock->now(),
                );
                $result = $stream ? $this->exporter->exportToStream($context) : $this->exporter->export($context);
                $this->executions->recordExportIssued(
                    $actor,
                    $requestId,
                    $result->schemaVersion,
                    $result->sha256,
                    $result->sizeBytes,
                );
                return $result;
            }, $isolation);
        } catch (PersonalDataExportLimitException $exception) {
            if ($authorized) {
                $this->executions->recordExportFailed($actor, $requestId, 'export_too_large');
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($authorized) {
                $this->executions->recordExportFailed($actor, $requestId, 'export_failed');
            }
            throw $exception;
        }
    }
}
