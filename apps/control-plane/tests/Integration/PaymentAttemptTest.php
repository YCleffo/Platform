<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

final class PaymentAttemptTest extends PaymentTestCase
{
    public function testAttemptBindingFailureAndRetryLifecycle(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedInvoiceContext('payment-attempt');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'payment-attempt-create', $this->uuid());
        $provider = $this->providerAccount('attempt');
        $first = $this->attemptBeginner()->begin($payment['id'], $provider, 'card', $this->uuid());
        self::assertSame('pending', $first['status']);
        self::assertNotSame('', $first['provider_request_key']);

        try {
            $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
            self::fail('Only one pending attempt is allowed.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        $this->attemptBinder()->bind($first['attempt_id'], 'provider-payment-1');
        $this->attemptBinder()->bind($first['attempt_id'], 'provider-payment-1');
        self::assertSame('provider-payment-1', $this->connection->fetchOne('SELECT provider_payment_id FROM commercial.payment_attempts WHERE id = ?', [$first['attempt_id']]));
        try {
            $this->attemptBinder()->bind($first['attempt_id'], 'provider-payment-2');
            self::fail('Provider reference cannot be rebound.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }

        $failed = $this->attemptCompleter()->complete($first['attempt_id'], 'failed', 'declined', new \DateTimeImmutable('2026-09-25T10:00:00Z'), $this->uuid());
        self::assertSame('failed', $failed['attempt_status']);
        self::assertSame('pending', $failed['payment_status']);
        $second = $this->attemptBeginner()->begin($payment['id'], $provider, 'card', $this->uuid());
        self::assertNotSame($first['attempt_id'], $second['attempt_id']);
        $view = $this->paymentReader()->get($owner, $account, $payment['id']);
        self::assertCount(2, $view['attempts']);
        self::assertArrayNotHasKey('provider_request_key', $view['attempts'][0]);
        self::assertArrayNotHasKey('secret_ref', $view['attempts'][0]);
    }

    public function testDisabledProviderAndSuccessWithoutReferenceAreRejected(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedInvoiceContext('payment-provider-guard');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'payment-provider-create', $this->uuid());
        $disabled = $this->providerAccount('disabled', 'disabled');
        try {
            $this->attemptBeginner()->begin($payment['id'], $disabled, 'card', $this->uuid());
            self::fail('Disabled provider account must reject new attempts.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }

        $active = $this->providerAccount('active');
        $attempt = $this->attemptBeginner()->begin($payment['id'], $active, 'card', $this->uuid());
        try {
            $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('now'), $this->uuid());
            self::fail('Success without a provider reference must fail.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payment_attempts WHERE id = ?', [$attempt['attempt_id']]));
    }
}
