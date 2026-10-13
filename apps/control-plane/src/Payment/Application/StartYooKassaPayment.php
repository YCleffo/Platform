<?php

declare(strict_types=1);

namespace ControlPlane\Payment\Application;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Domain\Money;
use ControlPlane\Identity\Domain\EmailPolicy;
use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\PaymentProviderGateway;
use ControlPlane\Payment\Provider\ProviderAccount;
use ControlPlane\Payment\Provider\ProviderPayment;
use ControlPlane\Payment\Provider\ProviderPaymentRequest;
use ControlPlane\Payment\Provider\YooKassa\YooKassaProviderAccountLocator;
use ControlPlane\Payment\Provider\YooKassa\YooKassaFiscalPolicy;
use ControlPlane\Shared\Domain\IdentifierPolicy;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class StartYooKassaPayment
{
    private const IDEMPOTENCY_WINDOW_SECONDS = 86400;

    public function __construct(
        private Connection $connection,
        private AccountAuthorization $authorization,
        private YooKassaProviderAccountLocator $accounts,
        private BeginPaymentAttempt $attempts,
        private BindPaymentAttemptProviderReference $binder,
        private CompletePaymentAttempt $completer,
        private ProcessAuthoritativeProviderPayment $processor,
        private PaymentProviderGateway $gateway,
    ) {
    }

    /** @return array<string, mixed> */
    public function start(
        string $controlUserId,
        string $commercialAccountId,
        string $paymentId,
        string $provider,
        string $method,
        bool $savePaymentMethod,
        string $idempotencyKey,
        string $correlationId,
    ): array {
        if (!Uuid::isValid($controlUserId) || !Uuid::isValid($commercialAccountId) || !Uuid::isValid($paymentId) || !Uuid::isValid($correlationId)) {
            throw new \InvalidArgumentException('Invalid public identifier.');
        }
        if ($provider !== 'yookassa' || !in_array($method, ['sbp', 'bank_card'], true)) {
            throw new \InvalidArgumentException('Unsupported checkout method.');
        }
        if ($method === 'sbp' && $savePaymentMethod) throw new \InvalidArgumentException('SBP cannot be saved as a payment method.');
        $idempotencyKey = IdentifierPolicy::opaqueKey($idempotencyKey);
        [$account, $payment, $attempt] = $this->prepare($controlUserId, $commercialAccountId, $paymentId, $idempotencyKey, $correlationId, $method, $savePaymentMethod);
        $request = new ProviderPaymentRequest(
            Money::nonNegativeBigInt($payment['amount_minor']),
            (string) $payment['currency'],
            (string) $attempt['provider_request_key'],
            'Invoice ' . (string) $payment['invoice_id'],
            $paymentId,
            (string) $attempt['id'],
            (string) $payment['invoice_id'],
            $method,
            $savePaymentMethod,
        );

        if (is_string($attempt['provider_payment_id'])) {
            $providerPayment = $this->gateway->getPayment($account, $attempt['provider_payment_id']);
        } else {
            if ($attempt['status'] !== 'pending') {
                throw new \DomainException('A terminal checkout attempt has no provider reference.');
            }
            $createdAt = new \DateTimeImmutable((string) $attempt['created_at']);
            if ($createdAt->getTimestamp() < time() - self::IDEMPOTENCY_WINDOW_SECONDS) {
                throw new \DomainException('Payment attempt requires reconciliation.');
            }
            try {
                $providerPayment = $this->gateway->createPayment($account, $request);
            } catch (PaymentProviderException $exception) {
                if (!$exception->retryable) {
                    $this->completer->complete((string) $attempt['id'], 'failed', $exception->errorCode, new \DateTimeImmutable('now'), $correlationId);
                }
                throw $exception;
            }
            $this->binder->bind((string) $attempt['id'], $providerPayment->id);
        }

        $this->validateCheckoutResponse($providerPayment, $request);
        $this->processor->process($account, $providerPayment, $correlationId);

        return [
            'payment_id' => $paymentId,
            'attempt_id' => $attempt['id'],
            'provider' => 'yookassa',
            'method' => $method,
            'status' => $providerPayment->status === 'canceled' ? 'cancelled' : $providerPayment->status,
            'confirmation' => $providerPayment->confirmationUrl === null ? null : [
                'type' => 'redirect',
                'url' => $providerPayment->confirmationUrl,
            ],
        ];
    }

    /**
     * @return array{ProviderAccount, array<string, mixed>, array<string, mixed>}
     */
    private function prepare(string $userId, string $accountId, string $paymentId, string $idempotencyKey, string $correlationId, string $method, bool $savePaymentMethod): array
    {
        return $this->connection->transactional(function (Connection $connection) use ($userId, $accountId, $paymentId, $idempotencyKey, $correlationId, $method, $savePaymentMethod): array {
            $this->authorization->requirePermission($userId, $accountId, 'billing.manage');
            $payment = $connection->fetchAssociative('SELECT p.id, p.invoice_id, p.commercial_account_id, p.amount_minor, p.currency, p.status, i.bill_to_contact_email FROM commercial.payments p JOIN commercial.invoices i ON i.id = p.invoice_id WHERE p.id = ? AND p.commercial_account_id = ?', [$paymentId, $accountId]);
            if ($payment === false) {
                throw new \DomainException('Payment was not found.');
            }
            if (Money::nonNegativeBigInt($payment['amount_minor']) <= 0) {
                throw new \DomainException('YooKassa checkout requires a positive payment amount.');
            }
            if ($payment['currency'] !== 'RUB') {
                throw new \DomainException('YooKassa checkout supports RUB only.');
            }
            $providerAccount = $this->accounts->active();
            YooKassaFiscalPolicy::validate($providerAccount);
            if (!is_string($payment['bill_to_contact_email'])) {
                throw new \DomainException('YooKassa checkout requires invoice customer email.');
            }
            EmailPolicy::normalize($payment['bill_to_contact_email']);
            $scope = 'payment.checkout.' . str_replace('-', '', strtolower($paymentId)) . '.yookassa.' . $method;
            $hash = hash('sha256', json_encode(['payment_id' => strtolower($paymentId), 'provider' => 'yookassa', 'method' => $method, 'save_payment_method' => $savePaymentMethod], JSON_THROW_ON_ERROR));
            $connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$idempotencyKey]);
            if ($connection->fetchOne('SELECT scope FROM commercial.idempotency_keys WHERE idempotency_key = ? AND scope <> ? LIMIT 1', [$idempotencyKey, $scope]) !== false) {
                throw new \DomainException('Idempotency key was reused for a different request.');
            }
            $connection->executeStatement("INSERT INTO commercial.idempotency_keys (scope, idempotency_key, request_sha256, status, expires_at) VALUES (?, ?, decode(?, 'hex'), 'pending', now() + interval '24 hours') ON CONFLICT (scope, idempotency_key) DO NOTHING", [$scope, $idempotencyKey, $hash]);
            $record = $connection->fetchAssociative("SELECT encode(request_sha256, 'hex') AS request_hash, status, result_reference FROM commercial.idempotency_keys WHERE scope = ? AND idempotency_key = ? FOR UPDATE", [$scope, $idempotencyKey]);
            if ($record === false || !is_string($record['request_hash']) || !hash_equals($record['request_hash'], $hash)) {
                throw new \DomainException('Idempotency key was reused with a different request.');
            }
            $replay = $record['status'] === 'completed' && is_string($record['result_reference']);
            if (!$replay && $payment['status'] !== 'pending') {
                throw new \DomainException('YooKassa checkout requires a pending payment.');
            }
            if ($replay) {
                $attemptId = $record['result_reference'];
            } else {
                $begun = $this->attempts->beginOrReuse($paymentId, $providerAccount->id, $method, $correlationId);
                $attemptId = $begun['attempt_id'];
                $connection->executeStatement("UPDATE commercial.idempotency_keys SET status = 'completed', result_reference = ? WHERE scope = ? AND idempotency_key = ?", [$attemptId, $scope, $idempotencyKey]);
            }
            $attempt = $connection->fetchAssociative('SELECT id, payment_id, provider_account_id, method_type, provider_request_key, provider_payment_id, status, created_at FROM commercial.payment_attempts WHERE id = ?', [$attemptId]);
            if ($attempt === false || $attempt['payment_id'] !== $paymentId || $attempt['provider_account_id'] !== $providerAccount->id
                || $attempt['method_type'] !== $method || (!$replay && $attempt['status'] !== 'pending')) {
                throw new \DomainException('Idempotent checkout result is unavailable.');
            }

            return [$providerAccount, $payment, $attempt];
        });
    }

    private function validateCheckoutResponse(ProviderPayment $providerPayment, ProviderPaymentRequest $request): void
    {
        if ($providerPayment->amountMinor !== $request->amountMinor || $providerPayment->currency !== $request->currency
            || ($providerPayment->metadata['payment_id'] ?? null) !== $request->paymentId
            || ($providerPayment->metadata['attempt_id'] ?? null) !== $request->attemptId
            || ($providerPayment->metadata['invoice_id'] ?? null) !== $request->invoiceId) {
            throw new \DomainException('Provider payment does not match the checkout request.');
        }
    }
}
