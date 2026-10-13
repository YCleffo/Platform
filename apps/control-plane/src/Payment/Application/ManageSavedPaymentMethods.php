<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class ManageSavedPaymentMethods
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(string $userId, string $accountId): array
    {
        $this->assertIds($userId, $accountId);
        $this->authorization->requirePermission($userId, $accountId, 'account.read');

        return array_map([$this, 'publicMethod'], $this->connection->fetchAllAssociative(
            "SELECT id, method_type, status, display_title, card_brand, last4, expiry_month, expiry_year, is_default, created_at, last_used_at, disabled_at
             FROM commercial.saved_payment_methods WHERE commercial_account_id = ? ORDER BY is_default DESC, created_at DESC",
            [$accountId],
        ));
    }

    /** @return array<string, mixed> */
    public function setDefault(string $userId, string $accountId, string $methodId, string $correlationId): array
    {
        $this->assertIds($userId, $accountId, $methodId, $correlationId);

        return $this->connection->transactional(function (Connection $db) use ($userId, $accountId, $methodId, $correlationId): array {
            $this->authorization->requirePermission($userId, $accountId, 'billing.manage');
            $db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$accountId]);
            $method = $db->fetchAssociative("SELECT id FROM commercial.saved_payment_methods WHERE id = ? AND commercial_account_id = ? AND status = 'active' FOR UPDATE", [$methodId, $accountId]);
            if ($method === false) throw new \DomainException('Saved payment method was not found.');
            $db->executeStatement("UPDATE commercial.saved_payment_methods SET is_default = false, updated_at = now() WHERE commercial_account_id = ? AND status = 'active' AND is_default", [$accountId]);
            $db->executeStatement('UPDATE commercial.saved_payment_methods SET is_default = true, updated_at = now() WHERE id = ?', [$methodId]);
            $this->audit($db, $userId, 'payment_method.default_changed', $accountId, $methodId, $correlationId);

            return $this->one($db, $accountId, $methodId);
        });
    }

    public function disable(string $userId, string $accountId, string $methodId, string $correlationId): void
    {
        $this->assertIds($userId, $accountId, $methodId, $correlationId);
        $this->connection->transactional(function (Connection $db) use ($userId, $accountId, $methodId, $correlationId): void {
            $this->authorization->requirePermission($userId, $accountId, 'billing.manage');
            $method = $db->fetchAssociative("SELECT id FROM commercial.saved_payment_methods WHERE id = ? AND commercial_account_id = ? AND status = 'active' FOR UPDATE", [$methodId, $accountId]);
            if ($method === false) throw new \DomainException('Saved payment method was not found.');
            $db->executeStatement("UPDATE commercial.saved_payment_methods SET status = 'disabled', is_default = false, disabled_at = now(), updated_at = now() WHERE id = ?", [$methodId]);
            $this->audit($db, $userId, 'payment_method.disabled', $accountId, $methodId, $correlationId);
        });
    }

    /** @return array<string, mixed> */
    private function one(Connection $db, string $accountId, string $methodId): array
    {
        $method = $db->fetchAssociative("SELECT id, method_type, status, display_title, card_brand, last4, expiry_month, expiry_year, is_default, created_at, last_used_at, disabled_at FROM commercial.saved_payment_methods WHERE id = ? AND commercial_account_id = ?", [$methodId, $accountId]);
        if ($method === false) throw new \LogicException('Saved payment method disappeared.');

        return $this->publicMethod($method);
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, mixed>
     */
    private function publicMethod(array $method): array
    {
        return [
            'id' => $method['id'], 'method_type' => $method['method_type'], 'status' => $method['status'],
            'title' => $method['display_title'], 'card_brand' => $method['card_brand'], 'last4' => $method['last4'],
            'expiry_month' => $method['expiry_month'], 'expiry_year' => $method['expiry_year'],
            'is_default' => (bool) $method['is_default'], 'created_at' => $method['created_at'],
            'last_used_at' => $method['last_used_at'], 'disabled_at' => $method['disabled_at'],
        ];
    }

    private function audit(Connection $db, string $userId, string $action, string $accountId, string $methodId, string $correlationId): void
    {
        $db->executeStatement("INSERT INTO commercial.audit_events (actor_type, actor_id, action, commercial_account_id, resource_type, resource_id, correlation_id, outcome) VALUES ('control_user', ?, ?, ?, 'saved_payment_method', ?, ?, 'success')", [$userId, $action, $accountId, $methodId, $correlationId]);
    }

    private function assertIds(string ...$ids): void
    {
        foreach ($ids as $id) if (!Uuid::isValid($id)) throw new \InvalidArgumentException('Invalid public identifier.');
    }
}
