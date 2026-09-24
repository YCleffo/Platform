<?php

declare(strict_types=1);

namespace ControlPlane\Account\Application;

use ControlPlane\Shared\Domain\IdentifierPolicy;
use ControlPlane\Shared\Domain\TextPolicy;
use Doctrine\DBAL\Connection;

final readonly class CreateCommercialAccount
{
    public function __construct(private Connection $connection)
    {
    }

    public function create(string $code, string $displayName, string $idempotencyKey): string
    {
        $code = IdentifierPolicy::machineCode($code);
        $displayName = TextPolicy::label($displayName, 200);
        $idempotencyKey = IdentifierPolicy::opaqueKey($idempotencyKey);
        $hash = hash('sha256', json_encode([$code, $displayName], JSON_THROW_ON_ERROR));

        return $this->connection->transactional(function (Connection $connection) use ($code, $displayName, $idempotencyKey, $hash): string {
            $inserted = $connection->executeStatement(
                "INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status) VALUES ('account.create', ?, decode(?, 'hex'), 'pending') ON CONFLICT (scope, idempotency_key) DO NOTHING",
                [$idempotencyKey, $hash],
            );
            $record = $connection->fetchAssociative(
                "SELECT encode(request_sha256, 'hex') AS request_hash, status, result_reference FROM commercial.idempotency_keys WHERE scope = 'account.create' AND idempotency_key = ? FOR UPDATE",
                [$idempotencyKey],
            );
            if ($record === false) {
                throw new \LogicException('Idempotency record unavailable.');
            }
            if (!is_string($record['request_hash']) || !hash_equals($record['request_hash'], $hash)) {
                throw new \DomainException('Idempotency key was reused with a different request.');
            }
            if ($inserted === 0) {
                if ($record['status'] !== 'completed' || !is_string($record['result_reference'])) {
                    throw new \LogicException('Idempotency operation is not complete.');
                }

                return $record['result_reference'];
            }

            $accountId = $connection->fetchOne(
                "INSERT INTO commercial.commercial_accounts (code, display_name) VALUES (?, ?) RETURNING id",
                [$code, $displayName],
            );
            if (!is_string($accountId)) {
                throw new \LogicException('Account ID unavailable.');
            }
            $connection->executeStatement(
                "INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, outcome, metadata) VALUES ('system', ?, 'account.created', 'commercial_account', ?, 'success', '{}'::jsonb)",
                [$accountId, $accountId],
            );
            $connection->executeStatement(
                "INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('account.created', 'commercial_account', ?, jsonb_build_object('account_id', ?::text))",
                [$accountId, $accountId],
            );
            $connection->executeStatement(
                "UPDATE commercial.idempotency_keys SET status = 'completed', result_reference = ? WHERE scope = 'account.create' AND idempotency_key = ?",
                [$accountId, $idempotencyKey],
            );

            return $accountId;
        });
    }
}
