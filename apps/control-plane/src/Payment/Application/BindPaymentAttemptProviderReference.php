<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Application;

use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class BindPaymentAttemptProviderReference
{
    public function __construct(private Connection $connection)
    {
    }

    public function bind(string $attemptId, string $providerPaymentId): string
    {
        if (!Uuid::isValid($attemptId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        $providerPaymentId = IdentifierPolicy::opaqueKey($providerPaymentId);

        return $this->connection->transactional(function (Connection $connection) use ($attemptId, $providerPaymentId): string {
            $identity = $connection->fetchAssociative('SELECT pa.payment_id, p.invoice_id, i.subscription_id FROM commercial.payment_attempts pa JOIN commercial.payments p ON p.id = pa.payment_id JOIN commercial.invoices i ON i.id = p.invoice_id WHERE pa.id = ?', [$attemptId]);
            if ($identity === false || !is_string($identity['subscription_id']) || !is_string($identity['invoice_id']) || !is_string($identity['payment_id'])) {
                throw new \DomainException('Payment attempt was not found.');
            }
            $connection->executeQuery('SELECT id FROM commercial.subscriptions WHERE id = ? FOR UPDATE', [$identity['subscription_id']]);
            $connection->executeQuery('SELECT id FROM commercial.invoices WHERE id = ? FOR UPDATE', [$identity['invoice_id']]);
            $connection->executeQuery('SELECT id FROM commercial.payments WHERE id = ? FOR UPDATE', [$identity['payment_id']]);
            $attempt = $connection->fetchAssociative('SELECT provider_payment_id, status FROM commercial.payment_attempts WHERE id = ? FOR UPDATE', [$attemptId]);
            if ($attempt === false) {
                throw new \DomainException('Payment attempt was not found.');
            }
            if ($attempt['provider_payment_id'] === $providerPaymentId) {
                return $providerPaymentId;
            }
            if ($attempt['status'] !== 'pending') {
                throw new \DomainException('Only a pending attempt can bind a provider reference.');
            }
            if ($attempt['provider_payment_id'] !== null) {
                throw new \DomainException('Provider payment reference cannot be rebound.');
            }
            $connection->executeStatement('UPDATE commercial.payment_attempts SET provider_payment_id = ? WHERE id = ?', [$providerPaymentId, $attemptId]);

            return $providerPaymentId;
        });
    }
}
