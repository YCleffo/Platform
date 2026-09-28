<?php

declare(strict_types=1);

namespace App\Core\Audit\Infrastructure;

use App\Core\Audit\Application\AuditEventReceipt;
use App\Core\Audit\Application\AuditPayloadPolicy;
use App\Core\Audit\Application\AuditWriter;
use App\Core\Audit\Application\WriteAuditEventCommand;
use RuntimeException;
use Yiisoft\Db\Connection\ConnectionInterface;

final readonly class PostgresAuditWriter implements AuditWriter
{
    public function __construct(private ConnectionInterface $db, private AuditPayloadPolicy $policy) {}

    public function write(WriteAuditEventCommand $command): AuditEventReceipt
    {
        $this->policy->assertAllowed($command);
        $row = $this->db->createCommand(
            <<<'SQL'
            INSERT INTO core.audit_events (
                actor_type,
                actor_account_id,
                action,
                resource_type,
                resource_id,
                request_id,
                correlation_id,
                ip_address,
                user_agent,
                success,
                error_code,
                before_data,
                after_data,
                metadata
            )
            VALUES (
                CAST(:actor_type AS core.audit_actor_type),
                CAST(:actor_account_id AS uuid),
                :action,
                :resource_type,
                :resource_id,
                CAST(:request_id AS uuid),
                CAST(:correlation_id AS uuid),
                CAST(:ip_address AS inet),
                :user_agent,
                :success,
                :error_code,
                CAST(:before_data AS jsonb),
                CAST(:after_data AS jsonb),
                CAST(:metadata AS jsonb)
            )
            RETURNING
                id,
                event_id::text AS event_id,
                to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS occurred_at
            SQL,
            [
                ':actor_type' => $command->actorType,
                ':actor_account_id' => $command->actorAccountId,
                ':action' => $command->action,
                ':resource_type' => $command->resourceType,
                ':resource_id' => $command->resourceId,
                ':request_id' => $command->requestId,
                ':correlation_id' => $command->correlationId,
                ':ip_address' => $command->ipAddress,
                ':user_agent' => $command->userAgent,
                ':success' => $command->success,
                ':error_code' => $command->errorCode,
                ':before_data' => self::json($command->beforeData),
                ':after_data' => self::json($command->afterData),
                ':metadata' => self::json($command->metadata),
            ],
        )->queryOne();

        if (!is_array($row)) {
            throw new RuntimeException('PostgreSQL did not return the audit event receipt.');
        }

        return new AuditEventReceipt(
            (int) $row['id'],
            (string) $row['event_id'],
            (string) $row['occurred_at'],
        );
    }

    /** @param array<array-key, mixed>|null $value */
    private static function json(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
