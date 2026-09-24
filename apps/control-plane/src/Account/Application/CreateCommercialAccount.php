<?php

declare(strict_types=1);

namespace ControlPlane\Account\Application;

use ControlPlane\Shared\Domain\IdentifierPolicy;
use ControlPlane\Shared\Domain\TextPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CreateCommercialAccount
{
    public function __construct(private Connection $connection)
    {
    }

    public function create(string $userId, string $code, string $displayName, ?string $legalName, string $idempotencyKey, string $correlationId): string
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $code = IdentifierPolicy::machineCode($code);
        $displayName = TextPolicy::label($displayName, 200);
        $legalName = $legalName === null ? null : TextPolicy::label($legalName, 240);
        $idempotencyKey = IdentifierPolicy::opaqueKey($idempotencyKey);
        $hash = hash('sha256', json_encode([$code, $displayName, $legalName], JSON_THROW_ON_ERROR));
        $scope = 'account.create.' . str_replace('-', '', strtolower($userId));

        return $this->connection->transactional(function (Connection $connection) use ($userId, $code, $displayName, $legalName, $idempotencyKey, $hash, $scope, $correlationId): string {
            $inserted = $connection->executeStatement(
                "INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status, expires_at) VALUES (?, ?, decode(?, 'hex'), 'pending', now() + interval '24 hours') ON CONFLICT (scope, idempotency_key) DO NOTHING",
                [$scope, $idempotencyKey, $hash],
            );
            $record = $connection->fetchAssociative('SELECT encode(request_sha256, \'hex\') AS request_hash, status, result_reference FROM commercial.idempotency_keys WHERE scope = ? AND idempotency_key = ? FOR UPDATE', [$scope, $idempotencyKey]);
            if ($record === false) {
                throw new \LogicException('Idempotency record unavailable.');
            }
            if (!is_string($record['request_hash']) || !hash_equals($record['request_hash'], $hash)) {
                throw new \DomainException('Idempotency key was reused with a different request.');
            }
            if ($inserted === 0) {
                if ($record['status'] !== 'completed' || !is_string($record['result_reference'])) {
                    throw new \DomainException('Idempotency operation is already in progress.');
                }

                return $record['result_reference'];
            }
            $active = $connection->fetchOne("SELECT 1 FROM commercial.control_users WHERE id = ? AND status = 'active'", [$userId]);
            if ($active === false) {
                throw new \DomainException('Only an active verified user can create an account.');
            }
            $accountId = $connection->fetchOne('INSERT INTO commercial.commercial_accounts (code, display_name, legal_name, onboarding_state) VALUES (?, ?, ?, \'profile_required\') RETURNING id', [$code, $displayName, $legalName]);
            if (!is_string($accountId)) {
                throw new \LogicException('Account ID unavailable.');
            }
            $connection->executeStatement("INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, 'owner')", [$userId, $accountId]);
            $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, ?, 'commercial_account.created', 'commercial_account', ?, ?, 'success')", [$userId, $accountId, $accountId, $correlationId]);
            $connection->executeStatement("INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('commercial_account.created', 'commercial_account', ?, jsonb_build_object('account_id', ?::text, 'owner_user_id', ?::text))", [$accountId, $accountId, $userId]);
            $connection->executeStatement("UPDATE commercial.idempotency_keys SET status = 'completed', result_reference = ? WHERE scope = ? AND idempotency_key = ?", [$accountId, $scope, $idempotencyKey]);

            return $accountId;
        });
    }
}
