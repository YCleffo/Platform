<?php

declare(strict_types=1);

namespace App\Core\Messaging\Infrastructure;

use App\Core\Messaging\Application\OutboxEvent;
use App\Core\Messaging\Application\OutboxEventCommand;
use App\Core\Messaging\Application\OutboxRepository;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresOutboxRepository implements OutboxRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function enqueue(OutboxEventCommand $command): OutboxEvent
    {
        $row = $this->db->createCommand(
            <<<'SQL'
            INSERT INTO core.outbox_events (
                producer_module,
                event_type,
                aggregate_type,
                aggregate_id,
                payload,
                headers,
                correlation_id,
                causation_id,
                available_at
            )
            VALUES (
                :producer_module,
                :event_type,
                :aggregate_type,
                :aggregate_id,
                CAST(:payload AS jsonb),
                CAST(:headers AS jsonb),
                CAST(:correlation_id AS uuid),
                CAST(:causation_id AS uuid),
                COALESCE(CAST(:available_at AS timestamptz), now())
            )
            RETURNING
                id,
                event_id::text AS event_id,
                producer_module,
                event_type,
                aggregate_type,
                aggregate_id,
                payload::text AS payload,
                headers::text AS headers,
                correlation_id::text AS correlation_id,
                causation_id::text AS causation_id,
                status::text AS status,
                attempts,
                to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS occurred_at,
                to_char(available_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS available_at,
                to_char(published_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS published_at,
                locked_by,
                to_char(locked_until AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS locked_until,
                last_error
            SQL,
            [
                ':producer_module' => $command->producerModule,
                ':event_type' => $command->eventType,
                ':aggregate_type' => $command->aggregateType,
                ':aggregate_id' => $command->aggregateId,
                ':payload' => json_encode($command->payload, JSON_THROW_ON_ERROR),
                ':headers' => json_encode($command->headers, JSON_THROW_ON_ERROR),
                ':correlation_id' => $command->correlationId,
                ':causation_id' => $command->causationId,
                ':available_at' => $command->availableAt,
            ],
        )->queryOne();

        if (!is_array($row)) {
            throw new RuntimeException('PostgreSQL did not return the outbox event.');
        }
        return self::map($row);
    }

    public function claimPending(string $worker, int $limit, int $leaseSeconds): array
    {
        return $this->claim($worker, $limit, $leaseSeconds, []);
    }

    public function claimPendingByTypes(
        string $worker,
        array $eventTypes,
        int $limit,
        int $leaseSeconds,
    ): array {
        if ($eventTypes === []) {
            return [];
        }

        return $this->claim($worker, $limit, $leaseSeconds, $eventTypes);
    }

    public function markPublished(int $id, ?string $worker = null): bool
    {
        $workerFilter = $worker === null ? '' : 'AND locked_by = :worker';
        $sql = str_replace(
            '/* worker_filter */',
            $workerFilter,
            <<<'SQL'
            UPDATE core.outbox_events
            SET
                status = 'published',
                published_at = now(),
                payload = CASE WHEN event_type IN ('communication.email.requested',
                    'auth.password.reset.requested', 'auth.email.verification.requested',
                    'auth.account.created') THEN '{}'::jsonb ELSE payload END,
                headers = CASE WHEN event_type IN ('communication.email.requested',
                    'auth.password.reset.requested', 'auth.email.verification.requested',
                    'auth.account.created') THEN '{}'::jsonb ELSE headers END,
                locked_by = NULL,
                locked_until = NULL,
                last_error = NULL
            WHERE id = :id
              AND status = 'processing'
              /* worker_filter */
            SQL,
        );
        $params = [':id' => $id];
        if ($worker !== null) {
            $params[':worker'] = $worker;
        }

        return $this->db->createCommand(
            $sql,
            $params,
        )->execute() === 1;
    }

    public function markFailed(
        int $id,
        string $error,
        bool $dead = false,
        ?string $availableAt = null,
        ?string $worker = null,
    ): bool {
        $workerFilter = $worker === null ? '' : 'AND locked_by = :worker';
        $sql = str_replace(
            '/* worker_filter */',
            $workerFilter,
            <<<'SQL'
            UPDATE core.outbox_events
            SET
                status = CAST(:status AS core.outbox_status),
                available_at = COALESCE(CAST(:available_at AS timestamptz), available_at),
                locked_by = NULL,
                locked_until = NULL,
                payload = CASE WHEN CAST(:scrub_payload AS boolean) AND event_type IN
                    ('communication.email.requested', 'auth.password.reset.requested',
                     'auth.email.verification.requested', 'auth.account.created')
                    THEN '{}'::jsonb ELSE payload END,
                headers = CASE WHEN CAST(:scrub_headers AS boolean) AND event_type IN
                    ('communication.email.requested', 'auth.password.reset.requested',
                     'auth.email.verification.requested', 'auth.account.created')
                    THEN '{}'::jsonb ELSE headers END,
                last_error = :last_error
            WHERE id = :id
              AND status = 'processing'
              /* worker_filter */
            SQL,
        );
        $params = [
            ':id' => $id,
            ':status' => $dead ? 'dead' : 'failed',
            ':scrub_payload' => $dead ? 'true' : 'false',
            ':scrub_headers' => $dead ? 'true' : 'false',
            ':available_at' => $availableAt,
            ':last_error' => $error,
        ];
        if ($worker !== null) {
            $params[':worker'] = $worker;
        }

        return $this->db->createCommand(
            $sql,
            $params,
        )->execute() === 1;
    }

    /**
     * @param list<string> $eventTypes
     *
     * @return list<OutboxEvent>
     */
    private function claim(
        string $worker,
        int $limit,
        int $leaseSeconds,
        array $eventTypes,
    ): array {
        $params = [
            ':limit' => max(1, min(100, $limit)),
            ':worker' => $worker,
            ':lease_seconds' => max(1, min(3600, $leaseSeconds)),
        ];
        $eventFilter = '';

        if ($eventTypes !== []) {
            $placeholders = [];
            foreach (array_values(array_unique($eventTypes)) as $index => $eventType) {
                $placeholder = ':event_type_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $eventType;
            }
            $eventFilter = sprintf(
                'AND event_type IN (%s)',
                implode(', ', $placeholders),
            );
        }

        $sql = str_replace(
            '/* event_type_filter */',
            $eventFilter,
            <<<'SQL'
            WITH candidates AS (
                SELECT id
                FROM core.outbox_events
                WHERE (
                        status IN ('pending', 'failed')
                        OR (status = 'processing' AND locked_until < now())
                    )
                    AND available_at <= now()
                    AND (locked_until IS NULL OR locked_until < now())
                    /* event_type_filter */
                ORDER BY available_at, id
                FOR UPDATE SKIP LOCKED
                LIMIT CAST(:limit AS integer)
            )
            UPDATE core.outbox_events e
            SET
                status = 'processing',
                attempts = e.attempts + 1,
                locked_by = :worker,
                locked_until = now() + make_interval(secs => CAST(:lease_seconds AS integer)),
                last_error = NULL
            FROM candidates c
            WHERE e.id = c.id
            RETURNING
                e.id,
                e.event_id::text AS event_id,
                e.producer_module,
                e.event_type,
                e.aggregate_type,
                e.aggregate_id,
                e.payload::text AS payload,
                e.headers::text AS headers,
                e.correlation_id::text AS correlation_id,
                e.causation_id::text AS causation_id,
                e.status::text AS status,
                e.attempts,
                to_char(e.occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS occurred_at,
                to_char(e.available_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS available_at,
                to_char(e.published_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS published_at,
                e.locked_by,
                to_char(e.locked_until AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS locked_until,
                e.last_error
            SQL,
        );

        $rows = $this->db->createCommand(
            $sql,
            $params,
        )->queryAll();

        /** @var list<array<array-key, mixed>> $rows */
        return array_map(self::map(...), $rows);
    }

    /** @param array<array-key, mixed> $row */
    private static function map(array $row): OutboxEvent
    {
        return new OutboxEvent(
            id: (int) $row['id'],
            eventId: (string) $row['event_id'],
            producerModule: (string) $row['producer_module'],
            eventType: (string) $row['event_type'],
            aggregateType: self::nullableString($row['aggregate_type']),
            aggregateId: self::nullableString($row['aggregate_id']),
            payload: self::jsonObject((string) $row['payload']),
            headers: self::jsonObject((string) $row['headers']),
            correlationId: self::nullableString($row['correlation_id']),
            causationId: self::nullableString($row['causation_id']),
            status: (string) $row['status'],
            attempts: (int) $row['attempts'],
            occurredAt: (string) $row['occurred_at'],
            availableAt: (string) $row['available_at'],
            publishedAt: self::nullableString($row['published_at']),
            lockedBy: self::nullableString($row['locked_by']),
            lockedUntil: self::nullableString($row['locked_until']),
            lastError: self::nullableString($row['last_error']),
        );
    }

    /** @return array<array-key, mixed> */
    private static function jsonObject(string $json): array
    {
        $value = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : [];
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
