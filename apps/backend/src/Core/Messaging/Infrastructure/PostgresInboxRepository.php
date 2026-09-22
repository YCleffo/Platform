<?php

declare(strict_types=1);

namespace App\Core\Messaging\Infrastructure;

use App\Core\Messaging\Application\InboxEvent;
use App\Core\Messaging\Application\InboxRepository;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresInboxRepository implements InboxRepository
{
    public function __construct(private ConnectionInterface $db) {}

    public function find(string $eventId, string $consumer): ?InboxEvent
    {
        $row = $this->db->createCommand(
            self::SELECT_COLUMNS . <<<'SQL'

            FROM core.inbox_events
            WHERE event_id = CAST(:event_id AS uuid) AND consumer = :consumer
            SQL,
            [':event_id' => $eventId, ':consumer' => $consumer],
        )->queryOne();

        return is_array($row) ? self::map($row) : null;
    }

    public function registerReceived(string $eventId, string $consumer): InboxEvent
    {
        $this->db->createCommand(
            <<<'SQL'
            INSERT INTO core.inbox_events (event_id, consumer)
            VALUES (CAST(:event_id AS uuid), :consumer)
            ON CONFLICT (event_id, consumer) DO NOTHING
            SQL,
            [':event_id' => $eventId, ':consumer' => $consumer],
        )->execute();

        return $this->find($eventId, $consumer)
            ?? throw new RuntimeException('PostgreSQL did not return the registered inbox event.');
    }

    public function markProcessed(string $eventId, string $consumer): bool
    {
        return $this->db->createCommand(
            <<<'SQL'
            UPDATE core.inbox_events
            SET
                status = 'processed',
                processed_at = now(),
                locked_by = NULL,
                locked_until = NULL,
                last_error = NULL
            WHERE event_id = CAST(:event_id AS uuid)
                AND consumer = :consumer
                AND status <> 'processed'
            SQL,
            [':event_id' => $eventId, ':consumer' => $consumer],
        )->execute() === 1;
    }

    public function markFailed(
        string $eventId,
        string $consumer,
        string $error,
        bool $dead = false,
    ): bool {
        return $this->db->createCommand(
            <<<'SQL'
            UPDATE core.inbox_events
            SET
                status = CAST(:status AS core.inbox_status),
                attempts = attempts + 1,
                locked_by = NULL,
                locked_until = NULL,
                last_error = :last_error
            WHERE event_id = CAST(:event_id AS uuid)
                AND consumer = :consumer
                AND status <> 'processed'
            SQL,
            [
                ':event_id' => $eventId,
                ':consumer' => $consumer,
                ':status' => $dead ? 'dead' : 'failed',
                ':last_error' => $error,
            ],
        )->execute() === 1;
    }

    private const string SELECT_COLUMNS = <<<'SQL'
        SELECT
            id,
            event_id::text AS event_id,
            consumer,
            status::text AS status,
            attempts,
            to_char(received_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS received_at,
            to_char(processed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS processed_at,
            locked_by,
            to_char(locked_until AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS locked_until,
            last_error
        SQL;

    /** @param array<array-key, mixed> $row */
    private static function map(array $row): InboxEvent
    {
        return new InboxEvent(
            id: (int) $row['id'],
            eventId: (string) $row['event_id'],
            consumer: (string) $row['consumer'],
            status: (string) $row['status'],
            attempts: (int) $row['attempts'],
            receivedAt: (string) $row['received_at'],
            processedAt: self::nullableString($row['processed_at']),
            lockedBy: self::nullableString($row['locked_by']),
            lockedUntil: self::nullableString($row['locked_until']),
            lastError: self::nullableString($row['last_error']),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
