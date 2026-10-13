<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\ProviderPayment;
use ControlPlane\Tests\Support\FakePaymentProviderGateway;

final class YooKassaCheckoutTest extends PaymentTestCase
{
    public function testPendingRubPaymentStartsSbpRedirectAndBindsProviderReference(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedRubInvoiceContext('yk-checkout');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'yk-create', $this->uuid());
        $this->yooKassaProviderAccount();
        $gateway = new FakePaymentProviderGateway();

        $result = $this->yooKassaCheckout($gateway)->start($owner, $account, $payment['id'], 'yookassa', 'sbp', false, 'yk-checkout-key', $this->uuid());

        self::assertSame('pending', $result['status']);
        self::assertSame('yookassa', $result['provider']);
        self::assertSame('sbp', $result['method']);
        self::assertSame('redirect', $result['confirmation']['type']);
        self::assertStringStartsWith('https://', $result['confirmation']['url']);
        self::assertCount(1, $gateway->createCalls);
        $attempt = $this->connection->fetchAssociative('SELECT id, method_type, provider_request_key, provider_payment_id, status FROM commercial.payment_attempts WHERE payment_id = ?', [$payment['id']]);
        self::assertIsArray($attempt);
        self::assertSame($result['attempt_id'], $attempt['id']);
        self::assertSame('sbp', $attempt['method_type']);
        self::assertSame($gateway->createCalls[0][1]->providerRequestKey, $attempt['provider_request_key']);
        self::assertNotNull($attempt['provider_payment_id']);
    }

    public function testSameAndDifferentLocalKeysReuseOneAttemptAndOneProviderPayment(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedRubInvoiceContext('yk-idempotent');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'yk-idempotent-create', $this->uuid());
        $this->yooKassaProviderAccount();
        $gateway = new FakePaymentProviderGateway();
        $checkout = $this->yooKassaCheckout($gateway);

        $first = $checkout->start($owner, $account, $payment['id'], 'yookassa', 'sbp', false, 'same-local-key', $this->uuid());
        $second = $checkout->start($owner, $account, $payment['id'], 'yookassa', 'sbp', false, 'same-local-key', $this->uuid());
        $natural = $checkout->start($owner, $account, $payment['id'], 'yookassa', 'sbp', false, 'different-local-key', $this->uuid());

        self::assertSame($first['attempt_id'], $second['attempt_id']);
        self::assertSame($first['attempt_id'], $natural['attempt_id']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payment_attempts WHERE payment_id = ?', [$payment['id']]));
        self::assertCount(1, $gateway->createCalls);
        self::assertCount(2, $gateway->getCalls);
    }

    public function testSameCheckoutKeyReplaysAfterWebhookSettlementWithoutAnotherCharge(): void
    {
        [$owner, $account, , , , $subscription, $invoice] = $this->issuedRubInvoiceContext('yk-settled-replay');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'settled-replay-create', $this->uuid());
        $this->yooKassaProviderAccount();
        $gateway = new FakePaymentProviderGateway();
        $checkout = $this->yooKassaCheckout($gateway);
        $first = $checkout->start($owner, $account, $payment['id'], 'yookassa', 'sbp', false, 'settled-replay-key', $this->uuid());
        $request = $gateway->createCalls[0][1];
        $providerId = 'yk-' . str_replace('-', '', $first['attempt_id']);
        $gateway->payments[$providerId] = new ProviderPayment(
            $providerId, 'succeeded', true, $request->amountMinor, $request->currency,
            ['payment_id' => $payment['id'], 'attempt_id' => $first['attempt_id'], 'invoice_id' => $invoice['id']],
            null, new \DateTimeImmutable('2026-09-26T12:00:00Z'),
        );
        $this->yooKassaWebhook($gateway)->receive(
            json_encode(['type' => 'notification', 'event' => 'payment.succeeded', 'object' => ['id' => $providerId]], JSON_THROW_ON_ERROR),
            'application/json', $this->uuid(),
        );

        $replay = $checkout->start($owner, $account, $payment['id'], 'yookassa', 'sbp', false, 'settled-replay-key', $this->uuid());
        self::assertSame($first['attempt_id'], $replay['attempt_id']);
        self::assertSame('succeeded', $replay['status']);
        self::assertCount(1, $gateway->createCalls);
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoice['id']]));
        self::assertSame('active', $this->connection->fetchOne('SELECT status FROM commercial.subscriptions WHERE id = ?', [$subscription]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.audit_events WHERE action = 'payment.succeeded' AND resource_id = ?", [$payment['id']]));

        try {
            $checkout->start($owner, $account, $payment['id'], 'yookassa', 'sbp', false, 'new-key-after-settlement', $this->uuid());
            self::fail('A new checkout after settlement was accepted.');
        } catch (\DomainException) {
            self::assertCount(1, $gateway->createCalls);
        }
    }

    public function testUncertainCreateRemainsPendingAndRetryUsesSameRequest(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedRubInvoiceContext('yk-uncertain');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'yk-uncertain-create', $this->uuid());
        $this->yooKassaProviderAccount();
        $gateway = new FakePaymentProviderGateway();
        $gateway->createFailure = PaymentProviderException::uncertain();
        $checkout = $this->yooKassaCheckout($gateway);
        try {
            $checkout->start($owner, $account, $payment['id'], 'yookassa', 'sbp', false, 'uncertain-key', $this->uuid());
            self::fail('An uncertain provider result must be surfaced.');
        } catch (PaymentProviderException $exception) {
            self::assertTrue($exception->retryable);
        }
        $attempt = $this->connection->fetchAssociative('SELECT id, provider_request_key, provider_payment_id, status FROM commercial.payment_attempts WHERE payment_id = ?', [$payment['id']]);
        self::assertIsArray($attempt);
        self::assertSame('pending', $attempt['status']);
        self::assertNull($attempt['provider_payment_id']);

        $retry = $checkout->start($owner, $account, $payment['id'], 'yookassa', 'sbp', false, 'uncertain-key', $this->uuid());
        self::assertSame($attempt['id'], $retry['attempt_id']);
        self::assertCount(2, $gateway->createCalls);
        self::assertSame($gateway->createCalls[0][1]->providerRequestKey, $gateway->createCalls[1][1]->providerRequestKey);
        self::assertEquals($gateway->createCalls[0][1], $gateway->createCalls[1][1]);
    }

    public function testUnsupportedMoneyAndAmbiguousConfigurationFailBeforeProviderRequest(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedInvoiceContext('yk-usd');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'yk-usd-create', $this->uuid());
        $this->yooKassaProviderAccount('one');
        $gateway = new FakePaymentProviderGateway();
        try {
            $this->yooKassaCheckout($gateway)->start($owner, $account, $payment['id'], 'yookassa', 'sbp', false, 'usd-key', $this->uuid());
            self::fail('USD checkout must be rejected.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertCount(0, $gateway->createCalls);

        [$rubOwner, $rubAccount, , , , , $rubInvoice] = $this->issuedRubInvoiceContext('yk-ambiguous');
        $rubPayment = $this->paymentCreator()->create($rubOwner, $rubAccount, $rubInvoice['id'], 'yk-ambiguous-create', $this->uuid());
        $this->yooKassaProviderAccount('two');
        try {
            $this->yooKassaCheckout($gateway)->start($rubOwner, $rubAccount, $rubPayment['id'], 'yookassa', 'sbp', false, 'ambiguous-key', $this->uuid());
            self::fail('Ambiguous configuration must fail closed.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertCount(0, $gateway->createCalls);
    }

    public function testDefinitiveProviderRejectionFailsAttemptButAllowsFreshRetry(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedRubInvoiceContext('yk-rejected');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'yk-rejected-create', $this->uuid());
        $this->yooKassaProviderAccount();
        $gateway = new FakePaymentProviderGateway();
        $gateway->createFailure = PaymentProviderException::definitive('provider_authentication_failed');
        $checkout = $this->yooKassaCheckout($gateway);

        try {
            $checkout->start($owner, $account, $payment['id'], 'yookassa', 'sbp', false, 'rejected-key', $this->uuid());
            self::fail('Definitive provider rejection was expected.');
        } catch (PaymentProviderException $exception) {
            self::assertFalse($exception->retryable);
        }
        self::assertSame('failed', $this->connection->fetchOne('SELECT status FROM commercial.payment_attempts WHERE payment_id = ?', [$payment['id']]));
        self::assertSame('provider_authentication_failed', $this->connection->fetchOne('SELECT failure_code FROM commercial.payment_attempts WHERE payment_id = ?', [$payment['id']]));
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));

        $retry = $checkout->start($owner, $account, $payment['id'], 'yookassa', 'sbp', false, 'fresh-key', $this->uuid());
        self::assertSame('pending', $retry['status']);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.payment_attempts WHERE payment_id = ?', [$payment['id']]));
    }

    public function testUncertainAttemptOutsideProviderWindowRequiresReconciliation(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedRubInvoiceContext('yk-window');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'yk-window-create', $this->uuid());
        $provider = $this->yooKassaProviderAccount();
        $attempt = $this->connection->fetchOne("INSERT INTO commercial.payment_attempts (payment_id, provider_account_id, method_type, provider_request_key, status, created_at) VALUES (?, ?, 'sbp', ?, 'pending', now() - interval '25 hours') RETURNING id", [$payment['id'], $provider, 'old-provider-request']);
        self::assertIsString($attempt);
        $gateway = new FakePaymentProviderGateway();

        try {
            $this->yooKassaCheckout($gateway)->start($owner, $account, $payment['id'], 'yookassa', 'sbp', false, 'old-window-key', $this->uuid());
            self::fail('An old uncertain attempt must require reconciliation.');
        } catch (\DomainException $exception) {
            self::assertSame('Payment attempt requires reconciliation.', $exception->getMessage());
        }
        self::assertCount(0, $gateway->createCalls);
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payment_attempts WHERE id = ?', [$attempt]));
    }
}
