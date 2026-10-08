<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Billing\Application\RenewalCollectionLifecycle;
use ControlPlane\Billing\Application\RenewalDunningPolicy;
use ControlPlane\Fiscalization\Application\ApplyAuthoritativeReceipt;
use ControlPlane\Fiscalization\Application\CreatePaymentReceiptObligation;
use ControlPlane\Fiscalization\Application\ReceiptSnapshot;
use ControlPlane\Fiscalization\Application\SubmitYooKassaReceipt;
use ControlPlane\Payment\Application\BeginPaymentAttempt;
use ControlPlane\Payment\Application\BindPaymentAttemptProviderReference;
use ControlPlane\Payment\Application\CompletePaymentAttempt;
use ControlPlane\Payment\Application\CreatePayment;
use ControlPlane\Payment\Application\GetPayment;
use ControlPlane\Payment\Application\ProcessAuthoritativeProviderPayment;
use ControlPlane\Payment\Application\RecordSavedPaymentMethod;
use ControlPlane\Payment\Application\ReceiveYooKassaWebhook;
use ControlPlane\Payment\Application\ReconcileYooKassaPayments;
use ControlPlane\Payment\Application\SettlePayment;
use ControlPlane\Payment\Application\StartYooKassaPayment;
use ControlPlane\Payment\Provider\PaymentProviderGateway;
use ControlPlane\Payment\Provider\FiscalReceiptProviderGateway;
use ControlPlane\Payment\Provider\YooKassa\YooKassaProviderAccountLocator;
use ControlPlane\Subscription\Application\AdvanceSubscriptionPeriod;
use ControlPlane\Tests\Support\FakeFiscalReceiptProviderGateway;

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
        return new CompletePaymentAttempt($this->connection, $this->settlement(), new CreatePaymentReceiptObligation());
    }

    protected function providerAccount(string $suffix, string $status = 'active'): string
    {
        $id = $this->connection->fetchOne("INSERT INTO commercial.payment_provider_accounts (provider_code, external_account_id, secret_ref, status) VALUES (?, ?, ?, ?) RETURNING id", ['provider-' . $suffix, 'merchant-' . $suffix, 'payments/' . $suffix, $status]);
        self::assertIsString($id);

        return $id;
    }

    protected function yooKassaProviderAccount(string $suffix = 'default', string $status = 'active'): string
    {
        $id = $this->connection->fetchOne("INSERT INTO commercial.payment_provider_accounts (provider_code, external_account_id, secret_ref, receipt_mode, receipt_vat_code, receipt_payment_subject, receipt_payment_mode, status) VALUES ('yookassa', ?, 'env:YOOKASSA_SECRET_KEY', 'yookassa', 1, 'service', 'full_payment', ?) RETURNING id", ['shop-' . $suffix, $status]);
        self::assertIsString($id);

        return $id;
    }

    /** @return array{string, string, string, string, string, string, array<string, mixed>} */
    protected function issuedRubInvoiceContext(string $suffix): array
    {
        [$owner, $account, $profile, $plan, $price, $subscription] = $this->invoiceContext($suffix, 1, 'month', 'RUB');
        $this->connection->executeStatement('UPDATE commercial.billing_profiles SET contact_name = ?, contact_email = ? WHERE id = ?', ['Billing Contact', 'billing-' . $suffix . '@example.test', $profile]);
        $invoice = $this->issuer()->issue($owner, $account, $subscription, $suffix . '-invoice', $this->uuid());

        return [$owner, $account, $profile, $plan, $price, $subscription, $invoice];
    }

    protected function authoritativeProcessor(?FiscalReceiptProviderGateway $gateway = null): ProcessAuthoritativeProviderPayment
    {
        return new ProcessAuthoritativeProviderPayment($this->connection, $this->attemptBinder(), $this->attemptCompleter(), $this->receiptSubmission($gateway), new RecordSavedPaymentMethod($this->connection), $this->renewalLifecycle());
    }

    protected function receiptSubmission(?FiscalReceiptProviderGateway $gateway = null): SubmitYooKassaReceipt
    {
        return new SubmitYooKassaReceipt($this->connection, new ReceiptSnapshot(), $gateway ?? new FakeFiscalReceiptProviderGateway(), new ApplyAuthoritativeReceipt($this->connection));
    }

    protected function yooKassaCheckout(PaymentProviderGateway $gateway): StartYooKassaPayment
    {
        return new StartYooKassaPayment(
            $this->connection,
            new AccountAuthorization($this->connection),
            new YooKassaProviderAccountLocator($this->connection),
            $this->attemptBeginner(),
            $this->attemptBinder(),
            $this->attemptCompleter(),
            $this->authoritativeProcessor(),
            $gateway,
        );
    }

    protected function yooKassaWebhook(PaymentProviderGateway $gateway, ?FiscalReceiptProviderGateway $receiptGateway = null): ReceiveYooKassaWebhook
    {
        return new ReceiveYooKassaWebhook(
            $this->connection,
            new YooKassaProviderAccountLocator($this->connection),
            $gateway,
            $this->authoritativeProcessor($receiptGateway),
        );
    }

    protected function yooKassaReconciliation(PaymentProviderGateway $gateway): ReconcileYooKassaPayments
    {
        return new ReconcileYooKassaPayments($this->connection, $gateway, $this->authoritativeProcessor());
    }

    protected function settlement(): SettlePayment
    {
        return new SettlePayment($this->activator(), new AdvanceSubscriptionPeriod($this->connection), $this->renewalLifecycle());
    }

    protected function dunningPolicy(): RenewalDunningPolicy
    {
        return new RenewalDunningPolicy(3, '60,300', 3600, 30);
    }

    protected function renewalLifecycle(): RenewalCollectionLifecycle
    {
        return new RenewalCollectionLifecycle($this->connection, $this->dunningPolicy());
    }
}
