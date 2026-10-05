<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Tests\Support\FakePaymentProviderGateway;

final class FiscalReceiptPreflightTest extends PaymentTestCase
{
    public function testCheckoutRejectsMissingInvoiceEmailBeforeProviderRequest(): void
    {
        [$owner, $account, , , , $subscription] = $this->invoiceContext('receipt-no-email', 1, 'month', 'RUB');
        $invoice = $this->issuer()->issue($owner, $account, $subscription, 'receipt-no-email-invoice', $this->uuid());
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'receipt-no-email-payment', $this->uuid());
        $this->yooKassaProviderAccount();
        $gateway = new FakePaymentProviderGateway();

        $this->expectException(\DomainException::class);
        try {
            $this->yooKassaCheckout($gateway)->start($owner, $account, $payment['id'], 'yookassa', 'sbp', false, 'missing-email', $this->uuid());
        } finally {
            self::assertCount(0, $gateway->createCalls);
        }
    }

    public function testCheckoutRejectsDisabledFiscalConfigurationBeforeProviderRequest(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedRubInvoiceContext('receipt-disabled');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'disabled-payment', $this->uuid());
        $this->connection->executeStatement("INSERT INTO commercial.payment_provider_accounts (provider_code, external_account_id, secret_ref, status) VALUES ('yookassa', 'disabled-fiscal-shop', 'env:YOOKASSA_SECRET_KEY', 'active')");
        $gateway = new FakePaymentProviderGateway();

        $this->expectException(\DomainException::class);
        try {
            $this->yooKassaCheckout($gateway)->start($owner, $account, $payment['id'], 'yookassa', 'sbp', false, 'disabled-fiscal', $this->uuid());
        } finally {
            self::assertCount(0, $gateway->createCalls);
        }
    }

    public function testSettlementWithoutRequiredSnapshotRollsBackAtomically(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedInvoiceContext('receipt-atomic');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'atomic-payment', $this->uuid());
        $provider = $this->yooKassaProviderAccount();
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'provider-atomic');

        try {
            $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('now'), $this->uuid());
            self::fail('Missing fiscal snapshot must reject settlement.');
        } catch (\DomainException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payment_attempts WHERE id = ?', [$attempt['attempt_id']]));
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
        self::assertSame('issued', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoice['id']]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.receipts WHERE payment_id = ?', [$payment['id']]));
    }
}
