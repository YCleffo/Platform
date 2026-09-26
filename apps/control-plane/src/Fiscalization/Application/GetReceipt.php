<?php

declare(strict_types=1);

namespace ControlPlane\Fiscalization\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Domain\Money;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class GetReceipt
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization)
    {
    }

    /** @return array<string, mixed> */
    public function get(string $userId, string $accountId, string $paymentId): array
    {
        if (!Uuid::isValid($userId) || !Uuid::isValid($accountId) || !Uuid::isValid($paymentId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $this->authorization->requirePermission($userId, $accountId, 'account.read');
        $receipt = $this->connection->fetchAssociative(<<<'SQL'
SELECT id, payment_id, receipt_type AS type, status, external_receipt_id, amount_minor, currency, created_at, completed_at
FROM commercial.receipts WHERE payment_id = ? AND commercial_account_id = ?
SQL, [$paymentId, $accountId]);
        if ($receipt === false) {
            throw new \DomainException('Receipt was not found.');
        }
        $items = $this->connection->fetchAllAssociative('SELECT description, quantity, unit_amount_minor, total_minor, currency, vat_code, payment_subject, payment_mode FROM commercial.receipt_items WHERE receipt_id = ? ORDER BY created_at, id', [$receipt['id']]);
        foreach ($items as &$item) {
            $item['quantity'] = (int) $item['quantity'];
            $item['unit_amount_minor'] = Money::nonNegativeBigInt($item['unit_amount_minor']);
            $item['total_minor'] = Money::nonNegativeBigInt($item['total_minor']);
            $item['vat_code'] = (int) $item['vat_code'];
        }
        unset($item);
        $receipt['amount_minor'] = Money::nonNegativeBigInt($receipt['amount_minor']);
        $receipt['items'] = $items;

        return $receipt;
    }
}
