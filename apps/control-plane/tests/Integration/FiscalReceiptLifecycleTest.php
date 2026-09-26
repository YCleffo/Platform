<?php

declare(strict_types=1);

namespace ControlPlane\Tests\Integration;

use ControlPlane\Account\Authorization\AccountAuthorization;
use ControlPlane\Fiscalization\Application\ApplyAuthoritativeReceipt;
use ControlPlane\Fiscalization\Application\GetReceipt;
use ControlPlane\Fiscalization\Application\ReconcileYooKassaReceipts;
use ControlPlane\Fiscalization\Application\ReceiptSnapshot;
use ControlPlane\Fiscalization\Application\SubmitYooKassaReceipt;
use ControlPlane\Payment\Provider\PaymentProviderException;
use ControlPlane\Payment\Provider\ProviderReceipt;
use ControlPlane\Tests\Support\FakeFiscalReceiptProviderGateway;
use ControlPlane\Tests\Support\FakePaymentProviderGateway;
use ControlPlane\Payment\Provider\ProviderPayment;

final class FiscalReceiptLifecycleTest extends PaymentTestCase
{
    public function testSuccessfulProviderSettlementAtomicallySnapshotsAndFiscalizesReceipt(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedRubInvoiceContext('receipt-life');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'receipt-payment', $this->uuid());
        $provider = $this->yooKassaProviderAccount();
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'provider-payment-one');
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('2026-09-26T10:00:00Z'), $this->uuid());

        $receipt = $this->connection->fetchAssociative('SELECT * FROM commercial.receipts WHERE payment_id = ?', [$payment['id']]);
        self::assertIsArray($receipt);
        self::assertSame('pending', $receipt['status']);
        self::assertSame($invoice['total_minor'], (int) $receipt['amount_minor']);
        self::assertSame('billing-receipt-life@example.test', $receipt['customer_email']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.receipt_items WHERE receipt_id = ?', [$receipt['id']]));

        $gateway = new FakeFiscalReceiptProviderGateway();
        $submission = new SubmitYooKassaReceipt($this->connection, new ReceiptSnapshot(), $gateway, new ApplyAuthoritativeReceipt($this->connection));
        self::assertFalse($submission->submit($receipt['id'], $this->uuid()));
        $bound = $this->connection->fetchOne('SELECT external_receipt_id FROM commercial.receipts WHERE id = ?', [$receipt['id']]);
        self::assertIsString($bound);
        $pending = $gateway->receipts[$bound];
        self::assertInstanceOf(ProviderReceipt::class, $pending);
        $gateway->receipts[$bound] = new ProviderReceipt($pending->id, 'payment', $pending->providerPaymentId, 'succeeded', $pending->amountMinor, $pending->currency, $pending->items, new \DateTimeImmutable('2026-09-26T10:01:00Z'));
        $reconciled = (new ReconcileYooKassaReceipts($this->connection, $submission))->reconcile(100, $this->uuid());
        self::assertSame(['selected' => 1, 'completed' => 1, 'pending' => 0, 'failed' => 0], $reconciled);
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.receipts WHERE id = ?', [$receipt['id']]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'receipt.succeeded' AND aggregate_id = ?", [$receipt['id']]));

        $public = (new GetReceipt($this->connection, new AccountAuthorization($this->connection)))->get($owner, $account, $payment['id']);
        self::assertSame('succeeded', $public['status']);
        self::assertSame('payment', $public['type']);
        self::assertArrayNotHasKey('customer_email', $public);
        self::assertArrayNotHasKey('customer_name', $public);
        self::assertCount(1, $public['items']);
    }

    public function testUncertainSubmissionKeepsPaidStateAndRetriesStableSnapshot(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedRubInvoiceContext('receipt-retry');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'receipt-retry-payment', $this->uuid());
        $provider = $this->yooKassaProviderAccount();
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'provider-payment-retry');
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('now'), $this->uuid());
        $receipt = $this->connection->fetchAssociative('SELECT id, provider_request_key FROM commercial.receipts WHERE payment_id = ?', [$payment['id']]);
        self::assertIsArray($receipt);
        $gateway = new FakeFiscalReceiptProviderGateway();
        $gateway->createFailure = PaymentProviderException::uncertain('receipt_timeout');
        $submission = $this->receiptSubmission($gateway);
        try {
            $submission->submit($receipt['id'], $this->uuid());
            self::fail('Uncertain fiscal submission was expected.');
        } catch (PaymentProviderException) {
            self::addToAssertionCount(1);
        }
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM commercial.invoices WHERE id = ?', [$invoice['id']]));
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.receipts WHERE id = ?', [$receipt['id']]));
        self::assertFalse($submission->submit($receipt['id'], $this->uuid()));
        self::assertCount(2, $gateway->createCalls);
        self::assertSame($receipt['provider_request_key'], $gateway->createCalls[0][1]->providerRequestKey);
        self::assertEquals($gateway->createCalls[0][1], $gateway->createCalls[1][1]);
    }

    public function testAuthoritativeCanceledReceiptIsTerminalAndEmitsOnce(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedRubInvoiceContext('receipt-canceled');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'receipt-canceled-payment', $this->uuid());
        $provider = $this->yooKassaProviderAccount();
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'provider-payment-canceled');
        $this->attemptCompleter()->complete($attempt['attempt_id'], 'succeeded', null, new \DateTimeImmutable('now'), $this->uuid());
        $receiptId = $this->connection->fetchOne('SELECT id FROM commercial.receipts WHERE payment_id = ?', [$payment['id']]);
        self::assertIsString($receiptId);
        $gateway = new FakeFiscalReceiptProviderGateway();
        $submission = $this->receiptSubmission($gateway);
        self::assertFalse($submission->submit($receiptId, $this->uuid()));
        $external = $this->connection->fetchOne('SELECT external_receipt_id FROM commercial.receipts WHERE id = ?', [$receiptId]);
        self::assertIsString($external);
        $pending = $gateway->receipts[$external];
        self::assertInstanceOf(ProviderReceipt::class, $pending);
        $gateway->receipts[$external] = new ProviderReceipt($pending->id, 'payment', $pending->providerPaymentId, 'canceled', $pending->amountMinor, $pending->currency, $pending->items, null);

        self::assertTrue($submission->submit($receiptId, $this->uuid()));
        self::assertFalse($submission->submit($receiptId, $this->uuid()));
        self::assertSame('canceled', $this->connection->fetchOne('SELECT status FROM commercial.receipts WHERE id = ?', [$receiptId]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT count(*) FROM commercial.outbox_events WHERE event_type = 'receipt.canceled' AND aggregate_id = ?", [$receiptId]));
    }

    public function testZeroPaymentNeverCreatesReceipt(): void
    {
        [$owner, $account, $profile] = $this->readyAccount('receipt-zero');
        [$plan, $price] = $this->planPrice('receipt-zero-plan', 'month', 'RUB', 'draft');
        $this->connection->executeStatement('UPDATE commercial.plan_prices SET amount_minor = 0 WHERE id = ?', [$price]);
        $this->connection->executeStatement("UPDATE commercial.plans SET status = 'published', published_at = now() WHERE id = ?", [$plan]);
        $subscription = $this->draftSubscription($account, $profile, [$price]);
        $invoice = $this->issuer()->issue($owner, $account, $subscription, 'receipt-zero-invoice', $this->uuid());
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'receipt-zero-payment', $this->uuid());
        self::assertSame('succeeded', $payment['status']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM commercial.receipts WHERE payment_id = ?', [$payment['id']]));
    }

    public function testWebhookIsProcessedWhenPostCommitReceiptSubmissionIsUncertain(): void
    {
        [$owner, $account, , , , , $invoice] = $this->issuedRubInvoiceContext('receipt-webhook');
        $payment = $this->paymentCreator()->create($owner, $account, $invoice['id'], 'receipt-webhook-payment', $this->uuid());
        $provider = $this->yooKassaProviderAccount();
        $attempt = $this->attemptBeginner()->begin($payment['id'], $provider, 'sbp', $this->uuid());
        $this->attemptBinder()->bind($attempt['attempt_id'], 'provider-webhook-payment');
        $payments = new FakePaymentProviderGateway();
        $payments->payments['provider-webhook-payment'] = new ProviderPayment(
            'provider-webhook-payment', 'succeeded', true, (int) $invoice['total_minor'], 'RUB',
            ['payment_id' => $payment['id'], 'attempt_id' => $attempt['attempt_id'], 'invoice_id' => $invoice['id']],
            null, new \DateTimeImmutable('2026-09-26T10:00:00Z'),
        );
        $receipts = new FakeFiscalReceiptProviderGateway();
        $receipts->createFailure = PaymentProviderException::uncertain('receipt_timeout');
        $body = json_encode(['type' => 'notification', 'event' => 'payment.succeeded', 'object' => ['id' => 'provider-webhook-payment']], JSON_THROW_ON_ERROR);

        $result = $this->yooKassaWebhook($payments, $receipts)->receive($body, 'application/json', $this->uuid());

        self::assertSame('processed', $result['status']);
        self::assertSame('succeeded', $this->connection->fetchOne('SELECT status FROM commercial.payments WHERE id = ?', [$payment['id']]));
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM commercial.receipts WHERE payment_id = ?', [$payment['id']]));
        self::assertSame('receipt_timeout', $this->connection->fetchOne('SELECT last_error_code FROM commercial.receipts WHERE payment_id = ?', [$payment['id']]));
        self::assertSame('processed', $this->connection->fetchOne('SELECT status FROM commercial.payment_provider_events WHERE provider_object_id = ?', ['provider-webhook-payment']));
    }
}
