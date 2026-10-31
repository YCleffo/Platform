<?php

declare(strict_types=1);

namespace ControlPlane\Account\Invitation\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Identity\Application\AuthFailure;
use ControlPlane\Identity\Domain\EmailPolicy;
use ControlPlane\Identity\Security\DatabaseRateLimiter;
use ControlPlane\Identity\Security\TokenCodec;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

final readonly class InvitationService
{
    private const ROLES = ['admin', 'billing', 'technical'];

    public function __construct(
        private Connection $connection,
        private AccountAuthorization $authorization,
        private TokenCodec $tokens,
        private DatabaseRateLimiter $rateLimiter,
        private int $ttlSeconds,
    ) {
        if ($ttlSeconds < 60) {
            throw new \InvalidArgumentException('Invitation TTL is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function create(string $actorId, string $accountId, string $emailInput, string $role, string $idempotencyKey, string $correlationId): array
    {
        $actorId = $this->id($actorId);
        $accountId = $this->id($accountId);
        $email = EmailPolicy::normalize($emailInput);
        $role = $this->role($role);
        $key = IdentifierPolicy::opaqueKey($idempotencyKey);
        $this->rateLimiter->consume('account.invitation.create', $actorId, 20, 3600);
        $hash = hash('sha256', json_encode([$accountId, $email['normalized'], $role], JSON_THROW_ON_ERROR));
        $scope = 'invitation.create.' . str_replace('-', '', $accountId);

        return $this->connection->transactional(function (Connection $db) use ($actorId, $accountId, $email, $role, $key, $hash, $scope, $correlationId): array {
            // Authorization is evaluated on every branch, including idempotent
            // replay, under the same account lock used by membership operations.
            $this->lockAccount($db, $accountId);
            $this->authorization->requirePermission($actorId, $accountId, 'membership.manage');

            $inserted = $db->executeStatement(
                "INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status, expires_at) VALUES (?, ?, decode(?, 'hex'), 'pending', now() + interval '24 hours') ON CONFLICT (scope, idempotency_key) DO NOTHING",
                [$scope, $key, $hash],
            );
            $record = $db->fetchAssociative("SELECT encode(request_sha256, 'hex') AS request_hash, status, result_reference FROM commercial.idempotency_keys WHERE scope = ? AND idempotency_key = ? FOR UPDATE", [$scope, $key]);
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
                $existing = $this->findById($db, $record['result_reference']);
                if ($existing === null) {
                    throw new \LogicException('Invitation result is unavailable.');
                }

                return $existing;
            }

            $db->executeStatement(
                "UPDATE commercial.commercial_account_invitations SET status = 'expired', updated_at = now() WHERE commercial_account_id = ? AND email_normalized = ? AND status = 'pending' AND expires_at <= now()",
                [$accountId, $email['normalized']],
            );
            $pending = $db->fetchOne(
                "SELECT 1 FROM commercial.commercial_account_invitations WHERE commercial_account_id = ? AND email_normalized = ? AND status = 'pending' FOR UPDATE",
                [$accountId, $email['normalized']],
            );
            if ($pending !== false) {
                throw new \DomainException('An active invitation already exists for this email.');
            }

            $invitationId = Uuid::v7()->toRfc4122();
            $token = $this->tokens->invitationToken($invitationId, 1);
            $db->executeStatement(
                "INSERT INTO commercial.commercial_account_invitations (id, commercial_account_id, email, email_normalized, role, invited_by, token_hash, token_version, status, expires_at) VALUES (?, ?, ?, ?, ?, ?, decode(?, 'hex'), 1, 'pending', now() + (? * interval '1 second'))",
                [$invitationId, $accountId, $email['email'], $email['normalized'], $role, $actorId, $this->tokens->hash($token), $this->ttlSeconds],
            );
            $this->audit($db, $actorId, $accountId, $invitationId, 'commercial_invitation.created', $correlationId, ['role' => $role]);
            $this->enqueue($db, $invitationId, $accountId);
            $db->executeStatement('UPDATE commercial.idempotency_keys SET status = \'completed\', result_reference = ? WHERE scope = ? AND idempotency_key = ?', [$invitationId, $scope, $key]);

            $created = $this->findById($db, $invitationId);
            if ($created === null) {
                throw new \LogicException('Invitation result is unavailable.');
            }

            return $created;
        });
    }

    /** @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int} */
    public function list(string $actorId, string $accountId, int $limit, int $offset): array
    {
        $actorId = $this->id($actorId);
        $accountId = $this->id($accountId);
        $this->authorization->requirePermission($actorId, $accountId, 'membership.manage');
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $total = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM commercial.commercial_account_invitations WHERE commercial_account_id = ?',
            [$accountId],
        );
        $items = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT i.id, i.email, i.role,
       CASE WHEN i.status = 'pending' AND i.expires_at <= now() THEN 'expired' ELSE i.status END AS status,
       i.created_at, i.expires_at, i.accepted_at, i.declined_at, i.revoked_at, i.invited_by, u.email AS inviter_email
FROM commercial.commercial_account_invitations i
JOIN commercial.control_users u ON u.id = i.invited_by
WHERE i.commercial_account_id = ?
ORDER BY i.created_at DESC, i.id
LIMIT ? OFFSET ?
SQL,
            [$accountId, $limit, $offset],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    public function revoke(string $actorId, string $accountId, string $invitationId, string $correlationId): void
    {
        $actorId = $this->id($actorId);
        $accountId = $this->id($accountId);
        $invitationId = $this->id($invitationId);

        $this->connection->transactional(function (Connection $db) use ($actorId, $accountId, $invitationId, $correlationId): void {
            $this->lockAccount($db, $accountId);
            $this->authorization->requirePermission($actorId, $accountId, 'membership.manage');
            $invitation = $db->fetchAssociative(
                'SELECT id, status FROM commercial.commercial_account_invitations WHERE id = ? AND commercial_account_id = ? FOR UPDATE',
                [$invitationId, $accountId],
            );
            if ($invitation === false) {
                throw new InvitationUnavailable();
            }
            if ($invitation['status'] === 'revoked') {
                return;
            }
            if ($invitation['status'] !== 'pending') {
                throw new \DomainException('Invitation is no longer pending.');
            }
            $db->executeStatement("UPDATE commercial.commercial_account_invitations SET status = 'revoked', revoked_at = now(), updated_at = now() WHERE id = ?", [$invitationId]);
            $this->audit($db, $actorId, $accountId, $invitationId, 'commercial_invitation.revoked', $correlationId, []);
        });
    }

    /** @return array<string, mixed> */
    public function resend(string $actorId, string $accountId, string $invitationId, string $correlationId): array
    {
        $actorId = $this->id($actorId);
        $accountId = $this->id($accountId);
        $invitationId = $this->id($invitationId);
        $this->rateLimiter->consume('account.invitation.resend', $actorId, 10, 3600);

        return $this->connection->transactional(function (Connection $db) use ($actorId, $accountId, $invitationId, $correlationId): array {
            $this->lockAccount($db, $accountId);
            $this->authorization->requirePermission($actorId, $accountId, 'membership.manage');
            $invitation = $db->fetchAssociative(
                'SELECT id, status, token_version, expires_at FROM commercial.commercial_account_invitations WHERE id = ? AND commercial_account_id = ? FOR UPDATE',
                [$invitationId, $accountId],
            );
            if ($invitation === false) {
                throw new InvitationUnavailable();
            }
            if ($invitation['status'] !== 'pending') {
                throw new \DomainException('Invitation is no longer pending.');
            }
            if (strtotime((string) $invitation['expires_at']) <= time()) {
                throw new \DomainException('Invitation has expired.');
            }
            $version = (int) $invitation['token_version'] + 1;
            $token = $this->tokens->invitationToken($invitationId, $version);
            $db->executeStatement(
                "UPDATE commercial.commercial_account_invitations SET token_hash = decode(?, 'hex'), token_version = ?, expires_at = now() + (? * interval '1 second'), updated_at = now() WHERE id = ?",
                [$this->tokens->hash($token), $version, $this->ttlSeconds, $invitationId],
            );
            $this->audit($db, $actorId, $accountId, $invitationId, 'commercial_invitation.resent', $correlationId, []);
            $this->enqueue($db, $invitationId, $accountId);

            $result = $this->findById($db, $invitationId);
            if ($result === null) {
                throw new \LogicException('Invitation result is unavailable.');
            }

            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function preview(string $token): array
    {
        $invitationId = $this->invitationIdFromToken($token);
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
SELECT i.id, i.email, i.email_normalized, i.role, i.status, i.expires_at, a.display_name, a.status AS account_status
FROM commercial.commercial_account_invitations i
JOIN commercial.commercial_accounts a ON a.id = i.commercial_account_id
WHERE i.id = ? AND i.token_hash = decode(?, 'hex')
SQL,
            [$invitationId, $this->tokens->hash($token)],
        );
        if ($row === false) {
            throw new InvitationUnavailable();
        }
        $status = $this->effectiveStatus((string) $row['status'], (string) $row['expires_at']);

        return [
            'status' => $status,
            'organization_name' => $row['display_name'],
            'role' => $row['role'],
            'expires_at' => $row['expires_at'],
            'email' => $this->maskEmail((string) $row['email']),
        ];
    }

    /** @return array{account_id: string, role: string, status: string} */
    public function accept(string $userId, string $token, string $correlationId): array
    {
        $userId = $this->id($userId);
        $user = $this->verifiedUser($userId);
        $invitationId = $this->invitationIdFromToken($token);
        $tokenHash = $this->tokens->hash($token);

        return $this->connection->transactional(function (Connection $db) use ($userId, $user, $invitationId, $tokenHash, $correlationId): array {
            $initial = $db->fetchAssociative(
                'SELECT commercial_account_id FROM commercial.commercial_account_invitations WHERE id = ? AND token_hash = decode(?, \'hex\')',
                [$invitationId, $tokenHash],
            );
            if ($initial === false) {
                throw new InvitationUnavailable();
            }
            $accountId = (string) $initial['commercial_account_id'];
            $this->lockAccount($db, $accountId);
            $invitation = $db->fetchAssociative(
                'SELECT id, email_normalized, role, status, expires_at FROM commercial.commercial_account_invitations WHERE id = ? AND commercial_account_id = ? AND token_hash = decode(?, \'hex\') FOR UPDATE',
                [$invitationId, $accountId, $tokenHash],
            );
            if ($invitation === false) {
                throw new InvitationUnavailable();
            }
            $status = $this->effectiveStatus((string) $invitation['status'], (string) $invitation['expires_at']);
            if ($status === 'expired') {
                throw new AuthFailure('invitation_expired', 'This invitation has expired.');
            }
            if ($status !== 'pending') {
                throw new AuthFailure('invitation_not_available', 'This invitation is no longer available.');
            }
            if (!hash_equals((string) $invitation['email_normalized'], (string) $user['email_normalized'])) {
                throw new AuthFailure('invitation_email_mismatch', 'This invitation belongs to a different email address.');
            }
            $role = (string) $invitation['role'];
            if (!in_array($role, self::ROLES, true)) {
                throw new AuthFailure('invitation_not_available', 'This invitation is no longer available.');
            }
            $accountStatus = $db->fetchOne('SELECT status FROM commercial.commercial_accounts WHERE id = ?', [$accountId]);
            if (!is_string($accountStatus) || !in_array($accountStatus, ['draft', 'active'], true)) {
                throw new AuthFailure('invitation_account_unavailable', 'This commercial account is not available.');
            }

            $membership = $db->fetchAssociative(
                'SELECT id, role, revoked_at FROM commercial.commercial_account_memberships WHERE control_user_id = ? AND commercial_account_id = ? FOR UPDATE',
                [$userId, $accountId],
            );
            if ($membership !== false && $membership['revoked_at'] === null) {
                // Never silently change an existing active role.
                $effectiveRole = (string) $membership['role'];
            } elseif ($membership !== false) {
                $db->executeStatement('UPDATE commercial.commercial_account_memberships SET role = ?, revoked_at = NULL WHERE id = ?', [$role, $membership['id']]);
                $effectiveRole = $role;
            } else {
                $db->executeStatement('INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role) VALUES (?, ?, ?)', [$userId, $accountId, $role]);
                $effectiveRole = $role;
            }

            $db->executeStatement("UPDATE commercial.commercial_account_invitations SET status = 'accepted', accepted_by = ?, accepted_at = now(), updated_at = now() WHERE id = ?", [$userId, $invitationId]);
            $this->audit($db, $userId, $accountId, $invitationId, 'commercial_invitation.accepted', $correlationId, ['role' => $effectiveRole]);

            return ['account_id' => $accountId, 'role' => $effectiveRole, 'status' => 'accepted'];
        });
    }

    /** @return array{status: string} */
    public function decline(string $userId, string $token, string $correlationId): array
    {
        $userId = $this->id($userId);
        $user = $this->verifiedUser($userId);
        $invitationId = $this->invitationIdFromToken($token);
        $tokenHash = $this->tokens->hash($token);

        return $this->connection->transactional(function (Connection $db) use ($userId, $user, $invitationId, $tokenHash, $correlationId): array {
            $initial = $db->fetchAssociative(
                'SELECT commercial_account_id FROM commercial.commercial_account_invitations WHERE id = ? AND token_hash = decode(?, \'hex\')',
                [$invitationId, $tokenHash],
            );
            if ($initial === false) {
                throw new InvitationUnavailable();
            }
            $accountId = (string) $initial['commercial_account_id'];
            $this->lockAccount($db, $accountId);
            $invitation = $db->fetchAssociative(
                'SELECT id, email_normalized, status, expires_at FROM commercial.commercial_account_invitations WHERE id = ? AND commercial_account_id = ? AND token_hash = decode(?, \'hex\') FOR UPDATE',
                [$invitationId, $accountId, $tokenHash],
            );
            if ($invitation === false) {
                throw new InvitationUnavailable();
            }
            $status = $this->effectiveStatus((string) $invitation['status'], (string) $invitation['expires_at']);
            if ($status === 'declined') {
                return ['status' => 'declined'];
            }
            if ($status === 'expired') {
                throw new AuthFailure('invitation_expired', 'This invitation has expired.');
            }
            if ($status !== 'pending') {
                throw new AuthFailure('invitation_not_available', 'This invitation is no longer available.');
            }
            if (!hash_equals((string) $invitation['email_normalized'], (string) $user['email_normalized'])) {
                throw new AuthFailure('invitation_email_mismatch', 'This invitation belongs to a different email address.');
            }
            $db->executeStatement("UPDATE commercial.commercial_account_invitations SET status = 'declined', declined_at = now(), updated_at = now() WHERE id = ?", [$invitationId]);
            $this->audit($db, $userId, $accountId, $invitationId, 'commercial_invitation.declined', $correlationId, []);

            return ['status' => 'declined'];
        });
    }

    /** @return array{email_normalized: string} */
    private function verifiedUser(string $userId): array
    {
        $user = $this->connection->fetchAssociative(
            'SELECT id, email_normalized, status, email_verified_at FROM commercial.control_users WHERE id = ?',
            [$userId],
        );
        if ($user === false || !in_array($user['status'], ['active', 'pending_verification'], true) || !is_string($user['email_normalized'])) {
            throw new AuthFailure('invalid_access_token', 'Authentication is required.');
        }
        if ($user['status'] !== 'active' || $user['email_verified_at'] === null) {
            throw new AuthFailure('email_verification_required', 'Verify your email address before responding to the invitation.');
        }

        return ['email_normalized' => $user['email_normalized']];
    }

    private function lockAccount(Connection $db, string $accountId): void
    {
        if ($db->fetchOne('SELECT id FROM commercial.commercial_accounts WHERE id = ? FOR UPDATE', [$accountId]) === false) {
            throw new AccessDeniedException('Account resource is not available.');
        }
    }

    /** @return array<string, mixed>|null */
    private function findById(Connection $db, string $invitationId): ?array
    {
        $row = $db->fetchAssociative(
            <<<'SQL'
SELECT i.id, i.email, i.role,
       CASE WHEN i.status = 'pending' AND i.expires_at <= now() THEN 'expired' ELSE i.status END AS status,
       i.created_at, i.expires_at, i.accepted_at, i.declined_at, i.revoked_at, i.invited_by, u.email AS inviter_email
FROM commercial.commercial_account_invitations i
JOIN commercial.control_users u ON u.id = i.invited_by
WHERE i.id = ?
SQL,
            [$invitationId],
        );

        return $row === false ? null : $row;
    }

    private function enqueue(Connection $db, string $invitationId, string $accountId): void
    {
        $db->executeStatement(
            "INSERT INTO commercial.outbox_events (event_type, aggregate_type, aggregate_id, payload) VALUES ('commercial_account.invitation_requested', 'commercial_account_invitation', ?, jsonb_build_object('invitation_id', ?::text, 'account_id', ?::text))",
            [$invitationId, $invitationId, $accountId],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Connection $db, string $actorId, string $accountId, string $invitationId, string $action, string $correlationId, array $metadata): void
    {
        $db->executeStatement(
            "INSERT INTO commercial.audit_events (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome, metadata) VALUES ('control_user', ?, ?, ?, 'commercial_account_invitation', ?, ?, 'success', ?::jsonb)",
            [$actorId, $accountId, $action, $invitationId, $correlationId, json_encode((object) $metadata, JSON_THROW_ON_ERROR)],
        );
    }

    private function effectiveStatus(string $status, string $expiresAt): string
    {
        return $status === 'pending' && strtotime($expiresAt) <= time() ? 'expired' : $status;
    }

    private function invitationIdFromToken(string $token): string
    {
        try {
            return $this->tokens->validateInvitationToken($token);
        } catch (\DomainException|\InvalidArgumentException) {
            throw new InvitationUnavailable();
        }
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            return '***';
        }

        return $parts[0][0] . '***@' . $parts[1];
    }

    private function role(string $role): string
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Invalid invitation role.');
        }

        return $role;
    }

    private function id(string $value): string
    {
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }

        return strtolower($value);
    }
}
