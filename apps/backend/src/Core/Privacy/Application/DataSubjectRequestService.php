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

final readonly class DataSubjectRequestService
{
    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
        private PrivacyDeadlinePolicy $deadlines,
        private DataSubjectRequestTypeRegistry $types,
        private DataSubjectRequestStateMachine $states,
        private RepresentativeAuthorityPolicy $authority,
        private AuditService $audit,
    ) {}

    public function submitSelf(AuthenticatedIdentity $actor, string $type): string
    {
        return $this->submit($actor, $actor->personId, null, $type);
    }

    public function submitForSubject(AuthenticatedIdentity $actor, string $subjectPersonId, string $representationId, string $type): string
    {
        if ($actor->personId === $subjectPersonId) {
            throw new DomainException('Use self submission for own request.');
        }
        return $this->submit($actor, $subjectPersonId, $representationId, $type);
    }

    private function submit(AuthenticatedIdentity $actor, ?string $subject, ?string $representation, string $type): string
    {
        $definition = $this->types->get($type);
        if ($subject === null || $actor->personId === null) {
            throw new DomainException('A human Account linked to a Person is required.');
        }
        return $this->db->transaction(function () use ($actor, $subject, $representation, $type, $definition): string {
            $this->assertHumanActor($actor);
            if ($representation !== null) {
                $this->authority->assertActiveForAction($representation, $subject, $actor->personId, $definition['scope']);
            }
            $now = $this->clock->now();
            $id = $this->db->createCommand(<<<'SQL'
                INSERT INTO core.data_subject_requests
                    (subject_person_id, submitted_by_person_id, submitted_by_account_id,
                     legal_representation_id, request_type, submitted_at)
                VALUES (CAST(:subject AS uuid), CAST(:person AS uuid), CAST(:account AS uuid),
                        CAST(:representation AS uuid), :type, CAST(:now AS timestamptz))
                RETURNING id::text
                SQL, [':subject' => $subject, ':person' => $actor->personId, ':account' => $actor->accountId,
                ':representation' => $representation, ':type' => $type, ':now' => $now->format(DATE_ATOM)])->queryScalar();
            if (!is_string($id)) {
                throw new DomainException('Request could not be saved.');
            }
            if ($definition['deadline'] !== null) {
                $deadlineCode = $definition['deadline'];
                $rule = $this->deadlines->definition($deadlineCode);
                $due = $this->deadlines->dueAt($rule['calendarType'], $rule['amount'], $now);
                $this->db->createCommand(<<<'SQL'
                    INSERT INTO core.data_subject_request_deadlines
                        (request_id, deadline_code, triggered_at, calendar_type, due_at, extension_allowed)
                    VALUES (CAST(:id AS uuid), :code, CAST(:trigger AS timestamptz), :calendar,
                            CAST(:due AS timestamptz), :allowed)
                    SQL, [':id' => $id, ':code' => $deadlineCode, ':trigger' => $now->format(DATE_ATOM),
                    ':calendar' => $rule['calendarType'], ':due' => $due->format(DATE_ATOM),
                    ':allowed' => $rule['extensionDays'] > 0])->execute();
            }
            $this->event($id, 'submitted', null, 'submitted', $actor, null, $now);
            $this->record($actor, 'privacy.request.submitted', $id, ['requestType' => $type]);
            return $id;
        });
    }

    /** @return list<DataSubjectRequestView> */
    public function listOwn(AuthenticatedIdentity $actor): array
    {
        if ($actor->personId === null) {
            throw new DomainException('A human Account linked to a Person is required.');
        }
        $this->assertHumanActor($actor);
        $rows = $this->db->createCommand(<<<'SQL'
            SELECT r.id::text, r.subject_person_id::text, r.request_type, r.status,
                   r.submitted_at, r.completed_at, r.resolution_code
            FROM core.data_subject_requests r
            WHERE r.subject_person_id = CAST(:person AS uuid)
               OR (r.submitted_by_account_id = CAST(:account AS uuid) AND r.legal_representation_id IS NOT NULL
                   AND EXISTS (SELECT 1 FROM core.legal_representations a
                       JOIN core.legal_representation_scopes s ON s.representation_id = a.id
                       WHERE a.id = r.legal_representation_id AND a.status = 'verified'
                         AND a.revoked_at IS NULL AND a.valid_from <= CAST(:now AS timestamptz)
                         AND (a.valid_until IS NULL OR a.valid_until >= CAST(:now AS timestamptz))
                         AND s.scope_code = 'privacy.request.view'))
            ORDER BY r.submitted_at DESC, r.id DESC LIMIT 100
            SQL, [':person' => $actor->personId, ':account' => $actor->accountId,
            ':now' => $this->clock->now()->format(DATE_ATOM)])->queryAll();
        /** @var list<array<string, mixed>> $rows */
        return $this->viewsFromRows($rows);
    }

    public function getVisible(AuthenticatedIdentity $actor, string $id, bool $management = false): ?DataSubjectRequestView
    {
        if (!$management && $actor->personId === null) {
            return null;
        }
        $sql = <<<'SQL'
            SELECT r.id::text, r.subject_person_id::text,
                   r.request_type, r.status, r.submitted_at, r.completed_at, r.resolution_code
            FROM core.data_subject_requests r
            WHERE r.id = CAST(:id AS uuid)
            SQL;
        $params = [':id' => $id];
        if (!$management) {
            $this->assertHumanActor($actor);
            $sql .= <<<'SQL'

                AND (r.subject_person_id = CAST(:person AS uuid)
                  OR (r.submitted_by_account_id = CAST(:account AS uuid) AND r.legal_representation_id IS NOT NULL
                  AND EXISTS (SELECT 1 FROM core.legal_representations a
                      JOIN core.legal_representation_scopes s ON s.representation_id = a.id
                      WHERE a.id = r.legal_representation_id AND a.status = 'verified'
                        AND a.revoked_at IS NULL AND a.valid_from <= CAST(:now AS timestamptz)
                        AND (a.valid_until IS NULL OR a.valid_until >= CAST(:now AS timestamptz))
                        AND s.scope_code = 'privacy.request.view')))
                SQL;
            $params[':person'] = $actor->personId;
            $params[':account'] = $actor->accountId;
            $params[':now'] = $this->clock->now()->format(DATE_ATOM);
        }
        $row = $this->db->createCommand($sql, $params)->queryOne();
        if (!is_array($row)) {
            return null;
        }
        /** @var array<string, mixed> $row */
        $views = $this->viewsFromRows([$row]);
        return $views[0];
    }

    public function listManagement(int $limit, int $offset, DataSubjectRequestFilter $filter): DataSubjectRequestPage
    {
        $where = ['1=1'];
        $params = [];
        if ($filter->status !== null) {
            $where[] = 'r.status = :status';
            $params[':status'] = $filter->status;
        }
        if ($filter->requestType !== null) {
            $where[] = 'r.request_type = :type';
            $params[':type'] = $filter->requestType;
        }
        if ($filter->subjectPersonId !== null) {
            $where[] = 'r.subject_person_id = CAST(:subject AS uuid)';
            $params[':subject'] = $filter->subjectPersonId;
        }
        if ($filter->overdue !== null) {
            $params[':now'] = $this->clock->now()->format(DATE_ATOM);
            $where[] = ($filter->overdue ? '' : 'NOT ') . <<<'SQL'
                EXISTS (SELECT 1 FROM core.data_subject_request_deadlines d
                    WHERE d.request_id = r.id AND d.satisfied_at IS NULL
                      AND COALESCE(d.extended_due_at, d.due_at) < CAST(:now AS timestamptz))
                SQL;
        }
        $clause = implode(' AND ', $where);
        $total = (int) $this->db->createCommand(
            'SELECT count(*) FROM core.data_subject_requests r WHERE ' . $clause, $params,
        )->queryScalar();
        $rows = $this->db->createCommand(<<<SQL
            SELECT r.id::text, r.subject_person_id::text, r.request_type, r.status,
                   r.submitted_at, r.completed_at, r.resolution_code
            FROM core.data_subject_requests r WHERE {$clause}
            ORDER BY r.submitted_at DESC, r.id DESC LIMIT :limit OFFSET :offset
            SQL, [...$params, ':limit' => $limit, ':offset' => $offset])->queryAll();
        /** @var list<array<string, mixed>> $rows */
        return new DataSubjectRequestPage($this->viewsFromRows($rows), $limit, $offset, $total);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<DataSubjectRequestView>
     */
    private function viewsFromRows(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $params = [];
        $placeholders = [];
        foreach ($rows as $index => $row) {
            $key = ':id' . $index;
            $params[$key] = (string) $row['id'];
            $placeholders[] = 'CAST(' . $key . ' AS uuid)';
        }
        $in = implode(', ', $placeholders);
        $deadlineRows = $this->db->createCommand(<<<SQL
            SELECT request_id::text, deadline_code, triggered_at, calendar_type,
                   due_at, extended_due_at, satisfied_at
            FROM core.data_subject_request_deadlines
            WHERE request_id IN ({$in})
            ORDER BY request_id, deadline_code, id
            SQL, $params)->queryAll();
        $byRequest = [];
        $now = $this->clock->now();
        foreach ($deadlineRows as $deadline) {
            $due = new DateTimeImmutable((string) $deadline['due_at']);
            $extended = $deadline['extended_due_at'] === null ? null : new DateTimeImmutable((string) $deadline['extended_due_at']);
            $satisfied = $deadline['satisfied_at'] === null ? null : new DateTimeImmutable((string) $deadline['satisfied_at']);
            $byRequest[(string) $deadline['request_id']][] = new DataSubjectRequestDeadlineView(
                (string) $deadline['deadline_code'], new DateTimeImmutable((string) $deadline['triggered_at']),
                (string) $deadline['calendar_type'], $due, $extended, $satisfied,
                $this->deadlines->isOverdue($now, $extended ?? $due, $satisfied),
            );
        }
        $views = [];
        foreach ($rows as $row) {
            $views[] = new DataSubjectRequestView(
                (string) $row['id'], (string) $row['subject_person_id'], (string) $row['request_type'],
                (string) $row['status'], new DateTimeImmutable((string) $row['submitted_at']),
                $row['completed_at'] === null ? null : new DateTimeImmutable((string) $row['completed_at']),
                $row['resolution_code'] === null ? null : (string) $row['resolution_code'],
                $byRequest[(string) $row['id']] ?? [],
            );
        }
        return $views;
    }


    public function transition(AuthenticatedIdentity $actor, string $id, string $to, ?string $reasonCode): void
    {
        if ($reasonCode !== null) {
            MachineCode::assert($reasonCode);
        }
        $this->db->transaction(function () use ($actor, $id, $to, $reasonCode): void {
            $row = $this->db->createCommand(
                'SELECT status, request_type FROM core.data_subject_requests WHERE id = CAST(:id AS uuid) FOR UPDATE',
                [':id' => $id],
            )->queryOne();
            if (!is_array($row)) {
                throw new DomainException('Request not found.');
            }
            $from = (string) $row['status'];
            $this->states->assertTransition($from, $to);
            if (in_array($to, ['completed', 'partially_completed', 'rejected'], true)) {
                throw new DomainException('Terminal response requires execution evidence.');
            }
            if ($to === 'cancelled' && $reasonCode === null) {
                throw new DomainException('Resolution reason is required.');
            }
            $now = $this->clock->now();
            $this->db->createCommand(<<<'SQL'
                UPDATE core.data_subject_requests SET status = :status,
                    completed_at = CASE WHEN :terminal THEN CAST(:now AS timestamptz) ELSE completed_at END,
                    resolution_code = :reason, updated_at = CAST(:now AS timestamptz)
                WHERE id = CAST(:id AS uuid)
                SQL, [':status' => $to, ':terminal' => false,
                ':now' => $now->format(DATE_ATOM), ':reason' => $reasonCode, ':id' => $id])->execute();
            $this->event($id, 'status_changed', $from, $to, $actor, $reasonCode, $now);
            $this->record($actor, $to === 'cancelled' ? 'privacy.request.cancelled' : 'privacy.request.status_changed',
                $id, array_filter(['fromStatus' => $from, 'toStatus' => $to, 'reasonCode' => $reasonCode]));
        });
    }

    public function extendDeadline(AuthenticatedIdentity $actor, string $id, string $deadlineCode, string $reasonCode): DateTimeImmutable
    {
        MachineCode::assert($reasonCode);
        return $this->db->transaction(function () use ($actor, $id, $deadlineCode, $reasonCode): DateTimeImmutable {
            $row = $this->db->createCommand(<<<'SQL'
                SELECT d.due_at, d.extended_at, d.satisfied_at, d.extension_allowed, r.status
                FROM core.data_subject_request_deadlines d
                JOIN core.data_subject_requests r ON r.id = d.request_id
                WHERE d.request_id = CAST(:id AS uuid) AND d.deadline_code = :code
                FOR UPDATE OF d, r
                SQL, [':id' => $id, ':code' => $deadlineCode])->queryOne();
            if (!is_array($row) || !in_array($row['extension_allowed'], [true, 't', 1], true)
                || $row['extended_at'] !== null || $row['satisfied_at'] !== null
                || in_array($row['status'], ['completed', 'partially_completed', 'rejected', 'cancelled'], true)) {
                throw new DomainException('Deadline cannot be extended.');
            }
            $now = $this->clock->now();
            $extended = $this->deadlines->extendedDueAt($deadlineCode, new DateTimeImmutable((string) $row['due_at']));
            $this->db->createCommand(<<<'SQL'
                UPDATE core.data_subject_request_deadlines
                SET extended_at = CAST(:now AS timestamptz), extended_due_at = CAST(:due AS timestamptz),
                    extension_reason_code = :reason
                WHERE request_id = CAST(:id AS uuid) AND deadline_code = :code
                SQL, [':now' => $now->format(DATE_ATOM), ':due' => $extended->format(DATE_ATOM),
                ':reason' => $reasonCode, ':id' => $id, ':code' => $deadlineCode])->execute();
            $this->event($id, 'deadline_extended', null, null, $actor, $reasonCode, $now);
            $this->record($actor, 'privacy.request.deadline_extended', $id,
                ['deadlineCode' => $deadlineCode, 'reasonCode' => $reasonCode]);
            return $extended;
        });
    }

    private function assertHumanActor(AuthenticatedIdentity $actor): void
    {
        $person = $this->db->createCommand(<<<'SQL'
            SELECT person_id::text FROM core.accounts
            WHERE id = CAST(:account AS uuid) AND account_type = 'human'
            SQL, [':account' => $actor->accountId])->queryScalar();
        if (!is_string($person) || $person !== $actor->personId) {
            throw new DomainException('A human Account linked to a Person is required.');
        }
    }

    private function event(string $id, string $code, ?string $from, ?string $to, AuthenticatedIdentity $actor, ?string $reason, DateTimeImmutable $now): void
    {
        $this->db->createCommand(<<<'SQL'
            INSERT INTO core.data_subject_request_events
                (request_id, event_code, from_status, to_status, actor_account_id, reason_code, occurred_at)
            VALUES (CAST(:id AS uuid), :code, :from_status, :to_status,
                    CAST(:actor AS uuid), :reason, CAST(:now AS timestamptz))
            SQL, [':id' => $id, ':code' => $code, ':from_status' => $from, ':to_status' => $to,
            ':actor' => $actor->accountId, ':reason' => $reason, ':now' => $now->format(DATE_ATOM)])->execute();
    }

    /** @param array<string, string> $metadata */
    private function record(AuthenticatedIdentity $actor, string $action, string $id, array $metadata): void
    {
        $this->audit->record(new WriteAuditEventCommand('account', $actor->accountId, $action,
            resourceType: 'core.data_subject_request', resourceId: $id, metadata: $metadata));
    }
}
