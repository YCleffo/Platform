<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Payment\Application\BeginPaymentAttempt;
use ControlPlane\Payment\Application\BindPaymentAttemptProviderReference;
use ControlPlane\Payment\Application\CompletePaymentAttempt;
use ControlPlane\Payment\Application\CreatePayment;
use ControlPlane\Payment\Application\GetPayment;
use ControlPlane\Payment\Application\SettlePayment;

abstract class PaymentTestCase extends InvoiceTestCase
{
    /** @return array{string, string, string, string, string, string, array<string, mixed>} */
    protected function issuedInvoiceContext(string $suffix, int $quantity = 1): array
    {
        [$owner, $account, $profile, $plan, $price, $subscription] = $this->invoiceContext($suffix, $quantity);
        $invoice = $this->issuer()->issue($owner, $account, $subscription, $suffix . '-invoice', $this->uuid());

        return [$owner, $account, $profile, $plan, $price, $subscription, $invoice];
    }

    protected function paymentCreator(): CreatePayment
    {
        return new CreatePayment($this->connection, new AccountAuthorization($this->connection), $this->settlement());
    }

    protected function paymentReader(): GetPayment
    {
        return new GetPayment($this->connection, new AccountAuthorization($this->connection));
    }

    protected function attemptBeginner(): BeginPaymentAttempt
    {
        return new BeginPaymentAttempt($this->connection);
    }

    protected function attemptBinder(): BindPaymentAttemptProviderReference
    {
        return new BindPaymentAttemptProviderReference($this->connection);
    }

    protected function attemptCompleter(): CompletePaymentAttempt
    {
        return new CompletePaymentAttempt($this->connection, $this->settlement());
    }

    protected function providerAccount(string $suffix, string $status = 'active'): string
    {
        $id = $this->connection->fetchOne("INSERT INTO commercial.payment_provider_accounts (provider_code, external_account_id, secret_ref, status) VALUES (?, ?, ?, ?) RETURNING id", ['provider-' . $suffix, 'merchant-' . $suffix, 'payments/' . $suffix, $status]);
        self::assertIsString($id);

        return $id;
    }

    protected function settlement(): SettlePayment
    {
        return new SettlePayment($this->activator());
    }
}
