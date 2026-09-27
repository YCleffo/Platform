<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Domain\Money;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class GetPayment
{
    public function __construct(private Connection $connection, private AccountAuthorization $authorization)
    {
    }

    /** @return array<string, mixed> */
    public function get(string $controlUserId, string $commercialAccountId, string $paymentId): array
    {
        if (!Uuid::isValid($controlUserId) || !Uuid::isValid($commercialAccountId) || !Uuid::isValid($paymentId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $this->authorization->requirePermission($controlUserId, $commercialAccountId, 'account.read');
        $payment = $this->connection->fetchAssociative('SELECT id, invoice_id, status, currency, amount_minor, created_at, succeeded_at, cancelled_at FROM commercial.payments WHERE id = ? AND commercial_account_id = ?', [$paymentId, $commercialAccountId]);
        if ($payment === false) {
            throw new \DomainException('Payment was not found.');
        }
        $attempts = $this->connection->fetchAllAssociative('SELECT id, provider_account_id, method_type, provider_payment_id, status, failure_code, created_at, completed_at FROM commercial.payment_attempts WHERE payment_id = ? ORDER BY created_at, id', [$paymentId]);
        $refunded = Money::nonNegativeBigInt($this->connection->fetchOne("SELECT COALESCE(sum(amount_minor), 0) FROM commercial.refunds WHERE payment_id = ? AND status = 'succeeded'", [$paymentId]));
        $reserved = Money::nonNegativeBigInt($this->connection->fetchOne("SELECT COALESCE(sum(amount_minor), 0) FROM commercial.refunds WHERE payment_id = ? AND status IN ('pending','succeeded')", [$paymentId]));
        $amount = Money::nonNegativeBigInt($payment['amount_minor']);

        return [
            'id' => $payment['id'],
            'invoice_id' => $payment['invoice_id'],
            'status' => $payment['status'],
            'currency' => $payment['currency'],
            'amount_minor' => $amount,
            'refunded_minor' => $refunded,
            'refundable_minor' => max(0, $amount - $reserved),
            'created_at' => $payment['created_at'],
            'succeeded_at' => $payment['succeeded_at'],
            'cancelled_at' => $payment['cancelled_at'],
            'attempts' => array_map(static fn (array $attempt): array => [
                'id' => $attempt['id'],
                'provider_account_id' => $attempt['provider_account_id'],
                'method_type' => $attempt['method_type'],
                'provider_payment_id' => $attempt['provider_payment_id'],
                'status' => $attempt['status'],
                'failure_code' => $attempt['failure_code'],
                'created_at' => $attempt['created_at'],
                'completed_at' => $attempt['completed_at'],
            ], $attempts),
        ];
    }
}
