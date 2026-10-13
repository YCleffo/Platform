<?php

declare(strict_types=1);

namespace ControlPlane\Privacy;

use Doctrine\DBAL\Connection;

final readonly class ControlIncidentService
{
    public function __construct(private Connection $connection, private ControlPrivacyOperator $operators) {}

    /** @param list<mixed> $categories
     * @return array{id:string, initialNotificationDueAt:string, investigationNotificationDueAt:string}
     */
    public function detect(string $operatorId, string $detectedAt, array $categories, ?int $estimatedSubjects): array
    {
        $this->operators->assertAuthorized($operatorId);
        if ($categories === [] || count($categories) > 32 || ($estimatedSubjects !== null && $estimatedSubjects < 0)) {
            throw new \InvalidArgumentException('Invalid incident metadata.');
        }
        foreach ($categories as $code) {
            if (!is_string($code) || preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/D', $code) !== 1) {
                throw new \InvalidArgumentException('Invalid incident category.');
            }
        }
        if (count($categories) !== count(array_unique($categories))) {
            throw new \InvalidArgumentException('Duplicate incident category.');
        }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/D', $detectedAt) !== 1) {
            throw new \InvalidArgumentException('Invalid detectedAt.');
        }
        $detected = new \DateTimeImmutable($detectedAt);
        if ($detected > new \DateTimeImmutable()) {
            throw new \InvalidArgumentException('Future incident detection is invalid.');
        }
        $due24 = $detected->modify('+24 hours');
        $due72 = $detected->modify('+72 hours');
        return $this->connection->transactional(function (Connection $db) use ($operatorId, $detected, $due24, $due72, $categories, $estimatedSubjects): array {
        $id = (string) $db->fetchOne(<<<'SQL'
INSERT INTO commercial.privacy_incidents
 (detected_at, initial_notification_due_at, investigation_notification_due_at, category_codes, estimated_subjects, created_by_user_id)
VALUES (?, ?, ?, ?, ?, ?) RETURNING id
SQL, [$detected->format('c'), $due24->format('c'), $due72->format('c'), '{' . implode(',', $categories) . '}', $estimatedSubjects, $operatorId]);
        $this->event($id, 'detected', null, $operatorId);
        return ['id' => $id, 'initialNotificationDueAt' => $due24->format('c'), 'investigationNotificationDueAt' => $due72->format('c')];
        });
    }

    public function record(string $operatorId, string $incidentId, string $eventCode, string $evidenceReference): void
    {
        $this->operators->assertAuthorized($operatorId);
        $column = match ($eventCode) {
            'containment' => 'containment_reference',
            'investigation' => 'investigation_reference',
            'initial_notification' => 'initial_notification_reference',
            'investigation_notification' => 'investigation_notification_reference',
            default => throw new \InvalidArgumentException('Invalid incident event.'),
        };
        if (!str_starts_with($evidenceReference, 'urn:') || strlen($evidenceReference) > 500) {
            throw new \InvalidArgumentException('Evidence reference required.');
        }
        $this->connection->transactional(function (Connection $db) use ($operatorId, $incidentId, $eventCode, $evidenceReference, $column): void {
            $incident = $db->fetchAssociative('SELECT id, status FROM commercial.privacy_incidents WHERE id = ? FOR UPDATE', [$incidentId]);
            if ($incident === false || $incident['status'] === 'closed') {
                throw new \DomainException('Open incident not found.');
            }
            $status = match ($eventCode) {
                'containment' => $incident['status'] === 'investigating' ? 'investigating' : 'contained',
                'investigation' => 'investigating', default => $incident['status'],
            };
            $db->executeStatement("UPDATE commercial.privacy_incidents SET $column = ?, status = ?, updated_at = now() WHERE id = ?", [$evidenceReference, $status, $incidentId]);
            $this->event($incidentId, $eventCode, $evidenceReference, $operatorId);
        });
    }

    public function close(string $operatorId, string $incidentId): void
    {
        $this->operators->assertAuthorized($operatorId);
        $this->connection->transactional(function (Connection $db) use ($operatorId, $incidentId): void {
            $incident = $db->fetchAssociative('SELECT * FROM commercial.privacy_incidents WHERE id = ? FOR UPDATE', [$incidentId]);
            if ($incident === false || $incident['status'] === 'closed') {
                throw new \DomainException('Open incident not found.');
            }
            foreach (['containment_reference', 'investigation_reference',
                'initial_notification_reference', 'investigation_notification_reference'] as $field) {
                if (!is_string($incident[$field]) || $incident[$field] === '') {
                    throw new \DomainException('Incident evidence is incomplete.');
                }
            }
            $db->executeStatement("UPDATE commercial.privacy_incidents SET status = 'closed', updated_at = now() WHERE id = ?", [$incidentId]);
            $this->event($incidentId, 'closed', null, $operatorId);
        });
    }

    private function event(string $id, string $code, ?string $reference, string $actorId): void
    {
        $this->connection->executeStatement('INSERT INTO commercial.privacy_incident_events (incident_id, event_code, evidence_reference, actor_user_id) VALUES (?, ?, ?, ?)', [$id, $code, $reference, $actorId]);
    }
}
