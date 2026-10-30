<?php

declare(strict_types=1);

namespace ControlPlane\Account\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use Doctrine\DBAL\Connection;

final readonly class MembershipService
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization)
    {
    }

    public function add(string $actorId, string $accountId, string $targetUserId, string $role, string $correlationId): string
    {
        AccountAuthorization::assertRole($role);

        return $this->connection->transactional(function (Connection $connection) use ($actorId, $accountId, $targetUserId, $role, $correlationId): string {
            $this->lockAccount($connection, $accountId);
            $existing = $connection->fetchAssociative(
                'SELECT id, role, revoked_at FROM commercial.commercial_account_memberships WHERE control_user_id = ? AND commercial_account_id = ? FOR UPDATE',
                [$targetUserId, $accountId],
            );
            $existingRole = $existing === false ? null : (string) $existing['role'];

            // Authorization is evaluated inside the account lock and considers the
            // target's current role, so add() can never bypass ownership.manage.
            $this->requirePolicyRole($actorId, $accountId, $existingRole, $role);

            if ($connection->fetchOne("SELECT 1 FROM commercial.control_users WHERE id = ? AND status = 'active'", [$targetUserId]) === false) {
                throw new \DomainException('Membership target must be an active Control User.');
            }

            if ($existing !== false && $existing['revoked_at'] === null) {
                if ($existingRole === $role) {
                    // Idempotent: an active membership with the same role is unchanged.
                    return (string) $existing['id'];
                }
                // Role changes must use the dedicated endpoint and its policy.
                throw new \DomainException('An active membership already exists; use the role change endpoint.');
            }

            if ($existing !== false) {
                $membershipId = (string) $existing['id'];
                $connection->executeStatement(
                    'UPDATE commercial.commercial_account_memberships SET role = ?, revoked_at = NULL WHERE id = ?',
                    [$role, $membershipId],
                );
                $this->audit($connection, $actorId, $accountId, $membershipId, 'commercial_membership.reactivated', $correlationId, ['role' => $role]);

                return $membershipId;
            }

            $membershipId = $connection->fetchOne(<<<'SQL'
INSERT INTO commercial.commercial_account_memberships (control_user_id, commercial_account_id, role)
VALUES (?, ?, ?)
RETURNING id
SQL, [$targetUserId, $accountId, $role]);
            if (!is_string($membershipId)) {
                throw new \LogicException('Membership ID unavailable.');
            }
            $this->audit($connection, $actorId, $accountId, $membershipId, 'commercial_membership.saved', $correlationId, ['role' => $role]);

            return $membershipId;
        });
    }

    public function changeRole(string $actorId, string $accountId, string $membershipId, string $newRole, string $correlationId): void
    {
        AccountAuthorization::assertRole($newRole);
        $this->connection->transactional(function (Connection $connection) use ($actorId, $accountId, $membershipId, $newRole, $correlationId): void {
            $this->lockAccount($connection, $accountId);
            $membership = $connection->fetchAssociative('SELECT role, revoked_at FROM commercial.commercial_account_memberships WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$membershipId, $accountId]);
            if ($membership === false || $membership['revoked_at'] !== null || !is_string($membership['role'])) {
                throw new \DomainException('Membership was not found.');
            }
            $this->requireManageRole($actorId, $accountId, $membership['role'] === 'owner' || $newRole === 'owner' ? 'owner' : $newRole);
            if ($membership['role'] === 'owner' && $newRole !== 'owner') {
                $this->assertAnotherOwner($connection, $accountId, $membershipId);
            }
            $connection->executeStatement('UPDATE commercial.commercial_account_memberships SET role = ? WHERE id = ?', [$newRole, $membershipId]);
            $this->audit($connection, $actorId, $accountId, $membershipId, 'commercial_membership.role_changed', $correlationId, ['role' => $newRole]);
        });
    }

    public function remove(string $actorId, string $accountId, string $membershipId, string $correlationId): void
    {
        $this->connection->transactional(function (Connection $connection) use ($actorId, $accountId, $membershipId, $correlationId): void {
            $this->lockAccount($connection, $accountId);
            $membership = $connection->fetchAssociative('SELECT role, revoked_at FROM commercial.commercial_account_memberships WHERE id = ? AND commercial_account_id = ? FOR UPDATE', [$membershipId, $accountId]);
            if ($membership === false || $membership['revoked_at'] !== null || !is_string($membership['role'])) {
                return;
            }
            $this->requireManageRole($actorId, $accountId, $membership['role']);
            if ($membership['role'] === 'owner') {
                $this->assertAnotherOwner($connection, $accountId, $membershipId);
            }
            $connection->executeStatement('UPDATE commercial.commercial_account_memberships SET revoked_at = now() WHERE id = ?', [$membershipId]);
            $this->audit($connection, $actorId, $accountId, $membershipId, 'commercial_membership.removed', $correlationId, []);
        });
    }

    private function requireManageRole(string $actorId, string $accountId, string $role): void
    {
        $this->authorization->requirePermission($actorId, $accountId, $role === 'owner' ? 'ownership.manage' : 'membership.manage');
    }

    private function requirePolicyRole(string $actorId, string $accountId, ?string $existingRole, string $targetRole): void
    {
        $requiresOwnership = $existingRole === 'owner' || $targetRole === 'owner';
        $this->authorization->requirePermission($actorId, $accountId, $requiresOwnership ? 'ownership.manage' : 'membership.manage');
    }

    private function lockAccount(Connection $connection, string $accountId): void
    {
        if ($connection->fetchOne('SELECT id FROM commercial.commercial_accounts WHERE id = ? FOR UPDATE', [$accountId]) === false) {
            throw new \DomainException('Account was not found.');
        }
    }

    private function assertAnotherOwner(Connection $connection, string $accountId, string $membershipId): void
    {
        $owners = (int) $connection->fetchOne("SELECT count(*) FROM commercial.commercial_account_memberships WHERE commercial_account_id = ? AND role = 'owner' AND revoked_at IS NULL AND id <> ?", [$accountId, $membershipId]);
        if ($owners < 1) {
            throw new \DomainException('The last owner cannot be removed or downgraded.');
        }
    }

    /** @param array<string, string> $metadata */
    private function audit(Connection $connection, string $actorId, string $accountId, string $membershipId, string $action, string $correlationId, array $metadata): void
    {
        $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome, metadata) VALUES ('control_user', ?, ?, ?, 'commercial_account_membership', ?, ?, 'success', ?::jsonb)", [$actorId, $accountId, $action, $membershipId, $correlationId, json_encode($metadata, JSON_THROW_ON_ERROR)]);
    }
}
