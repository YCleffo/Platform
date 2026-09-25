<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Application;

use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class BeginPaymentAttempt
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array{attempt_id: string, payment_id: string, provider_account_id: string, provider_request_key: string, status: 'pending'} */
    public function begin(string $paymentId, string $providerAccountId, string $methodType, string $correlationId): array
    {
        return $this->beginInternal($paymentId, $providerAccountId, $methodType, $correlationId, false);
    }

    /** @return array{attempt_id: string, payment_id: string, provider_account_id: string, provider_request_key: string, status: 'pending'} */
    public function beginOrReuse(string $paymentId, string $providerAccountId, string $methodType, string $correlationId): array
    {
        return $this->beginInternal($paymentId, $providerAccountId, $methodType, $correlationId, true);
    }

    /** @return array{attempt_id: string, payment_id: string, provider_account_id: string, provider_request_key: string, status: 'pending'} */
    private function beginInternal(string $paymentId, string $providerAccountId, string $methodType, string $correlationId, bool $reuse): array
    {
        if (!Uuid::isValid($paymentId) || !Uuid::isValid($providerAccountId) || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $methodType = IdentifierPolicy::machineCode($methodType);

        return $this->connection->transactional(function (Connection $connection) use ($paymentId, $providerAccountId, $methodType, $reuse): array {
            $identity = $connection->fetchAssociative('SELECT i.subscription_id, p.invoice_id FROM commercial.payments p JOIN commercial.invoices i ON i.id = p.invoice_id WHERE p.id = ?', [$paymentId]);
            if ($identity === false || !is_string($identity['subscription_id']) || !is_string($identity['invoice_id'])) {
                throw new \DomainException('Payment was not found.');
            }
            $connection->executeQuery('SELECT id FROM commercial.subscriptions WHERE id = ? FOR UPDATE', [$identity['subscription_id']]);
            $connection->executeQuery('SELECT id FROM commercial.invoices WHERE id = ? FOR UPDATE', [$identity['invoice_id']]);
            $paymentStatus = $connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ? FOR UPDATE', [$paymentId]);
            if ($paymentStatus !== 'pending') {
                throw new \DomainException('A payment attempt requires a pending payment.');
            }
            $providerStatus = $connection->fetchOne('SELECT status FROM commercial.payment_provider_accounts WHERE id = ? FOR UPDATE', [$providerAccountId]);
            if ($providerStatus !== 'active') {
                throw new \DomainException('An active provider account is required.');
            }
            $existing = $connection->fetchAssociative("SELECT id, payment_id, provider_account_id, method_type, provider_request_key, status FROM commercial.payment_attempts WHERE payment_id = ? AND status = 'pending'", [$paymentId]);
            if ($existing !== false) {
                if ($reuse && $existing['provider_account_id'] === $providerAccountId && $existing['method_type'] === $methodType
                    && is_string($existing['id']) && is_string($existing['payment_id']) && is_string($existing['provider_request_key'])) {
                    return [
                        'attempt_id' => $existing['id'],
                        'payment_id' => $existing['payment_id'],
                        'provider_account_id' => $existing['provider_account_id'],
                        'provider_request_key' => $existing['provider_request_key'],
                        'status' => 'pending',
                    ];
                }
                throw new \DomainException('A payment attempt is already in progress.');
            }
            $attemptId = Uuid::v7()->toRfc4122();
            $providerRequestKey = Uuid::v7()->toRfc4122();
            $connection->executeStatement("INSERT INTO commercial.payment_attempts (id, payment_id, provider_account_id, method_type, provider_request_key, status) VALUES (?, ?, ?, ?, ?, 'pending')", [$attemptId, $paymentId, $providerAccountId, $methodType, $providerRequestKey]);

            return ['attempt_id' => $attemptId, 'payment_id' => $paymentId, 'provider_account_id' => $providerAccountId, 'provider_request_key' => $providerRequestKey, 'status' => 'pending'];
        });
    }
}
