<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Application;

use ControlPlane\Billing\Domain\Money;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderPayment;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class ProcessAuthoritativeProviderPayment
{
    public function __construct(
        private Connection $connection,
        private BindPaymentAttemptProviderReference $binder,
        private CompletePaymentAttempt $completer,
    ) {
    }

    /** @return array{attempt_id: string, payment_id: string, provider_status: string, changed: bool} */
    public function process(ProviderAccount $account, ProviderPayment $providerPayment, string $correlationId): array
    {
        if (!Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid correlation identifier.');
        }
        foreach (['attempt_id', 'payment_id', 'invoice_id'] as $key) {
            if (!isset($providerPayment->metadata[$key]) || !Uuid::isValid($providerPayment->metadata[$key])) {
                throw new \DomainException('Provider payment metadata does not match local payment state.');
            }
        }
        $attemptId = $providerPayment->metadata['attempt_id'];
        $paymentId = $providerPayment->metadata['payment_id'];
        $invoiceId = $providerPayment->metadata['invoice_id'];
        $local = $this->connection->fetchAssociative(<<<'SQL'
SELECT pa.payment_id, pa.provider_account_id, pa.provider_payment_id, pa.status AS attempt_status,
       p.invoice_id, p.amount_minor, p.currency, p.status AS payment_status
FROM commercial.payment_attempts pa
JOIN commercial.payments p ON p.id = pa.payment_id
WHERE pa.id = ?
SQL, [$attemptId]);
        if ($local === false || $local['payment_id'] !== $paymentId || $local['invoice_id'] !== $invoiceId
            || $local['provider_account_id'] !== $account->id) {
            throw new \DomainException('Provider payment metadata does not match local payment state.');
        }
        if (Money::nonNegativeBigInt($local['amount_minor']) !== $providerPayment->amountMinor || $local['currency'] !== $providerPayment->currency) {
            throw new \DomainException('Provider payment money does not match local payment state.');
        }
        if (is_string($local['provider_payment_id']) && $local['provider_payment_id'] !== $providerPayment->id) {
            throw new \DomainException('Provider payment reference does not match local payment state.');
        }
        if ($providerPayment->status === 'pending' || $providerPayment->status === 'waiting_for_capture') {
            return ['attempt_id' => $attemptId, 'payment_id' => $paymentId, 'provider_status' => $providerPayment->status, 'changed' => false];
        }

        $this->binder->bind($attemptId, $providerPayment->id);
        if ($providerPayment->status === 'succeeded') {
            if (!$providerPayment->paid || $providerPayment->capturedAt === null) {
                throw new \DomainException('Provider payment is not authoritatively settled.');
            }
            $this->completer->complete($attemptId, 'succeeded', null, $providerPayment->capturedAt, $correlationId);

            return ['attempt_id' => $attemptId, 'payment_id' => $paymentId, 'provider_status' => 'succeeded', 'changed' => $local['attempt_status'] === 'pending'];
        }
        if ($providerPayment->status === 'canceled') {
            $this->completer->complete($attemptId, 'cancelled', null, new \DateTimeImmutable('now'), $correlationId);

            return ['attempt_id' => $attemptId, 'payment_id' => $paymentId, 'provider_status' => 'canceled', 'changed' => $local['attempt_status'] === 'pending'];
        }

        throw new \DomainException('Unsupported authoritative provider status.');
    }
}
