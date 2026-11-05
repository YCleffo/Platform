<?php

declare(strict_types=1);

namespace App\Core\Privacy\Application;

use App\Core\Auth\Application\AuthenticatedIdentity;
use App\Core\Privacy\Domain\MachineCode;
use App\Core\Time\Application\ClockInterface;
use DateTimeImmutable;
use DomainException;
use Yiisoft\Db\Connection\ConnectionInterface;

/** Technical incident workflow; external notifications are recorded, never sent here. */
final readonly class PrivacyIncidentService
{
    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
        private DataCategoryRegistry $categories,
    ) {}

    /** @param list<string> $categoryCodes @param list<string> $subjectPersonIds */
    public function detect(
        AuthenticatedIdentity $actor,
        string $incidentType,
        string $reasonCode,
        array $categoryCodes,
        ?int $estimatedSubjects,
        array $subjectPersonIds = [],
        ?DateTimeImmutable $detectedAt = null,
    ): string {
        MachineCode::assert($incidentType);
        MachineCode::assert($reasonCode);
        if ($categoryCodes === [] || count($categoryCodes) > 32 || count($subjectPersonIds) > 1000
            || ($estimatedSubjects !== null && $estimatedSubjects < 0)) {
            throw new DomainException('Invalid incident scope.');
        }
        foreach ($categoryCodes as $code) {
            MachineCode::assert($code);
            if ($this->categories->get($code) === null) {
                throw new DomainException('Unknown incident data category.');
            }
        }
        $detected = $detectedAt ?? $this->clock->now();
        if ($detected > $this->clock->now()) {
            throw new DomainException('Incident detection time cannot be future.');
        }
        return $this->db->transaction(function () use (
            $actor,
            $incidentType,
            $reasonCode,
            $categoryCodes,
            $estimatedSubjects,
            $subjectPersonIds,
            $detected
        ): string {
            $now = $this->clock->now()->format(DATE_ATOM);
            $id = $this->db->createCommand(<<<'SQL'
                INSERT INTO core.privacy_incidents
                    (incident_type, status, containment_status, detected_at,
                     initial_notification_due_at, investigation_notification_due_at,
                     estimated_subjects, safe_reason_code, created_by_account_id, created_at, updated_at)
                VALUES (:type, 'detected', 'unknown', CAST(:detected AS timestamptz),
                    CAST(:initial AS timestamptz), CAST(:investigation AS timestamptz),
                    :estimated, :reason, CAST(:actor AS uuid), CAST(:now AS timestamptz),
                    CAST(:now AS timestamptz)) RETURNING id::text
                SQL, [':type' => $incidentType, ':detected' => $detected->format(DATE_ATOM),
                ':initial' => $detected->modify('+24 hours')->format(DATE_ATOM),
                ':investigation' => $detected->modify('+72 hours')->format(DATE_ATOM),
                ':estimated' => $estimatedSubjects, ':reason' => $reasonCode,
                ':actor' => $actor->accountId, ':now' => $now])->queryScalar();
            foreach (array_unique($categoryCodes) as $code) {
                $this->db->createCommand(<<<'SQL'
                    INSERT INTO core.privacy_incident_categories (incident_id, category_code)
                    VALUES (CAST(:id AS uuid), :code)
                    SQL, [':id' => $id, ':code' => $code])->execute();
            }
            foreach (array_unique($subjectPersonIds) as $subject) {
                $this->db->createCommand(<<<'SQL'
                    INSERT INTO core.privacy_incident_subject_scope (incident_id, subject_person_id)
                    VALUES (CAST(:id AS uuid), CAST(:subject AS uuid))
                    SQL, [':id' => $id, ':subject' => $subject])->execute();
            }
            $this->event((string) $id, $actor, 'detected', null, 'detected', $reasonCode);
            return (string) $id;
        });
    }

    public function transition(
        AuthenticatedIdentity $actor,
        string $incidentId,
        string $to,
        string $reasonCode,
    ): void {
        MachineCode::assert($reasonCode);
        $this->db->transaction(function () use ($actor, $incidentId, $to, $reasonCode): void {
            $row = $this->db->createCommand(<<<'SQL'
                SELECT status FROM core.privacy_incidents WHERE id = CAST(:id AS uuid) FOR UPDATE
                SQL, [':id' => $incidentId])->queryOne();
            $allowed = ['detected' => 'investigating', 'investigating' => 'contained',
                'contained' => 'resolved'];
            if (!is_array($row) || ($allowed[(string) $row['status']] ?? null) !== $to) {
                throw new DomainException('Invalid incident transition.');
            }
            $this->db->createCommand(<<<'SQL'
                UPDATE core.privacy_incidents SET status = :status,
                    containment_status = :containment, updated_at = CAST(:now AS timestamptz)
                WHERE id = CAST(:id AS uuid)
                SQL, [':status' => $to,
                ':containment' => $to === 'contained' || $to === 'resolved' ? 'contained' : 'in_progress',
                ':now' => $this->clock->now()->format(DATE_ATOM), ':id' => $incidentId])->execute();
            $this->event($incidentId, $actor, 'status_changed', (string) $row['status'], $to, $reasonCode);
        });
    }

    public function recordExternalNotification(
        AuthenticatedIdentity $actor,
        string $incidentId,
        string $kind,
        string $referenceCode,
    ): void {
        MachineCode::assert($referenceCode);
        if (!in_array($kind, ['initial', 'investigation'], true)) {
            throw new DomainException('Invalid notification kind.');
        }
        $this->db->transaction(function () use ($actor, $incidentId, $kind, $referenceCode): void {
            $column = $kind === 'initial' ? 'initial' : 'investigation';
            $row = $this->db->createCommand(<<<SQL
                SELECT status, {$column}_notification_at AS notified_at
                FROM core.privacy_incidents WHERE id = CAST(:id AS uuid) FOR UPDATE
                SQL, [':id' => $incidentId])->queryOne();
            if (!is_array($row) || $row['notified_at'] !== null) {
                throw new DomainException('Incident notification already recorded or missing.');
            }
            $this->db->createCommand(<<<SQL
                UPDATE core.privacy_incidents
                SET {$column}_notification_at = CAST(:now AS timestamptz),
                    {$column}_notification_reference = :reference,
                    updated_at = CAST(:now AS timestamptz)
                WHERE id = CAST(:id AS uuid)
                SQL, [':now' => $this->clock->now()->format(DATE_ATOM),
                ':reference' => $referenceCode, ':id' => $incidentId])->execute();
            $this->event(
                $incidentId,
                $actor,
                $kind . '_notification_recorded',
                (string) $row['status'],
                (string) $row['status'],
                null,
            );
        });
    }

    public function export(string $incidentId): array
    {
        $row = $this->db->createCommand(<<<'SQL'
            SELECT id::text, incident_type, status, containment_status, detected_at,
                initial_notification_due_at, initial_notification_at, initial_notification_reference,
                investigation_notification_due_at, investigation_notification_at,
                investigation_notification_reference, estimated_subjects, safe_reason_code
            FROM core.privacy_incidents WHERE id = CAST(:id AS uuid)
            SQL, [':id' => $incidentId])->queryOne();
        if (!is_array($row)) {
            throw new DomainException('Incident not found.');
        }
        $row['affectedCategories'] = array_column($this->db->createCommand(<<<'SQL'
            SELECT category_code FROM core.privacy_incident_categories
            WHERE incident_id = CAST(:id AS uuid) ORDER BY category_code
            SQL, [':id' => $incidentId])->queryAll(), 'category_code');
        $row['subjectScopeCount'] = (int) $this->db->createCommand(<<<'SQL'
            SELECT count(*) FROM core.privacy_incident_subject_scope WHERE incident_id = CAST(:id AS uuid)
            SQL, [':id' => $incidentId])->queryScalar();
        $row['events'] = $this->db->createCommand(<<<'SQL'
            SELECT event_code, from_status, to_status, reason_code,
                actor_account_id::text, occurred_at
            FROM core.privacy_incident_events WHERE incident_id = CAST(:id AS uuid)
            ORDER BY occurred_at, id
            SQL, [':id' => $incidentId])->queryAll();
        $row['technicalEvidenceOnly'] = true;
        return $row;
    }

    private function event(
        string $incidentId,
        AuthenticatedIdentity $actor,
        string $eventCode,
        ?string $from,
        ?string $to,
        ?string $reason,
    ): void {
        $this->db->createCommand(<<<'SQL'
            INSERT INTO core.privacy_incident_events
                (incident_id, event_code, from_status, to_status, reason_code,
                 actor_account_id, occurred_at)
            VALUES (CAST(:id AS uuid), :event, :from, :to, :reason,
                CAST(:actor AS uuid), CAST(:now AS timestamptz))
            SQL, [':id' => $incidentId, ':event' => $eventCode, ':from' => $from,
            ':to' => $to, ':reason' => $reason, ':actor' => $actor->accountId,
            ':now' => $this->clock->now()->format(DATE_ATOM)])->execute();
    }
}
