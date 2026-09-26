<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Application;

use ControlPlane\Fiscalization\Application\CreatePaymentReceiptObligation;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class CompletePaymentAttempt
{
    public function __construct(private Connection $connection, private SettlePayment $settlement, private CreatePaymentReceiptObligation $receipts)
    {
    }

    /** @return array{attempt_id: string, attempt_status: string, payment_id: string, payment_status: string} */
    public function complete(string $attemptId, string $outcome, ?string $failureCode, \DateTimeImmutable $settlementInstant, string $correlationId): array
    {
        if (!Uuid::isValid($attemptId) || !Uuid::isValid($correlationId) || !in_array($outcome, ['succeeded', 'failed', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Invalid payment attempt completion.');
        }
        if ($failureCode !== null) {
            $failureCode = IdentifierPolicy::machineCode($failureCode);
        }
        if ($outcome !== 'failed' && $failureCode !== null) {
            throw new \InvalidArgumentException('Failure code is only valid for a failed attempt.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($attemptId, $outcome, $failureCode, $settlementInstant, $correlationId): array {
            $identity = $connection->fetchAssociative('SELECT pa.payment_id, p.invoice_id, i.subscription_id FROM commercial.payment_attempts pa JOIN commercial.payments p ON p.id = pa.payment_id JOIN commercial.invoices i ON i.id = p.invoice_id WHERE pa.id = ?', [$attemptId]);
            if ($identity === false || !is_string($identity['subscription_id']) || !is_string($identity['invoice_id']) || !is_string($identity['payment_id'])) {
                throw new \DomainException('Payment attempt was not found.');
            }
            $connection->executeQuery('SELECT id FROM commercial.subscriptions WHERE id = ? FOR UPDATE', [$identity['subscription_id']]);
            $invoice = $connection->fetchAssociative('SELECT id, subscription_id, commercial_account_id, status, currency, total_minor FROM commercial.invoices WHERE id = ? FOR UPDATE', [$identity['invoice_id']]);
            $payment = $connection->fetchAssociative('SELECT id, invoice_id, commercial_account_id, status, currency, amount_minor, created_at, succeeded_at FROM commercial.payments WHERE id = ? FOR UPDATE', [$identity['payment_id']]);
            $attempt = $connection->fetchAssociative('SELECT id, payment_id, provider_payment_id, status, failure_code FROM commercial.payment_attempts WHERE id = ? FOR UPDATE', [$attemptId]);
            if ($invoice === false || $payment === false || $attempt === false || !is_string($payment['id'])) {
                throw new \LogicException('Payment settlement state is unavailable.');
            }
            if ($attempt['status'] !== 'pending') {
                if ($attempt['status'] === $outcome && ($outcome !== 'succeeded' || ($payment['status'] === 'succeeded' && $invoice['status'] === 'paid'))) {
                    return ['attempt_id' => $attemptId, 'attempt_status' => $outcome, 'payment_id' => $payment['id'], 'payment_status' => (string) $payment['status']];
                }
                throw new \DomainException('A terminal payment attempt cannot be rewritten.');
            }
            $completedAt = $settlementInstant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
            if ($outcome !== 'succeeded') {
                $connection->executeStatement('UPDATE commercial.payment_attempts SET status = ?, failure_code = ?, completed_at = ?::timestamptz WHERE id = ?', [$outcome, $failureCode, $completedAt, $attemptId]);
                if ($outcome === 'failed') {
                    $connection->executeStatement("INSERT INTO commercial.audit_events (actor_type, commercial_account_id, action, resource_type, resource_id, correlation_id, outcome, metadata) VALUES ('system', ?, 'payment.attempt.failed', 'payment_attempt', ?, ?, 'failure', jsonb_build_object('failure_code', ?::text))", [$payment['commercial_account_id'], $attemptId, $correlationId, $failureCode]);
                }

                return ['attempt_id' => $attemptId, 'attempt_status' => $outcome, 'payment_id' => $payment['id'], 'payment_status' => (string) $payment['status']];
            }
            if (!is_string($attempt['provider_payment_id'])) {
                throw new \DomainException('Successful provider settlement requires a provider payment reference.');
            }
            $connection->executeStatement("UPDATE commercial.payment_attempts SET status = 'succeeded', completed_at = ?::timestamptz WHERE id = ?", [$completedAt, $attemptId]);
            $this->settlement->succeed($connection, $payment, $invoice, $settlementInstant, $correlationId);
            $this->receipts->createInTransaction($connection, $attemptId);

            return ['attempt_id' => $attemptId, 'attempt_status' => 'succeeded', 'payment_id' => $payment['id'], 'payment_status' => 'succeeded'];
        });
    }
}
