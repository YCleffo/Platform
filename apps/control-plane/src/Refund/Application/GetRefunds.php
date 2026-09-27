<?php

declare(strict_types=1);

namespace ControlPlane\Refund\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Domain\Money;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class GetRefunds
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forPayment(string $userId, string $accountId, string $paymentId): array
    {
        $this->validate($userId, $accountId, $paymentId);
        $this->authorization->requirePermission($userId, $accountId, 'account.read');
        if ($this->connection->fetchOne('SELECT id FROM commercial.payments WHERE id = ? AND commercial_account_id = ?', [$paymentId, $accountId]) === false) {
            throw new \DomainException('Payment was not found.');
        }

        return array_map([self::class, 'normalize'], $this->connection->fetchAllAssociative('SELECT * FROM commercial.refunds WHERE payment_id = ? AND commercial_account_id = ? ORDER BY created_at, id', [$paymentId, $accountId]));
    }

    /** @return array<string, mixed> */
    public function one(string $userId, string $accountId, string $refundId): array
    {
        $this->validate($userId, $accountId, $refundId);
        $this->authorization->requirePermission($userId, $accountId, 'account.read');
        $refund = $this->connection->fetchAssociative('SELECT * FROM commercial.refunds WHERE id = ? AND commercial_account_id = ?', [$refundId, $accountId]);
        if ($refund === false) {
            throw new \DomainException('Refund was not found.');
        }
        $result = self::normalize($refund);
        $result['items'] = array_map(static fn (array $item): array => [
            'invoice_line_id' => $item['invoice_line_id'], 'description' => $item['description'], 'quantity' => (int) $item['quantity'],
            'unit_amount_minor' => Money::nonNegativeBigInt($item['unit_amount_minor']), 'total_minor' => Money::nonNegativeBigInt($item['total_minor']),
            'currency' => $item['currency'], 'vat_code' => (int) $item['vat_code'], 'payment_subject' => $item['payment_subject'], 'payment_mode' => $item['payment_mode'],
        ], $this->connection->fetchAllAssociative('SELECT * FROM commercial.refund_items WHERE refund_id = ? ORDER BY created_at, id', [$refundId]));
        $receipt = $this->connection->fetchAssociative("SELECT id, status, external_receipt_id, created_at, completed_at FROM commercial.receipts WHERE refund_id = ? AND receipt_type = 'refund'", [$refundId]);
        $result['receipt'] = $receipt === false ? null : $receipt;

        return $result;
    }

    private function validate(string ...$ids): void
    {
        foreach ($ids as $id) {
            if (!Uuid::isValid($id)) {
                throw new \InvalidArgumentException('Invalid public identifier.');
            }
        }
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        return ['id' => $row['id'], 'payment_id' => $row['payment_id'], 'amount_minor' => Money::nonNegativeBigInt($row['amount_minor']), 'currency' => $row['currency'], 'status' => $row['status'], 'provider_refund_id' => $row['provider_refund_id'], 'created_at' => $row['created_at'], 'last_attempted_at' => $row['last_attempted_at'], 'completed_at' => $row['completed_at']];
    }
}
